<?php
namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Native-owner explicit restoration only. Never called by Check, report or forum Retry. */
class AttentionBaselineRestorer
{
    public function __construct(private readonly CmsClient $client, private readonly PrivateState $store,
        private readonly NativeWriter $writer, private readonly NativeAttentionInspector $inspector,
        private readonly LocalStaticHooks $hooks, private readonly StaticArtifact $artifacts,
        private readonly NativePolicy $policy) {}

    public function restore(string $inspectionId, bool $apply = false, bool $deployBaseline = false): array
    {
        $this->client->codec()->validate('UUID', $inspectionId);
        return $this->store->locked(function () use ($inspectionId, $apply, $deployBaseline) {
            if (config('discussionbridge.cms_profile') !== 'statamic_ssg') throw new CmsException('presentation_unsupported');
            $state = $this->store->read(); $saved = $state['attention_inspections'][$inspectionId] ?? null;
            if ($saved === null) throw new CmsException('native_restore_required');
            $request = $this->client->codec()->parseJson($saved['request']);
            $connection = $this->client->request('V4-01'); $policy = $this->client->request('V4-08'); $this->policy->authorize($connection, $policy);
            $status = $this->client->request('V4-14', ['work_id' => $request->work->work_id]);
            if ($status->work->state !== 'attention' || $status->work->attempt_cycle !== $request->work->attempt_cycle
                || $policy->policy_revision !== $request->work->policy_revision) throw new CmsException('policy_changed');
            foreach (['work_id', 'mapping_id', 'source_id', 'source_revision', 'policy_revision', 'action', 'lease_generation'] as $field) {
                if ($status->work->$field !== $request->work->$field) throw new CmsException('native_restore_required');
            }
            foreach (['mapping_id', 'resource_id', 'source_id', 'external_id', 'canonical_url', 'destination_native_id', 'mapping_revision'] as $field) {
                if ($status->mapping->$field !== $request->mapping->$field) throw new CmsException('native_ownership_conflict');
            }
            $id = $request->prepared_transaction?->transaction_id;
            $tx = $id === null ? null : ($state['static'][$id] ?? null);
            if ($tx === null || $tx['work_id'] !== $request->work->work_id
                || ! ($tx['snapshot']['exists'] ?? false) || ! isset($tx['snapshot']['binding']['last_verified'], $tx['previous_artifact'])
                || $request->verified_native_result === null || ! $this->inspector->preparedEvidence($state, $request)
                || ! in_array($this->hooks->observe($id, $tx), ['none', 'settled'], true)) throw new CmsException('native_restore_required');
            $observation = $this->inspector->inspect($state, $request);
            if ($observation->pending_mutation === 'uncertain') throw new CmsException('native_restore_required');
            $this->artifacts->assertCapture($tx['previous_artifact']);
            $bytes = base64_decode($tx['snapshot']['bytes'], true);
            if (! is_string($bytes) || 'sha256:'.hash('sha256', $bytes) !== $tx['snapshot']['revision']) throw new CmsException('native_restore_required');
            return $this->writer->observe($state, $request->mapping, function () use (&$state, $request, $inspectionId, $id, $tx, $apply, $deployBaseline) {
                $preview = ['inspection_id' => $inspectionId, 'transaction_id' => $id, 'native_id' => $tx['snapshot']['binding']['native_id'],
                    'native_path' => $tx['snapshot']['path'], 'artifact_sha256' => $tx['previous_artifact']['sha256'],
                    'artifact_files' => array_keys($tx['previous_artifact']['files']), 'state' => 'preview'];
                if (! $apply) return $preview;
                if (! $deployBaseline) throw new CmsException('native_restore_required');
                $operation = null;
                foreach ($state['attention_restorations'] ?? [] as $key => $record) {
                    if ($record['inspection_id'] === $inspectionId) { $operation = $key; break; }
                }
                if ($operation === null) {
                    $operation = (string) Str::uuid();
                    $state['attention_restorations'][$operation] = ['inspection_id' => $inspectionId, 'transaction_id' => $id,
                        'work_id' => $request->work->work_id, 'actor_kind' => 'native_cli', 'state' => 'intent', 'prepared_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
                        'previous_artifact' => $tx['previous_artifact'], 'commands' => []];
                    $this->store->save($state);
                }
                $record = &$state['attention_restorations'][$operation];
                if ($record['state'] !== 'restored') {
                    if (! in_array($this->hooks->observe($operation, $record), ['none', 'settled'], true)) throw new CmsException('native_restore_required');
                    $persist = function () use (&$state) { $this->store->save($state); };
                    // This command is selected by the native owner, never a forum-supplied argv.
                    $record['deployment'] = $this->hooks->restoreFor($operation, $record, $persist, fn () => null);
                    if (! $this->writer->restoreStatic($state, $request->mapping->mapping_id, $tx['snapshot'])) throw new CmsException('native_restore_required');
                    $record['state'] = 'restored'; $this->store->save($state);
                }
                unset($record);
                $fresh = $this->inspector->inspect($state, $request);
                if ($fresh->native_state !== 'restored_baseline' || $fresh->verification === null || $fresh->issue !== 'none') throw new CmsException('native_restore_required');
                return [...$preview, 'state' => 'restored', 'restoration_operation_id' => $operation,
                    'message' => 'Recorded baseline restored and inspected. Forum Check and explicit settlement-and-retry are still required.'];
            });
        });
    }
}
