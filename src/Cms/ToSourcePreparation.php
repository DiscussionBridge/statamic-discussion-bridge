<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\SourcePublication;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\EntryIdentity;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;

/** Read actual published authoring state without using a working-copy substitute. */
class ToSourcePreparation
{
    public function __construct(protected readonly Configuration $configuration)
    {
    }

    public function capture(object $row): array
    {
        ['entry' => $entry, 'file' => $file] = $this->persisted($row);
        if (! $entry || $entry->published() !== true || $entry->status() !== 'published'
            || $entry->get('discussionbridge_publish') !== true
            || $entry->get('discussionbridge_native_publication') === true
            || ! $this->configuration->collectionAllowed($entry->collectionHandle())) {
            throw new CmsException('native_source_not_eligible');
        }
        $site = $entry->site()->handle();
        if ((string) $entry->id() !== $row->entry_id || $entry->collectionHandle() !== $row->collection_handle
            || $site !== $row->site_handle
            || EntryIdentity::externalId($this->configuration->siteOrigin(), $site, $entry->collectionHandle(), (string) $entry->id()) !== $row->external_id) {
            throw new CmsException('native_source_identity_changed');
        }
        $base = app(ToSourceArticleProfile::class)->baseFor($entry);
        $publication = SourcePublication::fromEntry($entry, $this->configuration, $base);
        if ($publication['record']['canonical_url'] !== $row->canonical_url) {
            throw new CmsException('canonical_url_changed');
        }
        if ($file !== null && file_get_contents($file['path']) !== $file['bytes']) {
            throw new CmsException('native_source_changed_during_capture');
        }
        $this->qualify($entry, $publication);
        return $publication;
    }

    private function persisted(object $row, ?string $priorFilePath = null): array
    {
        $repository = Entry::getFacadeRoot();
        $file = null;
        if ($repository instanceof \Statamic\Eloquent\Entries\EntryRepository) {
            if (! in_array(get_class($repository), [\Statamic\Eloquent\Entries\EntryRepository::class, DatabaseEntryRepository::class], true)) {
                throw new CmsException('native_source_binding_unsupported');
            }
            // Repository find and ordinary query transforms reapply previews.
            // Native query find directly constructs the persisted model entry.
            $entry = $repository->query()->find($row->entry_id);
        } elseif (in_array(get_class($repository), [\Statamic\Stache\Repositories\EntryRepository::class, FlatEntryRepository::class], true)) {
            $locator = $repository->find($row->entry_id);
            if (! $locator && $priorFilePath === null) {
                return ['entry' => null, 'file' => null];
            }
            $path = $locator ? $locator->path() : $priorFilePath;
            // Native delete discards the filesystem result, then forgets its
            // Stache path. Verify the EXACT captured path, not cache absence.
            if ($priorFilePath !== null && $path !== $priorFilePath) {
                throw new CmsException('native_source_identity_changed');
            }
            clearstatcache(true, $path);
            if ($priorFilePath !== null && ! file_exists($path) && ! is_link($path)) {
                return ['entry' => null, 'file' => null];
            }
            if (is_link($path) || ! is_file($path)) {
                throw new CmsException('native_source_binding_unsupported');
            }
            $bytes = file_get_contents($path);
            $data = YAML::file($path)->parse($bytes);
            if (($data['id'] ?? null) !== $row->entry_id) {
                throw new CmsException('native_source_identity_changed');
            }
            // Native public factory uses these exact already-read bytes. A
            // missing ID never reaches its ID-generating/write-back branch.
            $entry = Stache::store('entries')->store($row->collection_handle)->makeItemFromFile($path, $bytes)->syncOriginal();
            $file = ['path' => $path, 'bytes' => $bytes];
        } else {
            throw new CmsException('native_source_binding_unsupported');
        }
        return ['entry' => $entry, 'file' => $file];
    }

    /** Binding-only native evidence; no source HTML preparation or privacy claim. */
    public function bindingSnapshot(object $row, ?array $prior = null): ?array
    {
        ['entry' => $entry, 'file' => $file] = $this->persisted($row,
            ($prior['native_snapshot']['kind'] ?? null) === 'file' ? $prior['native_snapshot']['path'] : null);
        if ($entry === null) {
            return null;
        }
        $site = $entry->site()->handle();
        if ((string) $entry->id() !== $row->entry_id || $entry->collectionHandle() !== $row->collection_handle
            || $site !== $row->site_handle
            || EntryIdentity::externalId($this->configuration->siteOrigin(), $site, $entry->collectionHandle(), (string) $entry->id()) !== $row->external_id) {
            throw new CmsException('native_source_identity_changed');
        }
        $canonical = $this->configuration->canonicalPageUrl($entry->absoluteUrl());
        if ($canonical !== $row->canonical_url) {
            throw new CmsException('canonical_url_changed');
        }
        if ($file !== null && file_get_contents($file['path']) !== $file['bytes']) {
            throw new CmsException('native_source_changed_during_capture');
        }
        $native = $file === null ? ['kind' => 'eloquent', 'raw' => $entry->model()->getRawOriginal()]
            : ['kind' => 'file', 'path' => $file['path'], 'bytes_base64' => base64_encode($file['bytes'])];
        return ['entry_id' => (string) $entry->id(), 'site_handle' => $site,
            'collection_handle' => $entry->collectionHandle(), 'external_id' => $row->external_id,
            'canonical_url' => $canonical, 'published' => $entry->published(), 'status' => $entry->status(),
            'opted_in' => $entry->get('discussionbridge_publish') === true,
            'imported' => $entry->get('discussionbridge_native_publication') === true,
            'collection_allowed' => $this->configuration->collectionAllowed($entry->collectionHandle()),
            'native_snapshot' => $native, 'native_snapshot_sha256' => NativeSnapshot::digest($native)];
    }
    protected function qualify($entry, array $publication): void
    {
        app(\CodeWorksLabs\DiscussionBridgeStatamic\Delivery\NativeArticleProof::class)
            ->qualify($publication['full_html']);
    }
}
