<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

class DatabaseEntryRepository extends \Statamic\Eloquent\Entries\EntryRepository implements FencedRepository
{
    public function save($entry)
    {
        return app(ToClosures::class)->run($entry, 'save', fn () => $this->fencedSave($entry));
    }

    public function delete($entry)
    {
        return app(ToClosures::class)->run($entry, 'delete', fn () => parent::delete($entry));
    }

    private function fencedSave($entry)
    {
        $source = app(ToClosures::class)->sourceRow($entry);
        if ($source !== null) {
            $modelClass = app('statamic.eloquent.entries.model');
            return (new $modelClass)->getConnection()->transaction(function () use ($modelClass, $entry) {
                $model = $modelClass::query()->whereKey($entry->id())->lockForUpdate()->first();
                if ($model === null) {
                    throw new CmsException('native_ownership_conflict');
                }
                $entryClass = get_class($entry);
                app(ToSourceSnapshot::class)->assertOriginal($entry, $entryClass::fromModel($model));
                \Statamic\Facades\Blink::store('entry-uris')->forget((string) $entry->id());
                parent::save($entry);
                $entry->syncOriginal();
            });
        }
        $guard = app(NativeWriteGuard::class);
        if (! $guard->requiresFence($entry)) {
            return parent::save($entry);
        }
        return $guard->commit($entry, function () use ($entry, $guard) {
            $modelClass = app('statamic.eloquent.entries.model');
            $connection = (new $modelClass)->getConnection();
            return $connection->transaction(function () use ($modelClass, $entry, $guard) {
                $model = $modelClass::query()->whereKey($entry->id())->lockForUpdate()->first();
                $current = $model ? \Statamic\Eloquent\Entries\Entry::fromModel($model) : null;
                $guard->compareOriginal($entry, $current);
                $guard->stage($entry);
                parent::save($entry);
                $entry->syncOriginal();
            });
        });
    }
}
