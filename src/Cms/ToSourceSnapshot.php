<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\EntryIdentity;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;

/** Actual source storage/route evidence. Does not prepare a new source edition. */
class ToSourceSnapshot
{
    public function __construct(private readonly Configuration $configuration) {}

    public static function digest(mixed $value): string
    {
        $ordered = function ($item) use (&$ordered) {
            if (is_array($item)) {
                if (! array_is_list($item)) {
                    ksort($item, SORT_STRING);
                }
                return array_map($ordered, $item);
            }
            return $item;
        };
        return hash('sha256', json_encode($ordered($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function read(object $row, ?string $path = null, bool $render = true): array
    {
        $repository = Entry::getFacadeRoot();
        if ($repository instanceof DatabaseEntryRepository) {
            $entry = $repository->query()->find($row->entry_id);
            if (! $entry) {
                throw new CmsException('native_ownership_conflict');
            }
            $model = $entry->model();
            $storage = ['kind' => 'eloquent', 'connection' => $model->getConnectionName(), 'table' => $model->getTable(),
                'key' => (string) $model->getKey(), 'raw' => $model->getRawOriginal()];
        } elseif ($repository instanceof FlatEntryRepository) {
            $locator = $repository->find($row->entry_id);
            $path ??= $locator?->path();
            if (! is_string($path) || is_link($path) || ! is_file($path)) {
                throw new CmsException('native_ownership_conflict');
            }
            $bytes = file_get_contents($path);
            $data = YAML::file($path)->parse($bytes);
            if (($data['id'] ?? null) !== $row->entry_id) {
                throw new CmsException('native_ownership_conflict');
            }
            $entry = Stache::store('entries')->store($row->collection_handle)->makeItemFromFile($path, $bytes)->syncOriginal();
            $storage = ['kind' => 'file', 'path' => $path, 'bytes_base64' => base64_encode($bytes)];
        } else {
            throw new CmsException('native_source_binding_unsupported');
        }
        if ((string) $entry->id() !== $row->entry_id || $entry->collectionHandle() !== $row->collection_handle
            || $entry->locale() !== $row->site_handle
            || EntryIdentity::externalId($this->configuration->siteOrigin(), $entry->locale(), $entry->collectionHandle(), (string) $entry->id()) !== $row->external_id) {
            throw new CmsException('native_source_identity_changed');
        }
        $route = $this->route($entry);
        $snapshot = ['entry_id' => (string) $entry->id(), 'site_handle' => $entry->locale(),
            'collection_handle' => $entry->collectionHandle(), 'external_id' => $row->external_id,
            'canonical_url' => $this->canonical($entry),
            'storage' => $storage, 'storage_sha256' => self::digest($storage), 'route' => $route,
            'route_sha256' => self::digest($route), 'render_inputs' => $render ? $this->renderInputs($entry) : [],
            'dirty' => $entry->getCurrentDirtyStateAttributes()];
        $snapshot['render_inputs_sha256'] = self::digest($snapshot['render_inputs']);
        return ['entry' => $entry, 'snapshot' => $snapshot];
    }

    public function route($entry): array
    {
        return ['site_url' => $entry->site()->url(), 'routes' => $entry->collection()->routes()->all(),
            'slug' => $entry->slug(), 'date' => $entry->date()?->format('Y-m-d-Hi'),
            'mount' => $entry->collection()->mount()?->id(), 'origin' => $entry->hasOrigin() ? $entry->origin()->id() : null,
            'orderable' => $entry->collection()->orderable(), 'propagate' => $entry->collection()->propagate()];
    }

    public function canonical($entry): string
    {
        \Statamic\Facades\Blink::store('entry-uris')->forget((string) $entry->id());
        return $this->configuration->canonicalPageUrl($entry->absoluteUrl());
    }

    /** Native serializer projection; includes fields that actually persist. */
    public function persistedPayload($entry): array
    {
        if ($entry instanceof \Statamic\Eloquent\Entries\Entry) {
            $attributes = $entry->toModel()->getAttributes();
            // Eloquent owns this actual persistence clock. It is checked and
            // retained separately as the actual source time after commit.
            unset($attributes['updated_at']);
            return ['kind' => 'eloquent', 'attributes' => $attributes];
        }
        return ['kind' => 'file', 'bytes_base64' => base64_encode($entry->fileContents())];
    }

    public function renderInputs($entry): array
    {
        $views = [];
        foreach ([$entry->template(), $entry->layout()] as $name) {
            if ($name === null || $name === '') {
                continue;
            }
            $view = view()->getFinder()->find($name);
            if (is_link($view) || ! is_file($view) || ! str_ends_with($view, '.antlers.html')) {
                throw new CmsException('native_source_template_unsupported');
            }
            $views[$name] = ['path' => $view, 'sha256' => hash_file('sha256', $view)];
        }
        return ['views' => $views, 'blueprint' => $entry->blueprint()->contents(),
            'template' => $entry->template(), 'layout' => $entry->layout()];
    }

    public function assertOriginal($entry, $current): void
    {
        if (method_exists($entry, 'discussionBridgeSourceOriginal') && method_exists($current, 'discussionBridgeSourceOriginal')) {
            $expected = $entry->discussionBridgeSourceOriginal();
            if ($expected === null || $expected !== $current->discussionBridgeSourceOriginal()) {
                throw new CmsException('native_ownership_conflict');
            }
            return;
        }
        if ($entry->getOriginal() === []
            || NativeSnapshot::digest($entry->getOriginal()) !== NativeSnapshot::digest($current->getCurrentDirtyStateAttributes())) {
            throw new CmsException('native_ownership_conflict');
        }
    }
}
