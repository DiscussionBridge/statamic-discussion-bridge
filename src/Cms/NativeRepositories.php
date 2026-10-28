<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Statamic\Contracts\Entries\EntryRepository;
use Statamic\Facades\Entry;
use Statamic\Statamic;

class NativeRepositories
{
    public static function install(): void
    {
        $repository = Entry::getFacadeRoot();
        if ($repository instanceof FencedRepository) {
            return;
        }
        $class = match (get_class($repository)) {
            \Statamic\Stache\Repositories\EntryRepository::class => FlatEntryRepository::class,
            \Statamic\Eloquent\Entries\EntryRepository::class => DatabaseEntryRepository::class,
            default => null,
        };
        // Never replace a custom/unknown repository or unrelated native service.
        if ($class === null) {
            return;
        }
        $entryContract = \Statamic\Contracts\Entries\Entry::class;
        $priorEntryBinding = null;
        if ($class === FlatEntryRepository::class && get_class(app($entryContract)) !== \Statamic\Entries\Entry::class) {
            // Preserve an explicitly registered custom Entry factory verbatim.
            // Its outer cancellation/completion path is not claimed supported.
            $priorEntryBinding = app()->getBindings()[$entryContract] ?? null;
        }
        if ($class === DatabaseEntryRepository::class
            && app('statamic.eloquent.entries.entry') === \Statamic\Eloquent\Entries\Entry::class) {
            app()->instance('statamic.eloquent.entries.entry', DatabaseOperationEntry::class);
        }
        Statamic::repository(EntryRepository::class, $class);
        if ($priorEntryBinding !== null) {
            app()->bind($entryContract, $priorEntryBinding['concrete'], $priorEntryBinding['shared']);
        }
        Entry::clearResolvedInstance(EntryRepository::class);
    }
}
