<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Closure;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/** Supported native operation completion plus exact durable closure capture. */
class ToClosures
{
    private ?string $active = null;

    public function __construct(private readonly PrivateState $store, private readonly CmsClient $client,
        private readonly ToSourcePreparation $source, private readonly Configuration $configuration) {}

    public function run($entry, string $operation, Closure $native): mixed
    {
        $row = $this->row($entry);
        if ($row === null || $this->active === $row->external_id) {
            return $native(); // Entry -> repository joins the SAME outer witness.
        }
        if ($this->active !== null) {
            throw new CmsException('native_source_nested_operation_unsupported');
        }
        return $this->store->locked(function () use ($entry, $operation, $native, $row) {
            $state = $this->store->read();
            app(ToSourceMoves::class)->recoverOne($state, $row);
            $slot = $state['to'][$row->external_id] ?? [];
            if (! isset($slot['accepted']) && ! isset($slot['pending'])) {
                $this->active = $row->external_id;
                try {
                    return $native();
                } finally {
                    $this->active = null;
                }
            }
            if ($entry instanceof \Statamic\Eloquent\Entries\Entry
                && $entry->model()->getConnection()->transactionLevel() !== 0) {
                throw new CmsException('native_source_outer_transaction_unsupported');
            }
            if (isset($slot['closure'])) {
                if ($slot['closure']['stage'] === 'prepared') {
                    throw new CmsException('native_source_closure_requires_reconciliation');
                }
                // The discussion binding stays closed; ordinary native authoring
                // remains native authoring and cannot reactivate this To queue.
                $this->active = $row->external_id;
                try {
                    return $native();
                } finally {
                    $this->active = null;
                }
            }
            $reason = $this->reason($entry, $operation);
            $current = app(ToSourceSnapshot::class)->read($row, $entry->getOriginal('path'), false);
            app(ToSourceSnapshot::class)->assertOriginal($entry, $current['entry']);
            $this->active = $row->external_id;
            $thrown = null;
            $result = null;
            $moving = false;
            $invoked = false;
            try {
                try {
                    if ($reason !== null) {
                        $this->prepare($state, $row, $reason);
                    } elseif ($operation === 'save') {
                        $moving = app(ToSourceMoves::class)->before($state, $row, $entry);
                    }
                    $invoked = true;
                    return $result = $native();
                } catch (Throwable $error) {
                    $thrown = $error;
                    if (! $invoked && ! isset($slot['source_move'])) {
                        app(ToSourceMoves::class)->notInvoked($row);
                    }
                    throw $error;
                } finally {
                    if ($reason !== null) {
                        $this->complete($row, $result === false ? 'cancelled' : ($thrown ? 'threw' : 'returned'));
                    } elseif ($moving) {
                        app(ToSourceMoves::class)->complete($row, $result === false ? 'cancelled' : ($thrown ? 'threw' : 'returned'));
                    }
                }
            } finally {
                $this->active = null;
            }
        });
    }

    /** Custom/cached objects lack the outer method witness: reject before closing. */
    public function blocksEnqueue($entry): bool
    {
        $row = $this->row($entry);
        if ($row === null) {
            return false;
        }
        if ($this->active === $row->external_id) {
            $slot = $this->store->read()['to'][$row->external_id] ?? [];
            return isset($slot['closure']) || isset($slot['source_move']);
        }
        return $this->store->locked(function () use ($row) {
            $slot = $this->store->read()['to'][$row->external_id] ?? [];
            return isset($slot['closure']) || isset($slot['source_move']);
        });
    }

    /** Repository commit joins the already-held outer source operation. */
    public function sourceRow($entry): ?object
    {
        $row = $this->row($entry);
        if ($row === null || $this->active !== $row->external_id) {
            return null;
        }
        $slot = $this->store->read()['to'][$row->external_id] ?? [];
        return ! isset($slot['closure']) && (isset($slot['accepted']) || isset($slot['pending'])) ? $row : null;
    }

    /** Existing native save/reconcile may schedule a genuinely different source. */
    public function rejectedSourceChanged($entry, string $preparedRevision): bool
    {
        $row = $this->row($entry);
        if ($row === null) {
            return false;
        }
        $changed = function () use ($row, $preparedRevision): bool {
            $state = $this->store->read();
            $slot = $state['to'][$row->external_id] ?? [];
            $pending = $slot['pending'] ?? null;
            if ($pending === null || isset($slot['closure']) || $pending['native_revision'] === $preparedRevision) {
                return false;
            }
            $record = $this->client->codec()->parseJson($pending['record']);
            if ($record->external_id !== $row->external_id || $record->canonical_url !== $row->canonical_url) {
                throw new CmsException('native_restore_required');
            }
            try {
                (new ReplayJournal($this->client, $this->store))->rejectIfRecorded($state, $pending['key'],
                    'V4-02', [], (object) ['bridge_record' => $record]);
            } catch (DefinitiveRejection) {
                return true;
            }
            return false;
        };
        return $this->active === $row->external_id ? $changed() : $this->store->locked($changed);
    }

    public function guardEvent($entry, string $operation): void
    {
        $row = $this->row($entry);
        if ($row === null || $this->active === $row->external_id) {
            return;
        }
        if ($this->reason($entry, $operation) === null
            && app(ToSourceSnapshot::class)->canonical($entry) === $row->canonical_url) {
            return;
        }
        $this->store->locked(function () use ($row) {
            $slot = $this->store->read()['to'][$row->external_id] ?? [];
            if (isset($slot['accepted']) || isset($slot['pending'])) {
                throw new CmsException('native_source_entry_operation_unsupported');
            }
        });
    }

    private function row($entry): ?object
    {
        if ($entry->get('discussionbridge_cms_owned') === true || $entry->id() === null
            || ! Schema::hasTable('discussionbridge_deliveries')) {
            return null;
        }
        $rows = DB::table('discussionbridge_deliveries')->where('entry_id', (string) $entry->id())->limit(2)->get();
        if ($rows->count() > 1) {
            throw new CmsException('native_source_binding_ambiguous');
        }
        return $rows->first();
    }

    private function reason($entry, string $operation): ?string
    {
        return $operation === 'delete' ? 'source_deleted'
            : ($entry->published() !== true || $entry->status() !== 'published' ? 'source_unpublished'
                : ($entry->get('discussionbridge_publish') !== true || $entry->get('discussionbridge_native_publication') === true
                    || ! $this->configuration->collectionAllowed($entry->collectionHandle()) ? 'source_ineligible' : null));
    }

    private function prepare(array &$state, object $row, string $reason): void
    {
        $slot = $state['to'][$row->external_id];
        $accepted = $slot['accepted'] ?? null;
        $before = $this->source->bindingSnapshot($row);
        if ($before === null) {
            throw new CmsException('native_source_closure_capture_unavailable');
        }
        if ($accepted !== null) {
            $record = $this->client->codec()->parseJson($accepted['record']);
            $this->client->codec()->validate('ToRecord', $record);
            $this->client->codec()->validate('Revision', $accepted['source_revision']);
            if ($record->external_id !== $row->external_id || $record->canonical_url !== $row->canonical_url
                || $row->resource_id !== null && $row->resource_id !== $accepted['resource_id']
                || $row->topic_id !== null && $row->topic_id !== $accepted['topic_id']) {
                throw new CmsException('native_restore_required');
            }
        }
        $capture = ['capture_id' => (string) Str::uuid(), 'stage' => 'prepared', 'reason' => $reason,
            'entry_id' => $row->entry_id, 'row_id' => $row->id, 'external_id' => $row->external_id,
            'canonical_url' => $row->canonical_url, 'native_before' => $before,
            'accepted_before' => $accepted, 'pending_source_before' => $slot['pending'] ?? null,
            'queue_before' => array_intersect_key((array) $row, array_flip([
                'status', 'attempts', 'next_attempt_at', 'last_error', 'lock_token', 'locked_at', 'desired_revision']))];
        $state['to'][$row->external_id]['closure'] = $capture;
        $this->store->save($state);
        if ($accepted !== null) {
            $key = 'to-withdraw:'.$capture['capture_id'];
            $body = (new ReplayJournal($this->client, $this->store))->prepare($state, $key, 'V4-05',
                ['resource_id' => $accepted['resource_id']], (object) [
                    'expected_source_revision' => $accepted['source_revision'], 'reason' => $reason]);
            $state['to'][$row->external_id]['closure']['mutation_key'] = $key;
            $state['to'][$row->external_id]['closure']['input'] = $this->client->codec()->encodeJson($body);
            $this->store->save($state);
        }
        DB::table('discussionbridge_deliveries')->where('id', $row->id)->update([
            'status' => 'withdraw_capture', 'next_attempt_at' => null, 'updated_at' => now()]);
    }

    private function complete(object $row, string $witness): void
    {
        $state = $this->store->read();
        if (! isset($state['to'][$row->external_id]['closure'])) {
            return;
        }
        $state['to'][$row->external_id]['closure']['operation_completed'] = $witness;
        $this->store->save($state);
        $this->reconcile($state, $row);
    }

    private function reconcile(array &$state, object $row): void
    {
        $closure = $state['to'][$row->external_id]['closure'];
        $actual = $this->source->bindingSnapshot($row, $closure['native_before']);
        $closed = match ($closure['reason']) {
            'source_deleted' => $actual === null,
            'source_unpublished' => $actual !== null && ($actual['published'] !== true || $actual['status'] !== 'published'),
            'source_ineligible' => $actual !== null && (! $actual['opted_in'] || $actual['imported'] || ! $actual['collection_allowed']),
            default => false,
        };
        if ($closed) {
            $state['to'][$row->external_id]['closure']['stage'] = $closure['accepted_before'] === null ? 'unestablished' : 'confirmed';
            $state['to'][$row->external_id]['closure']['native_after'] = $actual ?? ['absent' => true];
            $this->store->save($state);
            DB::table('discussionbridge_deliveries')->where('id', $row->id)->update([
                'status' => $closure['accepted_before'] === null ? 'reconciliation_required' : 'withdraw_pending',
                'last_error' => $closure['accepted_before'] === null ? 'native_source_acceptance_unestablished' : null,
                'next_attempt_at' => $closure['accepted_before'] === null ? null : now(), 'updated_at' => now()]);
        } elseif (isset($closure['operation_completed']) && $actual === $closure['native_before']) {
            // A finished cancellation/failure left exact persisted state unchanged.
            // Preserve abandoned mutation/history and exact prior retry state.
            $closure['stage'] = 'abandoned_unchanged';
            $state['to'][$row->external_id]['closure_history'][$closure['capture_id']] = $closure;
            unset($state['to'][$row->external_id]['closure']);
            $this->store->save($state);
            DB::table('discussionbridge_deliveries')->where('id', $row->id)->update([
                ...$closure['queue_before'], 'updated_at' => now()]);
        }
        // Without completion, unchanged storage cannot prove a dead caller's DB
        // operation cannot still commit. Keep that exact interrupted capture.
    }

    /** Under the existing writer; never replay pending02 after source closure. */
    public function recover(array &$state): int
    {
        $holds = 0;
        foreach ($state['to'] ?? [] as $external => $slot) {
            $closure = $slot['closure'] ?? null;
            if ($closure === null) {
                // Recover a crash between durable abandonment and SQL restore.
                $history = $slot['closure_history'] ?? [];
                $prior = $history === [] ? null : end($history);
                if ($prior !== null && $prior['stage'] === 'abandoned_unchanged') {
                    DB::table('discussionbridge_deliveries')->where('id', $prior['row_id'])
                        ->where('external_id', $external)->where('entry_id', $prior['entry_id'])
                        ->where('canonical_url', $prior['canonical_url'])->where('status', 'withdraw_capture')
                        ->update([...$prior['queue_before'], 'updated_at' => now()]);
                }
                continue;
            }
            if (in_array($closure['stage'], ['confirmed', 'unestablished'], true)) {
                // Recover a crash after private confirmation but before SQL queue
                // publication, without re-running the native operation.
                DB::table('discussionbridge_deliveries')->where('id', $closure['row_id'])
                    ->where('external_id', $external)->where('entry_id', $closure['entry_id'])
                    ->where('canonical_url', $closure['canonical_url'])->where('status', 'withdraw_capture')
                    ->update(['status' => $closure['stage'] === 'confirmed' ? 'withdraw_pending' : 'reconciliation_required',
                        'next_attempt_at' => $closure['stage'] === 'confirmed' ? now() : null,
                        'last_error' => $closure['stage'] === 'confirmed' ? null : 'native_source_acceptance_unestablished', 'updated_at' => now()]);
                continue;
            }
            if ($closure['stage'] !== 'prepared') {
                continue;
            }
            $row = DB::table('discussionbridge_deliveries')->where('id', $closure['row_id'])->first();
            if ($row === null || $row->external_id !== $external || $row->entry_id !== $closure['entry_id']
                || $row->canonical_url !== $closure['canonical_url']) {
                throw new CmsException('native_restore_required');
            }
            $this->reconcile($state, $row);
            $holds += isset($state['to'][$external]['closure']) && $state['to'][$external]['closure']['stage'] === 'prepared' ? 1 : 0;
        }
        return $holds;
    }
}
