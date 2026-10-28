<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use stdClass;

/** Stage each pinned page durably before advancing its next/checkpoint cursor. */
class SourceContinuation
{
    public function __construct(private readonly CmsClient $client, private readonly PrivateState $store)
    {
    }

    public function stage(array &$state, int $maximumPages = 25): int
    {
        if (isset($state['feed']['attention'])) {
            throw new CmsException($state['feed']['attention']);
        }
        if (isset($state['feed_reconciliation'])) {
            if (! $this->reconcileInventory($state, $maximumPages)) {
                return 0;
            }
        }
        $count = 0;
        for ($page = 0; $page < $maximumPages; $page++) {
            $feed = $state['feed'];
            $query = (object) ['stream' => $feed['stream']];
            if ($feed['cursor'] !== null) {
                $query->cursor = $feed['cursor'];
            }
            try {
                $response = $this->client->request('V4-06', [], $query);
            } catch (CmsException $error) {
                if (in_array($error->failureCode, ['cursor_invalid', 'cursor_expired', 'cursor_scope_changed'], true)) {
                    // Preserve the staged/native ledger; absence is never withdrawal.
                    if ($error->failureCode === 'cursor_expired') {
                        $state['feed_reconciliation'] = ['reason' => $error->failureCode, 'cursor' => null, 'items' => [], 'cursors' => []];
                    } else {
                        $state['feed']['attention'] = $error->failureCode;
                    }
                    $this->store->save($state);
                }
                throw $error;
            }
            if ($response->stream !== $feed['stream']) {
                throw new CmsException('identity_conflict');
            }
            foreach ($response->items as $event) {
                if ($response->stream === 'changes') {
                    $last = $state['last_change_sequence'] ?? null;
                    if ($last !== null && (strlen($event->event_sequence) < strlen($last)
                        || strlen($event->event_sequence) === strlen($last) && strcmp($event->event_sequence, $last) <= 0)) {
                        throw new CmsException('identity_conflict');
                    }
                    $state['last_change_sequence'] = $event->event_sequence;
                }
                $encoded = $this->client->codec()->encodeJson($event);
                if (isset($state['events'][$event->event_id]) && $state['events'][$event->event_id] !== $encoded) {
                    throw new CmsException('identity_conflict');
                }
                if (! isset($state['events'][$event->event_id])) {
                    $state['events'][$event->event_id] = $encoded;
                    $count++;
                }
                if ($event->event_type === 'publication') {
                    $source = $event->source;
                    $key = hash('sha256', $source->source_id."\0".$source->source_revision);
                    $wire = $this->client->codec()->encodeJson($source);
                    if (isset($state['sources'][$key]) && $state['sources'][$key] !== $wire) {
                        throw new CmsException('revision_conflict');
                    }
                    $state['sources'][$key] = $wire;
                }
            }
            // Page staging and cursor progress share a single atomic state replacement.
            $state['feed'] = $response->next_cursor !== null
                ? ['stream' => $feed['stream'], 'cursor' => $response->next_cursor]
                : ['stream' => 'changes', 'cursor' => $response->checkpoint_cursor];
            $this->store->save($state);
            if ($response->next_cursor === null) {
                break;
            }
        }
        return $count;
    }

    /** Complete immutable source bytes in private spool; metadata limits are per response. */
    public function content(stdClass $source, ?callable $heartbeat = null): string
    {
        $name = 'content-'.hash('sha256', $source->source_id."\0".$source->source_revision).'.html';
        $path = $this->store->path($name);
        if (is_file($path) && ! is_link($path) && filesize($path) === $source->content_bytes
            && hash_file('sha256', $path) === $source->content_sha256) {
            return $path;
        }
        $temporary = $this->store->path('content-'.bin2hex(random_bytes(16)).'.tmp');
        $stream = fopen($temporary, 'xb');
        if ($stream === false) {
            throw new CmsException('storage_exhausted');
        }
        @chmod($temporary, 0600);
        $hash = hash_init('sha256');
        $offset = 0;
        try {
            do {
                if ($heartbeat !== null) {
                    $heartbeat();
                }
                $chunk = $this->client->request('V4-07', ['source_id' => $source->source_id,
                    'source_revision' => $source->source_revision], (object) ['offset' => $offset]);
                if ($chunk->source_id !== $source->source_id || $chunk->source_revision !== $source->source_revision
                    || $chunk->content_sha256 !== $source->content_sha256 || $chunk->content_bytes !== $source->content_bytes
                    || $chunk->offset !== $offset) {
                    throw new CmsException('content_hash_mismatch');
                }
                $bytes = base64_decode($chunk->content_base64, true);
                if (! is_string($bytes)) {
                    throw new CmsException('content_hash_mismatch');
                }
                hash_update($hash, $bytes);
                if (fwrite($stream, $bytes) !== strlen($bytes)) {
                    throw new CmsException('storage_exhausted');
                }
                $offset += strlen($bytes);
            } while ($chunk->next_offset !== null);
            if ($offset !== $source->content_bytes || hash_final($hash) !== $source->content_sha256
                || ! fflush($stream) || (function_exists('fsync') && ! fsync($stream))) {
                throw new CmsException('content_hash_mismatch');
            }
            fclose($stream);
            $stream = null;
            // Validate complete UTF8 across chunk boundaries, never individual chunks.
            $content = file_get_contents($temporary);
            if (! is_string($content) || ! mb_check_encoding($content, 'UTF-8')) {
                throw new CmsException('content_hash_mismatch');
            }
            if (is_link($path) || ! rename($temporary, $path)) {
                throw new CmsException('storage_exhausted');
            }
            return $path;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function reconcileInventory(array &$state, int $maximumPages): bool
    {
        for ($page = 0; $page < $maximumPages; $page++) {
            $pending = &$state['feed_reconciliation'];
            $query = $pending['cursor'] === null ? (object) [] : (object) ['cursor' => $pending['cursor']];
            $response = $this->client->request('V4-10', [], $query);
            foreach ($response->items as $mapping) {
                if ($mapping->connection_id !== config('discussionbridge.connection_id') || isset($pending['items'][$mapping->mapping_id])) {
                    throw new CmsException('mapping_conflict');
                }
                $pending['items'][$mapping->mapping_id] = $this->client->codec()->encodeJson($mapping);
            }
            if ($response->next_cursor !== null && isset($pending['cursors'][$response->next_cursor])) {
                throw new CmsException('cursor_invalid');
            }
            $pending['cursor'] = $response->next_cursor;
            if ($response->next_cursor !== null) {
                $pending['cursors'][$response->next_cursor] = true;
            }
            $this->store->save($state);
            if ($response->next_cursor !== null) {
                continue;
            }
            foreach ($state['mappings'] as $id => &$binding) {
                if (! isset($pending['items'][$id])) {
                    throw new CmsException('mapping_conflict');
                }
                $old = $this->client->codec()->parseJson($binding['mapping'], 262144);
                $current = $this->client->codec()->parseJson($pending['items'][$id], 262144);
                foreach (['resource_id', 'source_id', 'external_id', 'canonical_url', 'connection_id'] as $field) {
                    if ($old->$field !== $current->$field) {
                        throw new CmsException('mapping_conflict');
                    }
                }
                if ($current->destination_native_id !== null && $current->destination_native_id !== ($binding['native_id'] ?? null)) {
                    throw new CmsException('native_ownership_conflict');
                }
                $binding['mapping'] = $pending['items'][$id];
            }
            unset($binding);
            // Unknown inventory objects remain read-only; never adopt or create
            // from census absence. Fresh initial after expiry is explicit/durable.
            $state['last_feed_reconciliation'] = $pending;
            unset($state['feed_reconciliation']);
            $state['feed'] = ['stream' => 'initial', 'cursor' => null];
            $this->store->save($state);
            return true;
        }
        return false;
    }
}
