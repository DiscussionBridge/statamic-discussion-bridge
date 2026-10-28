<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Statamic\Facades\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use stdClass;

/** Map only actual native blueprint fields and native repository IDs. */
class NativePolicy
{
    public function __construct(private readonly Configuration $configuration)
    {
    }

    public function profile(): string
    {
        $profile = config('discussionbridge.cms_profile');
        if (! in_array($profile, ['statamic_flat', 'statamic_db', 'statamic_ssg'], true)) {
            throw new CmsException('presentation_unsupported');
        }
        $database = Entry::getFacadeRoot() instanceof \Statamic\Eloquent\Entries\EntryRepository;
        if (($profile === 'statamic_db') !== $database) {
            throw new CmsException('presentation_unsupported');
        }
        return $profile;
    }

    public function authorize(stdClass $connection, stdClass $policy): void
    {
        $profile = $this->profile();
        $kind = $profile === 'statamic_ssg' ? 'static' : 'dynamic';
        if ($connection->connection_id !== $this->configuration->connectionId()
            || $connection->destination_origin !== $this->configuration->siteOrigin()
            || $connection->profile !== $profile || ! in_array('from_discourse', $connection->directions, true)) {
            throw new CmsException('scope_denied');
        }
        if ($policy->policy_revision !== $connection->policy->policy_revision) {
            throw new CmsException('policy_changed');
        }
        if ($policy->publication_kind !== $kind || $connection->native_capabilities->publication_kind !== $kind
            || ! in_array($policy->presentation_mode, $connection->native_capabilities->supported_presentations, true)
            || $connection->native_capabilities->complete_content_supported !== true) {
            throw new CmsException('presentation_unsupported');
        }
    }

    /** Empty selections leave native defaults and existing assignments intact. */
    public function initialDefaults($entry): array
    {
        try {
            // Native create prepares blueprint defaults; native store processes
            // that prepared value tree. No administrator identity is invented.
            return $entry->blueprint()->fields()->addValues($entry->data()->all())
                ->preProcess()->process()->values()
                ->except(['title', 'slug', 'blueprint', 'published', 'date'])->all();
        } catch (\Throwable) {
            throw new CmsException('metadata_unavailable');
        }
    }

    /** Empty selections leave native defaults and existing assignments intact. */
    public function assignments($entry, stdClass $policy): array
    {
        if ($policy->author_ids === [] && $policy->taxonomy_ids === []) {
            return [];
        }
        $fields = $entry->blueprint()?->fields()->all();
        if (! $fields) {
            throw new CmsException('metadata_unavailable');
        }
        $assignments = [];
        if ($policy->author_ids !== []) {
            $userFields = $fields->filter(fn ($field) => $field->type() === 'users');
            if ($userFields->count() !== 1) {
                throw new CmsException('metadata_unavailable');
            }
            $field = $userFields->first();
            if (($field->config()['query_scopes'] ?? []) !== []) {
                throw new CmsException('metadata_unavailable');
            }
            $maximum = $field->config()['max_items'] ?? null;
            if ($maximum !== null && count($policy->author_ids) > $maximum) {
                throw new CmsException('catalog_entry_unavailable');
            }
            foreach ($policy->author_ids as $id) {
                if (! User::find($id)) {
                    throw new CmsException('catalog_entry_unavailable');
                }
            }
            $assignments[$field->handle()] = $field->fieldtype()->process($policy->author_ids);
        }
        if ($policy->taxonomy_ids !== []) {
            $termFields = $fields->filter(fn ($field) => $field->type() === 'terms');
            foreach ($policy->taxonomy_ids as $id) {
                $term = Term::find($id);
                if (! $term) {
                    throw new CmsException('catalog_entry_unavailable');
                }
                $taxonomy = $term->taxonomy()->handle();
                $candidates = $termFields->filter(fn ($field) => in_array($taxonomy, $field->config()['taxonomies'] ?? [], true));
                if ($candidates->count() !== 1 || ! $entry->collection()->taxonomies()->contains(fn ($item) => $item->handle() === $taxonomy)) {
                    throw new CmsException('metadata_unavailable');
                }
                $field = $candidates->first();
                if (($field->config()['query_scopes'] ?? []) !== []) {
                    throw new CmsException('metadata_unavailable');
                }
                $assignments[$field->handle()][] = $term->id();
                $maximum = $field->config()['max_items'] ?? null;
                if ($maximum !== null && count($assignments[$field->handle()]) > $maximum) {
                    throw new CmsException('catalog_entry_unavailable');
                }
            }
            foreach ($termFields as $field) {
                if (isset($assignments[$field->handle()])) {
                    // Native Terms handles one-taxonomy slugs vs multi-taxonomy IDs.
                    $assignments[$field->handle()] = $field->fieldtype()->process($assignments[$field->handle()]);
                }
            }
        }
        return $assignments;
    }

    /** Actual selected native field/repository census; descriptive, never policy authority. */
    public function catalog(string $kind): array
    {
        $collection = Collection::find('discussionbridge');
        if (! $collection || ! in_array($kind, ['authors', 'taxonomy'], true)) {
            throw new CmsException('metadata_unavailable');
        }
        $entry = Entry::make()->collection($collection)->locale(Site::default()->handle());
        $fields = $entry->blueprint()->fields()->all();
        $bindings = [];
        $items = [];
        if ($kind === 'authors') {
            $native = $fields->filter(fn ($field) => $field->type() === 'users');
            if ($native->count() !== 1 || ($native->first()->config()['query_scopes'] ?? []) !== []) {
                throw new CmsException('metadata_unavailable');
            }
            $bindings[$native->first()->handle()] = $native->first()->config();
            foreach (User::all() as $user) {
                $id = $user->id();
                // Do not coerce an integer repository key into a newly invented
                // public ID grammar. This boundary supports native opaque strings.
                if (! is_string($id) || isset($items[$id])) {
                    throw new CmsException('metadata_unavailable');
                }
                $label = $user->name();
                $items[$id] = (object) ['id' => $id, 'label' => $label === '' ? $id : $label, 'available' => true];
            }
            $repository = get_class(User::getFacadeRoot());
        } else {
            $native = $fields->filter(fn ($field) => $field->type() === 'terms');
            $handles = $collection->taxonomies()->map->handle()->all();
            foreach ($handles as $handle) {
                $candidates = $native->filter(fn ($field) => in_array($handle, $field->config()['taxonomies'] ?? [], true));
                if ($candidates->count() !== 1 || ($candidates->first()->config()['query_scopes'] ?? []) !== []) {
                    throw new CmsException('metadata_unavailable');
                }
                $bindings[$candidates->first()->handle()] = $candidates->first()->config();
            }
            foreach ($handles === [] ? [] : Term::whereInTaxonomy($handles) as $term) {
                $id = $term->id();
                if (! is_string($id) || isset($items[$id])) {
                    throw new CmsException('metadata_unavailable');
                }
                $items[$id] = (object) ['id' => $id, 'label' => $term->title(), 'available' => true];
            }
            $repository = get_class(Term::getFacadeRoot());
        }
        ksort($items, SORT_STRING);
        ksort($bindings, SORT_STRING);
        return ['kind' => $kind, 'items' => array_values($items), 'native_revision' => 'sha256:'.hash('sha256',
            json_encode(['profile' => $this->profile(), 'collection' => $collection->handle(),
                'blueprint' => $entry->blueprint()->handle(), 'repository' => $repository,
                'bindings' => $bindings, 'items' => array_values($items)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
    }
}
