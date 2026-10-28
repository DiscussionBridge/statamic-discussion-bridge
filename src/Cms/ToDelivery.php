<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\NativePublication;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;
use stdClass;

/** One current-baseline To writer using the native queue and exact durable replay. */
class ToDelivery
{
    public function __construct(private readonly CmsClient $client, private readonly PrivateState $store,
        private readonly Configuration $configuration, private readonly NativePolicy $native,
        private readonly ToSourcePreparation $preparation, private readonly ToPublication $publication)
    {
    }

    public function deliver(object $row): array
    {
        $state = $this->store->read();
        $sourceState = $state['to'][$row->external_id] ?? [];
        if (isset($sourceState['closure'])) {
            return $this->withdraw($state, $row, $sourceState);
        }
        if (isset($sourceState['source_move'])) {
            return app(ToSourceMoves::class)->deliver($state, $row);
        }
        $accepted = $sourceState['accepted'] ?? null;
        $pending = $sourceState['pending'] ?? null;
        $rejected = null;
        $source = null;
        if ($accepted === null && ($row->cms_source_revision !== null || $row->resource_id !== null) && $pending === null) {
            throw new CmsException('native_restore_required');
        }
        if ($accepted !== null) {
            $this->queueBinding($row, $accepted);
            if ($row->cms_source_revision !== null && $row->cms_source_revision !== $accepted['source_revision']) {
                // Private acceptance may precede a crashed SQL readback update.
                $prior = array_filter($sourceState['accepted_history'] ?? [],
                    fn ($receipt) => $receipt['source_revision'] === $row->cms_source_revision
                        && $receipt['resource_id'] === $accepted['resource_id'] && $receipt['topic_id'] === $accepted['topic_id']);
                if ($prior === []) {
                    throw new CmsException('native_restore_required');
                }
            }
        }
        if ($pending !== null && isset($pending['operator_retry'])
            && ! ($sourceState['retry_actions'][$pending['operator_retry']]['dispatch_started'] ?? true)
            && $row->desired_revision !== $pending['native_revision']) {
            $source = $this->preparation->capture($row);
            if ($source['record']['source_publication']['revision'] !== $pending['native_revision']) {
                $mutation = $state['mutations'][$pending['key']] ?? null;
                if (! is_array($mutation) || $mutation['result'] !== null || isset($mutation['rejection'])) throw new CmsException('native_restore_required');
                // This exact private flag precedes every explicit-retry HTTP dispatch.
                // A genuinely new native edition may supersede a PROVEN UNSENT
                // intent; uncertain/dispatched intents retain their exact replay.
                $state['to'][$row->external_id]['undispatched_history'][$pending['key']] = [
                    'pending' => $pending, 'mutation' => $mutation, 'reason' => 'ordinary_changed_source_before_dispatch',
                    'continued_native_revision' => $source['record']['source_publication']['revision']];
                unset($state['to'][$row->external_id]['pending']);
                $this->store->save($state);
                $pending = null;
            }
        }
        if ($pending !== null) {
            $record = $this->pendingRecord($row, $pending);
            try {
                (new ReplayJournal($this->client, $this->store))->rejectIfRecorded($state,
                    $pending['key'], 'V4-02', [], (object) ['bridge_record' => $record]);
            } catch (DefinitiveRejection $error) {
                // A known rejected request is never replayed as a repair probe.
                // Only an actual different current prepared native snapshot can
                // proceed through the ordinary current-authority checks below.
                if ($row->desired_revision === $pending['native_revision']) {
                    throw $error;
                }
                $source = $this->preparation->capture($row);
                if ($source['record']['source_publication']['revision'] === $pending['native_revision']) {
                    throw $error;
                }
                $rejected = $pending;
                $pending = null;
            }
        }
        // An already journaled exact POST is reconciled even if a later native
        // edition changed. It is not a new body to discard or send under new ID.
        $source ??= $pending === null ? $this->preparation->capture($row) : null;
        $connection = $this->connection($state);
        if ($pending === null) {
            $nativeRevision = $source['record']['source_publication']['revision'];
            if ($accepted !== null) {
                $this->detail($row, $accepted);
                if ($accepted['native_revision'] === $nativeRevision) {
                    if ($rejected !== null) {
                        $this->retainRejected($state, $row, $rejected, $nativeRevision, null);
                        unset($state['to'][$row->external_id]['pending']);
                        $this->store->save($state);
                    }
                    return $this->values($accepted);
                }
            } else {
                $this->assertNewSource($row);
            }
            $record = $this->publication->record($source, $row, $connection, $accepted['source_revision'] ?? null, $this->client->codec());
            if (isset($sourceState['route_edition'])) {
                $record->source_revision = 'source:'.hash('sha256', $sourceState['route_edition']."\0".$row->canonical_url."\0".$nativeRevision);
                if ($record->representation->kind === 'generated_excerpt') {
                    $record->representation->selection_source_revision = $record->source_revision;
                }
                $this->client->codec()->validateGeneratedEnvelope($record);
            }
            $pending = ['key' => 'to-resolve:'.Str::uuid(), 'record' => $this->client->codec()->encodeJson($record),
                'native_source' => $source['native_source'],
                'prepared_source' => $source,
                'native_revision' => $nativeRevision, 'forum_name' => $connection->forum_name,
                'prior_resource_id' => $accepted['resource_id'] ?? null, 'prior_topic_id' => $accepted['topic_id'] ?? null];
            if ($rejected !== null) {
                $this->retainRejected($state, $row, $rejected, $nativeRevision, $pending['key']);
            }
            $state['to'][$row->external_id]['pending'] = $pending;
            $this->store->save($state);
        }
        $record = $this->pendingRecord($row, $pending);
        if (isset($pending['operator_retry'])) {
            $fingerprint = $pending['operator_retry'];
            $action = &$state['to'][$row->external_id]['retry_actions'][$fingerprint];
            if (! is_array($action) || $action['intent_key'] !== $pending['key']) throw new CmsException('native_restore_required');
            // This flag is durable before a queued explicit retry can dispatch.
            // Repeated operator submissions cannot reset its already-started budget.
            if (! $action['dispatch_started']) {
                $this->retryAdmission($state, $row, $pending);
                $action['dispatch_started'] = true;
                $this->store->save($state);
            }
            unset($action);
        }
        $result = (new ReplayJournal($this->client, $this->store))->mutate($state, $pending['key'], 'V4-02', [], (object) ['bridge_record' => $record]);
        if ($result->outcome === 'reconciliation_required') {
            $state['to'][$row->external_id]['attention_result'] = $this->client->codec()->encodeJson($result);
            $this->store->save($state);
            throw new CmsException($result->reason, 409);
        }
        if ($result->source_revision !== $record->source_revision
            || $pending['prior_resource_id'] !== null && ($result->resource_id !== $pending['prior_resource_id'] || $result->topic_id !== $pending['prior_topic_id'])
            || $pending['prior_resource_id'] === null && $result->outcome !== 'created') {
            throw new CmsException('returned_identity_mismatch');
        }
        app(NativePublication::class)->sourceTopicUrl($result->topic_url, (int) $result->topic_id);
        $accepted = ['resource_id' => $result->resource_id, 'topic_id' => $result->topic_id, 'topic_url' => $result->topic_url,
            'source_revision' => $result->source_revision, 'native_revision' => $pending['native_revision'],
            'synchronized_at' => $result->synchronized_at, 'outcome' => $result->outcome, 'forum_name' => $pending['forum_name'],
            'record' => $pending['record'], 'result' => $this->client->codec()->encodeJson($result), 'mutation_key' => $pending['key']];
        if (isset($pending['native_source'])) {
            $accepted['native_source'] = $pending['native_source'];
        }
        if (isset($pending['prepared_source'])) {
            $accepted['prepared_source'] = $pending['prepared_source'];
        }
        $state['to'][$row->external_id]['accepted_history'][$pending['key']] = $accepted;
        $state['to'][$row->external_id]['accepted'] = $accepted;
        unset($state['to'][$row->external_id]['pending']);
        $this->store->save($state);
        return $this->values($accepted);
    }

    /** Existing permissioned native utility supplies the actual user and exact displayed refusal. */
    public function retryInvalidField(int $id, string $fingerprint, string $actor): array
    {
        if ($id < 1 || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1 || $actor === '' || strlen($actor) > 256) {
            throw new CmsException('native_retry_requires_reconciliation');
        }
        return $this->store->locked(function () use ($id, $fingerprint, $actor) {
            $row = DB::table('discussionbridge_deliveries')->where('id', $id)->first();
            if (! $row) throw new CmsException('native_retry_requires_reconciliation');
            $state = $this->store->read(); $slot = $state['to'][$row->external_id] ?? [];
            if (isset($slot['retry_actions'][$fingerprint])) return $this->armRetry($state, $row, $fingerprint);
            if ($row->status !== 'reconciliation_required' || $row->last_error !== 'invalid_field'
                || isset($slot['closure']) || isset($slot['source_move']) || ! isset($slot['pending'])) {
                throw new CmsException('native_retry_requires_reconciliation');
            }
            $pending = $slot['pending']; $record = $this->pendingRecord($row, $pending);
            $mutation = $state['mutations'][$pending['key']] ?? null;
            if (! is_array($mutation) || ! hash_equals(hash('sha256', $mutation['identity']), $fingerprint)) {
                throw new CmsException('native_retry_requires_reconciliation');
            }
            $journal = new ReplayJournal($this->client, $this->store);
            $witness = $journal->invalidFieldWitness($state, $pending['key'], (object) ['bridge_record' => $record]);
            $this->retryAdmission($state, $row, $pending);
            $nextKey = 'to-resolve:'.Str::uuid();
            $action = ['actor_kind' => 'statamic_user', 'actor_id' => $actor,
                'authorized_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
                'refused_key' => $pending['key'], 'intent_key' => $nextKey, 'fingerprint' => $fingerprint,
                'native_revision' => $pending['native_revision'], 'queue_armed' => false, 'dispatch_started' => false,
                'prior_attempts' => (int) $row->attempts];
            $history = ['pending' => $pending, 'mutation' => $mutation, ...$witness,
                'continued_native_revision' => $pending['native_revision'], 'next_intent_key' => $nextKey, 'operator_action' => $action];
            if (isset($slot['rejected_history'][$pending['key']])) throw new CmsException('native_restore_required');
            // Stage without saving: refusal, new UUID, pointer and actor linkage
            // must enter ONE private commit before any queue reset or HTTP.
            $journal->prepare($state, $nextKey, 'V4-02', [], (object) ['bridge_record' => $record], false);
            $slot['rejected_history'][$pending['key']] = $history;
            $slot['retry_actions'][$fingerprint] = $action;
            $slot['pending'] = [...$pending, 'key' => $nextKey, 'operator_retry' => $fingerprint];
            $state['to'][$row->external_id] = $slot;
            $this->store->save($state);
            return $this->armRetry($state, $row, $fingerprint);
        });
    }

    /** Bounded protected view data only: no HTTP, native capture or retry intent. */
    public function retryOptions(): array
    {
        return $this->store->locked(function () {
            $state = $this->store->read(); $result = [];
            $rows = DB::table('discussionbridge_deliveries')->where('status', 'reconciliation_required')
                ->where('last_error', 'invalid_field')->orderBy('id')->limit(25)->get();
            foreach ($rows as $row) {
                $slot = $state['to'][$row->external_id] ?? [];
                if (isset($slot['closure']) || isset($slot['source_move']) || ! isset($slot['pending'])) continue;
                $pending = $slot['pending']; $mutation = $state['mutations'][$pending['key']] ?? null;
                if (! is_array($mutation)) continue;
                try {
                    (new ReplayJournal($this->client, $this->store))->invalidFieldWitness($state, $pending['key'],
                        (object) ['bridge_record' => $this->pendingRecord($row, $pending)]);
                } catch (CmsException) { continue; }
                $result[] = ['delivery_id' => $row->id, 'entry_id' => $row->entry_id,
                    'request_identity' => hash('sha256', $mutation['identity'])];
            }
            return $result;
        });
    }

    private function retryAdmission(array &$state, object $row, array $pending): void
    {
        if (! isset($state['to_binding'])) throw new CmsException('native_retry_requires_reconciliation');
        $slot = $state['to'][$row->external_id] ?? [];
        if (isset($slot['closure']) || isset($slot['source_move'])) throw new CmsException('native_retry_requires_reconciliation');
        $record = $this->pendingRecord($row, $pending);
        $source = $this->preparation->capture($row);
        if (! isset($pending['prepared_source'], $pending['native_source']) || $row->desired_revision !== $pending['native_revision']
            || $source['record']['source_publication']['revision'] !== $pending['native_revision']
            || ToSourceSnapshot::digest($source) !== ToSourceSnapshot::digest($pending['prepared_source'])
            || ToSourceSnapshot::digest($source['native_source']) !== ToSourceSnapshot::digest($pending['native_source'])) {
            throw new CmsException('native_source_changed_during_capture');
        }
        $this->connection($state, false);
        $accepted = $slot['accepted'] ?? null;
        if ($accepted !== null) {
            $this->queueBinding($row, $accepted);
            if ($record->expected_source_revision !== $accepted['source_revision']) throw new CmsException('native_ownership_conflict');
            $observed = $this->detail($row, $accepted);
            $known = $this->client->codec()->parseJson($accepted['record']);
            foreach (['topic_url', 'source_created_at', 'source_updated_at', 'source_content_sha256', 'source_content_bytes'] as $field) {
                $expected = $field === 'topic_url' ? $accepted['topic_url'] : $known->$field;
                if ($observed->$field !== $expected) throw new CmsException('native_ownership_conflict');
            }
        } else {
            if ($record->expected_source_revision !== null || $row->resource_id !== null || $row->topic_id !== null
                || $row->cms_source_revision !== null) throw new CmsException('native_ownership_conflict');
            $this->assertNewSource($row);
        }
        // Re-read native evidence after remote ownership checks, while holding
        // the existing shared private writer. No source/config repair is guessed.
        if (ToSourceSnapshot::digest($source) !== ToSourceSnapshot::digest($this->preparation->capture($row))) {
            throw new CmsException('native_source_changed_during_capture');
        }
    }

    private function armRetry(array &$state, object $row, string $fingerprint): array
    {
        $slot = &$state['to'][$row->external_id]; $action = &$slot['retry_actions'][$fingerprint];
        $result = ['delivery_id' => $row->id, 'intent_key' => $action['intent_key'], 'state' => 'already_recorded'];
        $mutation = $state['mutations'][$action['intent_key']] ?? null;
        if (isset($mutation['rejection'])) return [...$result, 'state' => 'reconciliation_required'];
        if ($action['queue_armed'] || $action['dispatch_started'] || ($slot['pending']['key'] ?? null) !== $action['intent_key']) return $result;
        if (! is_array($mutation) || $mutation['result'] !== null) return $result;
        $this->retryAdmission($state, $row, $slot['pending']);
        if ($row->status === 'reconciliation_required' && $row->last_error === 'invalid_field') {
            $updated = DB::table('discussionbridge_deliveries')->where('id', $row->id)
                ->where('external_id', $row->external_id)->where('canonical_url', $row->canonical_url)
                ->where('desired_revision', $action['native_revision'])->where('status', 'reconciliation_required')
                ->where('last_error', 'invalid_field')->where('attempts', $action['prior_attempts'])
                ->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null, 'updated_at' => now()]);
            if ($updated !== 1) throw new CmsException('native_retry_requires_reconciliation');
        } elseif (! in_array($row->status, ['pending', 'processing', 'failed'], true)) {
            throw new CmsException('native_retry_requires_reconciliation');
        }
        $action['queue_armed'] = true;
        $this->store->save($state);
        return [...$result, 'state' => 'queued'];
    }

    private function pendingRecord(object $row, array $pending): stdClass
    {
        $record = $this->client->codec()->parseJson($pending['record']);
        $this->client->codec()->validate('ToRecord', $record);
        if ($record->external_id !== $row->external_id || $record->canonical_url !== $row->canonical_url) {
            throw new CmsException('native_restore_required');
        }
        return $record;
    }

    private function retainRejected(array &$state, object $row, array $pending, string $nativeRevision, ?string $nextKey): void
    {
        $history = ['pending' => $pending, 'rejection' => $state['mutations'][$pending['key']]['rejection'],
            'continued_native_revision' => $nativeRevision, 'next_intent_key' => $nextKey];
        $prior = $state['to'][$row->external_id]['rejected_history'][$pending['key']] ?? null;
        if ($prior !== null && $prior !== $history) {
            throw new CmsException('native_restore_required');
        }
        $state['to'][$row->external_id]['rejected_history'][$pending['key']] = $history;
    }

    private function withdraw(array &$state, object $row, array $slot): array
    {
        $closure = $slot['closure'];
        if (! in_array($closure['stage'], ['confirmed', 'withdrawn'], true) || $closure['accepted_before'] === null) {
            throw new CmsException('native_source_closure_requires_reconciliation');
        }
        $accepted = $closure['accepted_before'];
        $this->queueBinding($row, $accepted);
        if (($slot['accepted'] ?? null) !== $accepted || ($slot['pending'] ?? null) !== $closure['pending_source_before']) {
            throw new CmsException('native_restore_required');
        }
        $this->connection($state);
        $key = $closure['mutation_key'];
        $journal = $state['mutations'][$key] ?? null;
        if ($journal === null || $journal['route'] !== 'V4-05' || $journal['body'] !== $closure['input']) {
            throw new CmsException('native_restore_required');
        }
        $detail = $this->client->request('V4-04', ['resource_id' => $accepted['resource_id']]);
        if ($detail->direction !== 'to_discourse' || $detail->resource_id !== $accepted['resource_id']
            || $detail->external_id !== $row->external_id || $detail->canonical_url !== $row->canonical_url
            || $detail->topic_id !== $accepted['topic_id'] || $detail->topic_url !== $accepted['topic_url']
            || $detail->source_revision !== $accepted['source_revision']
            || ($detail->state !== 'active' && ! ($detail->state === 'withdrawn' && isset($journal['dispatched'])))) {
            // Metadata cannot prove pending02(B) acceptance. Keep A, B and capture.
            throw new CmsException('native_source_withdrawal_requires_reconciliation');
        }
        $state['mutations'][$key]['dispatched'] = true;
        $this->store->save($state);
        $body = $this->client->codec()->parseJson($closure['input']);
        $result = (new ReplayJournal($this->client, $this->store))->mutate($state, $key, 'V4-05',
            ['resource_id' => $accepted['resource_id']], $body);
        if ($result->state !== 'withdrawn' || $result->direction !== 'to_discourse'
            || $result->resource_id !== $accepted['resource_id'] || $result->topic_id !== $accepted['topic_id']
            || $result->topic_url !== $accepted['topic_url'] || $result->external_id !== $row->external_id
            || $result->canonical_url !== $row->canonical_url || $result->source_revision !== $accepted['source_revision']) {
            throw new CmsException('returned_identity_mismatch');
        }
        $state['to'][$row->external_id]['closure']['stage'] = 'withdrawn';
        $state['to'][$row->external_id]['closure']['result'] = $this->client->codec()->encodeJson($result);
        $this->store->save($state);
        // No pending02 replay/removal and no rewrite of accepted edition/history.
        return [...$this->values($accepted), 'outcome' => 'withdrawn'];
    }

    private function connection(array &$state, bool $persist = true): stdClass
    {
        $connection = $this->client->request('V4-01');
        if ($connection->connection_id !== $this->configuration->connectionId()
            || $connection->destination_origin !== $this->configuration->siteOrigin()
            || $connection->profile !== $this->native->profile() || ! in_array('to_discourse', $connection->directions, true)
            || $this->configuration->lane() !== null && $connection->publication_lane !== $this->configuration->lane()) {
            throw new CmsException('scope_denied');
        }
        $binding = ['connection_id' => $connection->connection_id, 'profile' => $connection->profile,
            'destination_origin' => $connection->destination_origin, 'forum_origin' => $this->configuration->forumOrigin(),
            'publication_lane' => $connection->publication_lane, 'scope_revision' => $connection->scope_revision];
        if (isset($state['to_binding']) && $state['to_binding'] !== $binding) {
            throw new CmsException('scope_denied');
        }
        $state['to_binding'] = $binding;
        if ($persist) $this->store->save($state);
        return $connection;
    }

    private function queueBinding(object $row, array $accepted): void
    {
        $record = $this->client->codec()->parseJson($accepted['record']);
        if ($record->external_id !== $row->external_id || $record->canonical_url !== $row->canonical_url
            || $row->resource_id !== null && $row->resource_id !== $accepted['resource_id']
            || $row->topic_id !== null && $row->topic_id !== $accepted['topic_id']) {
            throw new CmsException('native_restore_required');
        }
    }

    private function detail(object $row, array $accepted): stdClass
    {
        $detail = $this->client->request('V4-04', ['resource_id' => $accepted['resource_id']]);
        if ($detail->state !== 'active' || $detail->direction !== 'to_discourse' || $detail->resource_id !== $accepted['resource_id']
            || $detail->external_id !== $row->external_id || $detail->canonical_url !== $row->canonical_url
            || $detail->topic_id !== $accepted['topic_id'] || $detail->source_revision !== $accepted['source_revision']) {
            throw new CmsException('native_ownership_conflict');
        }
        return $detail;
    }

    private function assertNewSource(object $row): void
    {
        $cursor = null;
        $seen = [];
        for ($page = 0; $page < 10000; $page++) {
            $records = $this->client->request('V4-03', [], $cursor === null ? new stdClass : (object) ['cursor' => $cursor]);
            foreach ($records->items as $record) {
                if ($record->external_id === $row->external_id || $record->canonical_url === $row->canonical_url) {
                    throw new CmsException('native_ownership_conflict');
                }
            }
            if ($records->next_cursor === null) {
                return;
            }
            if (in_array($records->next_cursor, $seen, true)) {
                throw new CmsException('native_restore_required');
            }
            $seen[] = $cursor = $records->next_cursor;
        }
        throw new CmsException('metadata_unavailable');
    }

    private function values(array $accepted): array
    {
        return ['resource_id' => $accepted['resource_id'], 'topic_id' => $accepted['topic_id'], 'topic_url' => $accepted['topic_url'],
            'outcome' => $accepted['outcome'], 'forum_name' => $accepted['forum_name'],
            'cms_source_revision' => $accepted['source_revision'], 'cms_native_revision' => $accepted['native_revision'],
            'cms_synchronized_at' => $accepted['synchronized_at']];
    }
}
