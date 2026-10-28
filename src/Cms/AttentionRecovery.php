<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use DiscussionBridge\CmsV4\NoContent;
use stdClass;

/** Closed r6 observation/discovery. Reports never grant native publication authority. */
class AttentionRecovery
{
    public function __construct(private readonly CmsClient $client, private readonly PrivateState $store,
        private readonly NativeAttentionInspector $inspector, private readonly NativePolicy $policy) {}

    public function check(): array
    {
        return $this->store->locked(function () {
            $state = $this->store->read(); $connection = $this->client->request('V4-01'); $policy = $this->client->request('V4-08');
            $this->policy->authorize($connection, $policy);
            return $this->poll($state, false);
        });
    }

    public function poll(array &$state, bool $discover = true): array
    {
        try { $capability = $this->client->request('V4-32'); }
        catch (\Throwable) { return ['supported' => false, 'reported' => 0, 'discovered' => 0]; }
        $profile = match (config('discussionbridge.cms_profile')) {
            'statamic_flat' => 'statamic-flat-1', 'statamic_db' => 'statamic-db-1', 'statamic_ssg' => 'statamic-ssg-1', default => null,
        };
        if ($capability->protocol_revision !== 'attention-recovery-1' || ! in_array($profile, $capability->inspection_profiles, true)) {
            return ['supported' => false, 'reported' => 0, 'discovered' => 0];
        }
        $summary = ['supported' => true, 'reported' => 0, 'discovered' => 0];
        $journal = new ReplayJournal($this->client, $this->store);
        $after = $state['attention_inspection_after'] ?? 0;
        $page = $this->client->request('V4-33', [], (object) ['after' => $after]);
        foreach ($page->items as $item) {
            if ($item->sequence <= $after) throw new CmsException('native_restore_required');
            $encoded = $this->client->codec()->encodeJson($item);
            $prior = $state['attention_inspections'][$item->inspection_id] ?? null;
            if ($prior !== null && $prior['request'] !== $encoded) throw new CmsException('identity_conflict');
            if ($prior === null) {
                $observation = $this->inspector->inspect($state, $item);
                $this->client->codec()->validate('AttentionObservation', $observation);
                $state['attention_inspections'][$item->inspection_id] = ['request' => $encoded,
                    'observation' => $this->client->codec()->encodeJson($observation), 'result' => null];
                $this->store->save($state);
            }
            $record = &$state['attention_inspections'][$item->inspection_id];
            $result = $journal->mutate($state, 'attention-report:'.$item->inspection_id, 'V4-34', ['inspection_id' => $item->inspection_id],
                (object) ['context_sha256' => $item->context_sha256, 'observation' => $this->client->codec()->parseJson($record['observation'])]);
            if ($result->inspection_id !== $item->inspection_id || $result->context_sha256 !== $item->context_sha256) throw new CmsException('identity_conflict');
            $record['result'] = $this->client->codec()->encodeJson($result); unset($record);
            $state['attention_inspection_after'] = $after = $item->sequence;
            $this->store->save($state); $summary['reported']++;
        }
        if ($page->next_after !== $after) throw new CmsException('native_restore_required');
        if (! $discover) return $summary;
        $after = $state['attention_recovery_after'] ?? 0;
        $page = $this->client->request('V4-35', [], (object) ['after' => $after]);
        foreach ($page->items as $item) {
            if ($item->sequence <= $after) throw new CmsException('native_restore_required');
            $encoded = $this->client->codec()->encodeJson($item);
            $known = $state['attention_recoveries'][$item->recovery_id] ?? null;
            if ($known !== null && $known['event'] !== $encoded) throw new CmsException('identity_conflict');
            if ($known === null) {
                $inspection = $state['attention_inspections'][$item->inspection_id] ?? null;
                if ($inspection === null) throw new CmsException('native_restore_required');
                $request = $this->client->codec()->parseJson($inspection['request']);
                $this->link($item, $request);
                $binding = $state['mappings'][$item->mapping->mapping_id] ?? null;
                if ($binding === null) throw new CmsException('native_ownership_conflict');
                $state['attention_recoveries'][$item->recovery_id] = ['event' => $encoded, 'status' => 'queued', 'poll' => 0,
                    'predecessor_binding' => $binding, 'predecessor_work' => $state['work'][$item->predecessor_work_id] ?? null,
                    'predecessor_static' => isset($binding['static_transaction']) ? ($state['static'][$binding['static_transaction']] ?? null) : null];
                // Durable exact continuation precedes the discovery cursor.
                if ($item->source !== null) {
                    $key = hash('sha256', $item->source->source_id."\0".$item->source->source_revision);
                    $source = $this->client->codec()->encodeJson($item->source);
                    if (isset($state['sources'][$key]) && $state['sources'][$key] !== $source) throw new CmsException('identity_conflict');
                    $state['sources'][$key] = $source;
                }
                $this->store->save($state);
            }
            $state['attention_recovery_after'] = $after = $item->sequence; $this->store->save($state); $summary['discovered']++;
        }
        if ($page->next_after !== $after) throw new CmsException('native_restore_required');
        return $summary;
    }

    public function claim(array &$state, ReplayJournal $journal): ?stdClass
    {
        foreach ($state['attention_recoveries'] ?? [] as $id => $record) {
            if (! in_array($record['status'], ['queued', 'claiming'], true)) continue;
            $event = $this->client->codec()->parseJson($record['event']);
            $request = $this->client->codec()->parseJson($state['attention_inspections'][$event->inspection_id]['request']);
            $this->link($event, $request);
            $key = 'attention-claim:'.$id.':'.$record['poll'];
            if ($record['status'] === 'claiming') {
                // This durable handoff was committed BEFORE ordinary execution.
                // Native state may now be the successor's uncertain write. Replay
                // its exact accepted claim; ordinary current/fence/receipt checks
                // reconcile that write, rather than reapplying the old baseline.
                if (($state['attention_active']['recovery_id'] ?? null) !== $id
                    || ($state['attention_active']['work_id'] ?? null) !== $event->work->work_id) throw new CmsException('native_restore_required');
                $lease = $journal->mutate($state, $key, 'V4-36', ['recovery_id' => $id], (object) ['work_id' => $event->work->work_id]);
                if ($lease === NoContent::VALUE) throw new CmsException('native_restore_required');
                foreach (['work_id', 'mapping_id', 'source_id', 'source_revision', 'policy_revision', 'action', 'attempt_cycle'] as $field) {
                    if ($lease->work->$field !== $event->work->$field) throw new CmsException('identity_conflict');
                }
                return $lease;
            }
            $prior = $this->client->codec()->parseJson($state['attention_inspections'][$event->inspection_id]['observation']);
            if ($this->client->codec()->semanticIdentity($event->native_baseline) !== $this->client->codec()->semanticIdentity($prior->native_result)) {
                throw new CmsException('native_ownership_conflict');
            }
            $current = $this->inspector->inspect($state, $request);
            foreach (['native_state', 'object_exists', 'native_id', 'native_revision', 'current_sha256', 'baseline_sha256', 'candidate_sha256', 'prepared_transaction_id'] as $field) {
                if ($current->$field !== $prior->$field) throw new CmsException('native_ownership_conflict');
            }
            if ($current->issue !== 'none' || ! in_array($current->native_state, ['baseline', 'never_applied', 'candidate_applied', 'restored_baseline'], true)) throw new CmsException('native_restore_required');
            $lease = $journal->mutate($state, $key, 'V4-36', ['recovery_id' => $id], (object) ['work_id' => $event->work->work_id]);
            if ($lease === NoContent::VALUE) { $state['attention_recoveries'][$id]['poll']++; $this->store->save($state); return null; }
            foreach (['work_id', 'mapping_id', 'source_id', 'source_revision', 'policy_revision', 'action', 'attempt_cycle'] as $field) {
                if ($lease->work->$field !== $event->work->$field) throw new CmsException('identity_conflict');
            }
            $afterLease = $this->inspector->inspect($state, $request);
            foreach (['native_state', 'object_exists', 'native_id', 'native_revision', 'current_sha256', 'baseline_sha256', 'candidate_sha256', 'prepared_transaction_id'] as $field) {
                if ($afterLease->$field !== $current->$field) throw new CmsException('native_ownership_conflict');
            }
            if ($afterLease->issue !== 'none') throw new CmsException('native_restore_required');
            $binding = &$state['mappings'][$event->mapping->mapping_id];
            if (($binding['native_id'] ?? null) !== $current->native_id && $current->object_exists !== false) throw new CmsException('native_ownership_conflict');
            if ($event->mode === 'settle_restored_baseline') {
                $transaction = $binding['static_transaction'] ?? null;
                if ($current->native_state !== 'restored_baseline' || $transaction !== $request->prepared_transaction?->transaction_id
                    || $event->native_baseline === null || $this->client->codec()->semanticIdentity($event->native_baseline) !== $this->client->codec()->semanticIdentity($prior->native_result)
                    || $event->verification === null || $this->client->codec()->semanticIdentity($event->verification) !== $this->client->codec()->semanticIdentity($prior->verification)) throw new CmsException('native_restore_required');
                $state['static'][$transaction]['state'] = 'settled_for_retry';
                $state['static'][$transaction]['recovered_by'] = ['recovery_id' => $id, 'work_id' => $event->work->work_id];
            }
            $binding['mapping'] = $this->client->codec()->encodeJson($event->mapping); unset($binding);
            $state['attention_recoveries'][$id]['status'] = 'claiming';
            $state['attention_active'] = ['recovery_id' => $id, 'work_id' => $lease->work->work_id];
            $this->store->save($state); return $lease;
        }
        return null;
    }

    public function handedOff(array &$state, stdClass $lease): void
    {
        $active = $state['attention_active'] ?? null;
        if ($active === null || $active['work_id'] !== $lease->work->work_id) return;
        $state['attention_recoveries'][$active['recovery_id']]['status'] = 'handed_off';
        unset($state['attention_active']); $this->store->save($state);
    }

    public function ownsMapping(array $state, string $mapping): bool
    {
        foreach ($state['attention_recoveries'] ?? [] as $record) {
            if (in_array($record['status'], ['queued', 'claiming'], true)
                && $this->client->codec()->parseJson($record['event'])->mapping->mapping_id === $mapping) return true;
        }
        return false;
    }

    private function link(stdClass $event, stdClass $request): void
    {
        if ($event->inspection_id !== $request->inspection_id || $event->context_sha256 !== $request->context_sha256
            || $event->predecessor_work_id !== $request->work->work_id || $event->predecessor_attempt_cycle !== $request->work->attempt_cycle
            || $event->work->work_id === $request->work->work_id || $event->work->attempt_cycle === $request->work->attempt_cycle) throw new CmsException('identity_conflict');
        foreach (['mapping_id', 'resource_id', 'connection_id', 'source_id', 'external_id', 'canonical_url', 'destination_native_id'] as $field) {
            if ($event->mapping->$field !== $request->mapping->$field) throw new CmsException('native_ownership_conflict');
        }
        foreach (['mapping_id', 'source_id', 'source_revision', 'policy_revision', 'action'] as $field) {
            if ($event->work->$field !== $request->work->$field) throw new CmsException('identity_conflict');
        }
    }
}
