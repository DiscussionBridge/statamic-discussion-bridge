<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Publication;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use RuntimeException;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;

class PlatformCatalog
{
    private const MAX_TERMS = 5000;
    private const MAX_AUTHORS = 500;
    private const MAX_BYTES = 245760;

    public function __construct(private readonly Configuration $configuration)
    {
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $site = Site::default()->handle();
        $collections = collect(config('discussionbridge.collections', []))->map(function ($handle) use ($site) {
            $collection = is_string($handle) ? Collection::findByHandle($handle) : null;
            if (! $collection || ! $this->supportedRoute($collection->route($site))) {
                throw new RuntimeException('A configured Statamic publication collection is unavailable or has an unsupported route.');
            }

            return $collection;
        });
        if ($collections->isEmpty() || $collections->count() > 500) {
            throw new RuntimeException('Statamic publication collection inventory is invalid.');
        }

        $taxonomyHandles = $collections->flatMap(
            fn ($collection) => $collection->taxonomies()->map->handle(),
        )->unique()->sort()->values();
        $termCount = 0;
        $taxonomies = $taxonomyHandles->map(function ($handle) use (&$termCount) {
            $taxonomy = Taxonomy::findByHandle($handle);
            if (! $taxonomy) {
                throw new RuntimeException('A configured Statamic taxonomy is unavailable.');
            }
            $terms = Term::whereTaxonomy($handle)->sortBy(fn ($term) => (string) $term->id())->values();
            $termCount += $terms->count();
            if ($termCount > self::MAX_TERMS) {
                throw new RuntimeException('Statamic taxonomy inventory exceeds the supported limit.');
            }

            return [
                'id' => $handle,
                'label' => $this->label($taxonomy->title()),
                'kind' => 'taxonomy',
                'terms' => $terms->map(fn ($term) => [
                    'id' => (string) $term->id(),
                    'label' => $this->label($term->title()),
                    'kind' => 'term',
                ])->all(),
            ];
        })->all();

        $users = User::all()->filter(fn ($user) => $user->isSuper() || $collections->contains(
            fn ($collection) => $user->hasPermission("publish {$collection->handle()} entries"),
        ))->sortBy(fn ($user) => (string) $user->id())->values();
        if ($users->isEmpty() || $users->count() > self::MAX_AUTHORS) {
            throw new RuntimeException('Statamic publish-capable author inventory is invalid.');
        }
        $authors = $users->map(fn ($user) => [
            'id' => 'user:'.(string) $user->id(),
            'label' => $this->label($user->get('name') ?: $user->email()),
            'kind' => 'author',
        ])->all();
        $serviceAuthor = $this->configuration->nativeAuthorId();
        if (! in_array('user:'.$serviceAuthor, array_column($authors, 'id'), true)) {
            throw new RuntimeException('The configured Statamic service author cannot publish to an enabled collection.');
        }

        $catalog = [
            'schema_version' => 1,
            'platform' => 'statamic',
            'containers' => $collections->map(fn ($collection) => [
                'id' => $collection->handle(),
                'label' => $this->label($collection->title()),
                'kind' => 'collection',
                'path' => $this->routePath($collection->route($site)),
                'taxonomy_ids' => $collection->taxonomies()->map->handle()->sort()->values()->all(),
            ])->all(),
            'taxonomies' => $taxonomies,
            'authors' => $authors,
            'service_author_id' => 'user:'.$serviceAuthor,
            'presentation_modes' => ['simple', 'full', 'fullInteractive', 'native'],
            'capabilities' => ['updates' => true, 'unpublish' => true, 'drafts' => true],
            'limits' => ['content_bytes' => 49152, 'title_bytes' => 1000, 'slug_bytes' => 180],
            'inventory' => [
                'authors_complete' => true,
                'terms_complete' => true,
                'authors_observed' => $users->count(),
                'terms_observed' => $termCount,
            ],
        ];
        $encoded = json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new RuntimeException('Statamic platform catalog exceeds the supported limit.');
        }

        return $catalog;
    }

    public function canonicalPath(string $collectionHandle, string $slug): string
    {
        $collection = Collection::findByHandle($collectionHandle);
        $route = $collection?->route(Site::default()->handle());
        if (! $collection || ! $this->configuration->collectionAllowed($collectionHandle) || ! $this->supportedRoute($route)) {
            throw new RuntimeException('DiscussionBridge Statamic destination collection is unsupported.');
        }
        $path = str_replace(['{parent_uri}/', '{parent_uri}'], '', $route);
        $path = str_replace('{slug}', $slug, $path);
        $path = '/'.trim(preg_replace('#/+#', '/', $path), '/');

        return $path === '/' ? '/'.$slug : $path;
    }

    private function supportedRoute(mixed $route): bool
    {
        if (! is_string($route) || strlen($route) > 512 || substr_count($route, '{slug}') !== 1) {
            return false;
        }
        $remaining = str_replace(['{slug}', '{parent_uri}'], '', $route);

        return ! str_contains($remaining, '{') && ! str_contains($remaining, '}');
    }

    private function routePath(string $route): string
    {
        $path = str_replace(['{parent_uri}/', '{parent_uri}', '{slug}'], '', $route);
        $path = '/'.trim(preg_replace('#/+#', '/', $path), '/');

        return $path === '/' ? '/' : $path.'/';
    }

    private function label(mixed $value): string
    {
        $label = is_string($value) ? trim(strip_tags($value)) : '';
        if ($label === '' || strlen($label) > 255 || preg_match('/[\x00-\x1f\x7f]/', $label)) {
            throw new RuntimeException('Statamic platform catalog contains an invalid label.');
        }

        return $label;
    }
}
