<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Closure;

/** A shared lock for the worker and ordinary eventful native entry saves. */
class NativeWriteGuard
{
    private array $locks = [];
    private array $worker = [];
    private \WeakMap $staged;

    public function __construct()
    {
        $this->staged = new \WeakMap;
    }

    public function capture($entry): void
    {
        if (! isset($this->worker[(string) $entry->id()])) {
            throw new CmsException('work_busy');
        }
        $this->staged[$entry] = ['expected' => $entry->get('discussionbridge_cms_native_version'),
            'next' => (string) \Illuminate\Support\Str::uuid()];
    }

    public function stage($entry): void
    {
        $staged = $this->staged[$entry] ?? ['expected' => $entry->get('discussionbridge_cms_native_version'),
            'next' => (string) \Illuminate\Support\Str::uuid()];
        $this->staged[$entry] = $staged;
        $entry->set('discussionbridge_cms_native_version', $staged['next']);
        $entry->set('discussionbridge_cms_native_digest', NativeSnapshot::entryDigest($entry));
    }

    public function assertRepositoryFence(): void
    {
        // Event hooks alone do not fence saveQuietly/direct repository writes.
        // A proven native repository commit mechanism must be selected before
        // this worker is allowed to create or replace a native object.
        if (! \Statamic\Facades\Entry::getFacadeRoot() instanceof FencedRepository) {
            throw new CmsException('native_ownership_conflict');
        }
    }

    public function locked(string $nativeId, Closure $operation): mixed
    {
        if (isset($this->worker[$nativeId])) {
            return $operation();
        }
        $this->acquire($nativeId);
        $this->worker[$nativeId] = true;
        try {
            return $operation();
        } finally {
            unset($this->worker[$nativeId]);
            foreach ($this->staged as $entry => $ignored) {
                if ((string) $entry->id() === $nativeId) {
                    unset($this->staged[$entry]);
                }
            }
            $this->release($nativeId);
        }
    }

    public function saving($entry): void
    {
        if (! $this->requiresFence($entry) || isset($this->worker[(string) $entry->id()]) && isset($this->staged[$entry])) {
            return;
        }
        // Probe early, but don't retain a lock across cancellable EntrySaving
        // listeners. The repository's try/finally covers the actual commit.
        $this->acquire((string) $entry->id());
        $this->release((string) $entry->id());
    }

    public function requiresFence($entry): bool
    {
        return $entry->get('discussionbridge_cms_owned') === true || $entry->getOriginal('discussionbridge_cms_owned') === true
            || ($entry->collectionHandle() === 'discussionbridge' && (new PrivateState)->reservation($entry->absoluteUrl()) !== null);
    }

    public function commit($entry, Closure $operation): mixed
    {
        $id = (string) $entry->id();
        $ownedLock = isset($this->worker[$id]) && isset($this->staged[$entry]);
        if (! $ownedLock) {
            $this->acquire($id);
        }
        try {
            $reservation = (new PrivateState)->reservation($entry->absoluteUrl());
            if ($reservation !== null && ($reservation['native_id'] !== $id
                || $entry->get('discussionbridge_cms_mapping_id') !== $reservation['mapping_id'])) {
                throw new CmsException('url_collision');
            }
            if ($entry->collection()->orderable() || $entry->collection()->propagate() || $entry->hasOrigin()) {
                throw new CmsException('presentation_unsupported');
            }
            return $operation();
        } finally {
            if (! $ownedLock) {
                unset($this->staged[$entry]);
                $this->release($id);
            }
        }
    }

    public function compareOriginal($entry, $current): void
    {
        $expected = isset($this->staged[$entry]) ? $this->staged[$entry]['expected'] : $entry->get('discussionbridge_cms_native_version');
        if ($current === null) {
            if (! isset($this->worker[(string) $entry->id()]) || $expected !== null) {
                throw new CmsException('native_ownership_conflict');
            }
            return;
        }
        if ((string) $current->id() !== (string) $entry->id()
            || $current->get('discussionbridge_cms_owned') !== true || ! is_string($expected)
            || $expected !== $current->get('discussionbridge_cms_native_version')
            || NativeSnapshot::entryDigest($current) !== $current->get('discussionbridge_cms_native_digest')) {
            throw new CmsException('native_ownership_conflict');
        }
    }

    public function saved($entry): void
    {
        $id = (string) $entry->id();
        if (! isset($this->worker[$id])) {
            $this->release($id);
        }
    }

    public function releaseAll(): void
    {
        foreach (array_keys($this->locks) as $id) {
            $this->release($id);
        }
    }

    private function acquire(string $nativeId): void
    {
        if (isset($this->locks[$nativeId])) {
            throw new CmsException('work_busy');
        }
        $store = new PrivateState;
        $path = $store->path('native-'.hash('sha256', $nativeId).'.lock');
        if (is_link($path)) {
            throw new CmsException('native_ownership_conflict');
        }
        $lock = fopen($path, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new CmsException('work_busy');
        }
        @chmod($path, 0600);
        $this->locks[$nativeId] = $lock;
    }

    private function release(string $id): void
    {
        if (isset($this->locks[$id])) {
            flock($this->locks[$id], LOCK_UN);
            fclose($this->locks[$id]);
            unset($this->locks[$id]);
        }
    }
}
