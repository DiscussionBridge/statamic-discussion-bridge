<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Support;

use RuntimeException;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\View\Antlers\Language\Nodes\AntlersNode;
use Statamic\View\Antlers\Language\Parser\DocumentParser;
use Throwable;

class TemplateIntegration
{
    /**
     * Verify selected native wiring without executing custom site PHP or tags.
     * This does not certify a generated/public page or a full storage profile.
     *
     * @return array{checked:int,incomplete:list<string>}
     */
    public function inspect(iterable $records): array
    {
        $selected = [];
        $incomplete = [];
        $handles = array_values(array_unique([...config('discussionbridge.collections', []), 'discussionbridge']));
        $handles = array_values(array_filter($handles, fn ($handle) => Collection::findByHandle($handle) !== null));
        $entries = $handles === [] ? [] : Entry::query()->whereIn('collection', $handles)->orderBy('id')->lazy(32);
        foreach ($entries as $entry) {
            $native = $entry->get('discussionbridge_native_publication') === true;
            if (! $native && $entry->get('discussionbridge_publish') !== true && ! $entry->get('discussionbridge_resource_id')) {
                continue;
            }
            $mode = $entry->get('discussionbridge_mode') ?? 'interactive';
            $this->select($selected, $incomplete, $entry->template(), $native ? 'from_discourse' : 'to_discourse', $mode);
        }

        // A newly selected native destination has no entry yet. Resolve its
        // actual collection template; never replace it with a fragment default.
        foreach ($records as $record) {
            if (! is_array($record) || ($record['direction'] ?? null) !== 'from_discourse') {
                continue;
            }
            foreach (is_array($record['bindings'] ?? null) ? $record['bindings'] : [] as $binding) {
                if (! is_array($binding) || ($binding['role'] ?? null) !== 'presentation'
                    || ($binding['state'] ?? null) !== 'active' || ($binding['native_materialization'] ?? null) !== true) {
                    continue;
                }
                $resource = $record['resource_id'] ?? null;
                $existing = is_string($resource) ? Entry::query()->where('discussionbridge_resource_id', $resource)->first() : null;
                if ($existing) {
                    $this->select($selected, $incomplete, $existing->template(), 'from_discourse', $existing->get('discussionbridge_mode') ?? $binding['presentation_mode'] ?? 'interactive');
                    continue;
                }
                $collection = Collection::findByHandle('discussionbridge');
                if (! $collection) {
                    $site = Site::default();
                    if (! $site || ! Entry::findByUri('/discussionbridge', $site->handle())) {
                        $incomplete[] = 'from_discourse: selected native destination collection or pages parent is unavailable.';
                        continue;
                    }
                    $collection = Collection::findByHandle('pages');
                }
                $this->select($selected, $incomplete, $collection?->template(), 'from_discourse', $binding['presentation_mode'] ?? 'interactive');
            }
        }

        foreach ($selected as [$template, $direction, $mode]) {
            try {
                $this->verify($template, $direction, $mode);
            } catch (Throwable $error) {
                $incomplete[] = $direction.' '.$mode.' template '.$template.': '.substr($error->getMessage(), 0, 180);
            }
        }

        return ['checked' => count($selected), 'incomplete' => array_values(array_unique($incomplete))];
    }

    public function verify(string $template, string $direction, string $mode): void
    {
        if (! in_array($direction, ['to_discourse', 'from_discourse'], true) || ! in_array($mode, ['simple', 'full', 'interactive'], true)
            || ! $this->supports($template, $direction, $mode, [])) {
            throw new RuntimeException('Selected native article/discussion integration is incomplete. Preserve the selected template and integrate the packaged fragment or supported article/discussion calls.');
        }
    }

    private function select(array &$selected, array &$incomplete, mixed $template, string $direction, mixed $mode): void
    {
        if (! is_string($template) || $template === '' || ! is_string($mode) || ! in_array($mode, ['simple', 'full', 'interactive'], true)) {
            $incomplete[] = $direction.': selected native template or presentation choice is unavailable.';
            return;
        }
        $selected[$template.'|'.$direction.'|'.$mode] = [$template, $direction, $mode];
    }

    private function supports(string $template, string $direction, string $mode, array $visited): bool
    {
        if (count($visited) >= 16 || isset($visited[$template])) {
            return false;
        }
        $visited[$template] = true;
        $path = view()->getFinder()->find($template);
        if (! str_ends_with($path, '.antlers.html') || is_link($path) || ! is_file($path) || filesize($path) > 262144) {
            return false;
        }
        $source = file_get_contents($path);
        if (! is_string($source)) {
            return false;
        }
        $normalized = str_replace("\r\n", "\n", $source);
        foreach (array_keys(PresentationAssets::templatePaths()) as $packaged) {
            if ($normalized === str_replace("\r\n", "\n", (string) file_get_contents($packaged))) {
                return true;
            }
        }

        $parser = new DocumentParser;
        $parser->parse($source);
        $article = false;
        $discussion = false;
        foreach ($parser->getRenderNodes() as $node) {
            // Conditions, paired loops, escaped text and comments are not proof
            // of unconditional invocation for this entry. Never scan their text.
            if (get_class($node) !== AntlersNode::class || $node->isComment || $node->isClosingTag || $node->isClosedBy || $node->hasModifiers() || ! $node->name) {
                continue;
            }
            $name = $node->name->name;
            $method = $node->name->methodPart;
            if ($name === 'partial' && $method !== '' && $node->parameters === []) {
                if ($this->supports($this->partialName($method), $direction, $mode, $visited)) {
                    return true;
                }
                continue;
            }
            if ($name !== 'discussionbridge') {
                continue;
            }
            $parameters = [];
            foreach ($node->parameters as $parameter) {
                if (array_key_exists($parameter->name, $parameters)) {
                    $parameters = ['duplicate_parameter' => true];
                    break;
                }
                $value = $parameter->value;
                if ($parameter->isVariableReference && in_array($value, ['id', 'discussionbridge_topic_id', 'discussionbridge_canonical_url'], true)) {
                    $value = '{'.$value.'}';
                } elseif (count($parameter->interpolations) === 1 && $value === $parameter->interpolations[0]) {
                    $interpolated = $node->processedInterpolationRegions[$value] ?? [];
                    if (count($interpolated) === 1 && get_class($interpolated[0]) === AntlersNode::class
                        && ! $interpolated[0]->hasModifiers() && ! $interpolated[0]->isComment
                        && in_array(trim($interpolated[0]->content), ['id', 'discussionbridge_topic_id', 'discussionbridge_canonical_url'], true)) {
                        $value = '{'.trim($interpolated[0]->content).'}';
                    }
                }
                $parameters[$parameter->name] = $value;
            }
            if (in_array($method, ['article', 'publication', 'discussion'], true)
                && ($parameters === [] || $parameters === ['entry' => '{id}'])) {
                $article = $article || $method === 'article';
                $discussion = $discussion || $method === ($direction === 'from_discourse' ? 'publication' : 'discussion');
            }
            if ($method === $mode && ($parameters['topic'] ?? null) === '{discussionbridge_topic_id}'
                && count(array_diff_key($parameters, ['topic' => true, 'canonical' => true])) === 0
                && (! isset($parameters['canonical']) || $parameters['canonical'] === '{discussionbridge_canonical_url}')) {
                $discussion = true;
            }
        }

        return $article && $discussion;
    }

    // Match Statamic's native Partial view resolution order, including operator
    // overrides; a fixed published-fragment filename is not the authority.
    private function partialName(string $partial): string
    {
        $partial = str_replace('/', '.', $partial);
        $parts = explode('.', $partial);
        $last = array_pop($parts);
        $underscored = implode('.', [...$parts, '_'.$last]);
        foreach ([$underscored, 'partials.'.$partial, 'partials.'.$underscored, $partial] as $candidate) {
            if (view()->exists($candidate)) {
                return $candidate;
            }
        }

        return $partial;
    }
}
