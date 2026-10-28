<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

class FlatEntryRepository extends \Statamic\Stache\Repositories\EntryRepository implements FencedRepository
{
    public function save($entry)
    {
        return app(ToClosures::class)->run($entry, 'save', fn () => $this->fencedSave($entry));
    }

    public function delete($entry)
    {
        return app(ToClosures::class)->run($entry, 'delete', fn () => parent::delete($entry));
    }

    public static function bindings(): array
    {
        return [\Statamic\Contracts\Entries\Entry::class => FlatOperationEntry::class, ...array_diff_key(parent::bindings(), [\Statamic\Contracts\Entries\Entry::class => true])];
    }

    private function fencedSave($entry)
    {
        $source = app(ToClosures::class)->sourceRow($entry);
        if ($source !== null) {
            $actual = app(ToSourceSnapshot::class)->read($source, $entry->getOriginal('path'), false);
            app(ToSourceSnapshot::class)->assertOriginal($entry, $actual['entry']);
            \Statamic\Facades\Blink::store('entry-uris')->forget((string) $entry->id());
            parent::save($entry);
            $entry->syncOriginal();
            return;
        }
        $guard = app(NativeWriteGuard::class);
        if (! $guard->requiresFence($entry)) {
            return parent::save($entry);
        }
        return $guard->commit($entry, function () use ($entry, $guard) {
            $path = $entry->getOriginal('path') ?? $entry->path();
            $current = null;
            if (is_link($path)) {
                throw new CmsException('native_ownership_conflict');
            }
            if (is_file($path)) {
                $bytes = file_get_contents($path);
                if (! is_string($bytes)) {
                    throw new CmsException('native_service_unavailable');
                }
                $current = $this->store->store($entry->collectionHandle())->makeItemFromFile($path, $bytes);
            }
            $guard->compareOriginal($entry, $current);
            // Native fileContents persists this selected blueprint even when
            // the new entry's initial data did not carry its handle.
            $entry->set('blueprint', $entry->blueprint()->handle());
            $guard->stage($entry);
            parent::save($entry);
            $entry->syncOriginal();
        });
    }
}
