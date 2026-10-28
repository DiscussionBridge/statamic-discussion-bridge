<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Carbon\CarbonImmutable;
use stdClass;

/** Observation only: no recovery wrappers, mutation replay, native save or commands. */
class NativeAttentionInspector
{
    public function __construct(private readonly CmsClient $client, private readonly NativeWriter $writer,
        private readonly OwnedFragments $fragments, private readonly PublicVerifier $verifier,
        private readonly StaticArtifact $artifacts, private readonly ?LocalStaticHooks $hooks = null) {}

    public function inspect(array $state, stdClass $inspection): stdClass
    {
        $profile = match (config('discussionbridge.cms_profile')) {
            'statamic_flat' => 'statamic-flat-1', 'statamic_db' => 'statamic-db-1', 'statamic_ssg' => 'statamic-ssg-1',
            default => null,
        };
        if ($profile === null) throw new CmsException('presentation_unsupported');
        $o = (object) ['profile' => $profile, 'observed_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'native_state' => 'unknown', 'object_exists' => null, 'native_id' => null, 'native_revision' => null,
            'ownership' => null, 'native_result' => null, 'verification' => null, 'source_revision' => null,
            'managed_content_sha256' => null, 'native_command' => 'none', 'pending_mutation' => 'none',
            'prepared_transaction_id' => $inspection->prepared_transaction?->transaction_id,
            'journal_sha256' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)), 'baseline_sha256' => null,
            'candidate_sha256' => null, 'current_sha256' => null, 'restoration_operation_id' => null, 'issue' => 'none'];
        $work = $inspection->work; $mapping = $inspection->mapping;
        if ($work->mapping_id !== $mapping->mapping_id || $work->source_id !== $mapping->source_id) return $this->issue($o, 'conflict', 'context_changed');
        if (in_array($mapping->mapping_state, ['url_move_open', 'url_move_attention', 'move_attention', 'moving'], true)) return $this->issue($o, 'conflict', 'url_move_open');
        $binding = $state['mappings'][$mapping->mapping_id] ?? null;
        if (! is_array($binding)) return $this->issue($o, 'unknown', 'baseline_missing');
        foreach ($state['mutations'] ?? [] as $mutation) {
            if (($mutation['result'] ?? null) !== null || isset($mutation['rejection'])) continue;
            $body = isset($mutation['body']) ? $this->client->codec()->parseJson($mutation['body']) : null;
            if (($mutation['path']['work_id'] ?? null) === $work->work_id
                || ($body?->authority?->work_id ?? null) === $work->work_id
                || ($mutation['route'] ?? null) === 'V4-13' && ($body?->mapping_id ?? null) === $mapping->mapping_id) {
                $o->pending_mutation = 'uncertain'; return $this->issue($o, 'unknown', 'uncertain_mutation');
            }
        }
        try {
            $readback = function (array $native) use ($state, $inspection, $binding, $o, $profile) {
                $o->object_exists = $native['object_exists']; $o->native_id = $native['native_id'];
                if (! $native['object_exists']) {
                    $o->native_state = 'never_applied'; $o->native_id = null;
                    $o->current_sha256 = hash('sha256', json_encode(['absent' => true, 'reserved_native_id' => $native['native_id'], 'url' => $inspection->mapping->canonical_url], JSON_THROW_ON_ERROR));
                    $o->candidate_sha256 = hash('sha256', $this->client->codec()->semanticIdentity($inspection->source)); return $o;
                }
                $o->native_revision = $native['native_revision']; $o->current_sha256 = $native['state_sha256'];
                $o->native_result = $native['result']; $o->source_revision = $native['receipt']->source_revision;
                $o->ownership = (object) ['resource_id' => $inspection->mapping->resource_id, 'external_id' => $inspection->mapping->external_id,
                    'native_id' => $native['native_id'], 'canonical_url' => $inspection->mapping->canonical_url,
                    'source_revision' => $native['receipt']->source_revision,
                    'ownership_receipt_sha256' => $native['result']->ownership_receipt_sha256];
                $o->managed_content_sha256 = $this->fragments->sha256($this->fragments->expected($native['entry']));
                if ($profile === 'statamic-ssg-1') return $this->staticBaseline($state, $inspection, $binding, $native, $o);
                if ($inspection->verified_native_result !== null && $this->same($native['result'], $inspection->verified_native_result)) {
                    $o->native_state = 'baseline'; $o->baseline_sha256 = $native['state_sha256']; return $this->prove($state, $inspection, $native, $o);
                }
                if ($native['work_id'] === $inspection->work->work_id && $native['source_revision'] === $inspection->work->source_revision
                    && $native['receipt']->last_lease_generation === $inspection->work->lease_generation
                    && ($inspection->work->action !== 'publish_revision' || $inspection->source !== null
                        && $native['result']->representation->source_content_sha256 === $inspection->source->content_sha256)) {
                    $o->native_state = 'candidate_applied'; $o->candidate_sha256 = $native['state_sha256']; return $this->prove($state, $inspection, $native, $o);
                }
                return $this->issue($o, 'conflict', 'native_conflict');
            };
            if ($profile === 'statamic-ssg-1' && isset($binding['static_transaction'])) {
                $snapshot = $state['static'][$binding['static_transaction']]['snapshot'] ?? null;
                if (($snapshot['exists'] ?? false) && isset($snapshot['binding']['native_revision'])) {
                    $baseline = $state;
                    $baseline['mappings'][$mapping->mapping_id] = [...$snapshot['binding'], 'static_transaction' => $binding['static_transaction']];
                    try { return $this->writer->observe($baseline, $mapping, $readback); }
                    catch (CmsException) { /* Exact baseline did not match; inspect known candidate without changing either record. */ }
                }
            }
            return $this->writer->observe($state, $mapping, $readback);
        } catch (CmsException $error) {
            return $this->issue($o, $error->failureCode === 'native_ownership_conflict' ? 'conflict' : 'unknown',
                $error->failureCode === 'native_ownership_conflict' ? 'native_conflict' : 'baseline_missing');
        }
    }

    private function staticBaseline(array $state, stdClass $i, array $binding, array $native, stdClass $o): stdClass
    {
        $id = $binding['static_transaction'] ?? null; $tx = $id === null ? null : ($state['static'][$id] ?? null);
        if ($i->prepared_transaction === null) {
            if ($tx !== null && ! in_array($tx['state'], ['verified', 'aborted', 'settled_for_retry'], true)) return $this->issue($o, 'unknown', 'baseline_missing');
            if ($i->verified_native_result !== null && $this->same($native['result'], $i->verified_native_result)) {
                $o->native_state = 'baseline'; $o->baseline_sha256 = $native['state_sha256']; return $this->prove($state, $i, $native, $o);
            }
            return $this->issue($o, 'unsupported', 'baseline_missing');
        }
        if ($tx === null || $id !== $i->prepared_transaction->transaction_id || $tx['work_id'] !== $i->work->work_id
            || ! isset($tx['snapshot']['binding']['last_verified'], $tx['previous_artifact']) || ! $tx['snapshot']['exists']
            || ! $this->preparedEvidence($state, $i)
            || $i->verified_native_result === null || ! $this->same($native['result'], $i->verified_native_result)) return $this->issue($o, 'unknown', 'baseline_missing');
        $o->native_command = ($tx['commands'] ?? []) === [] ? 'none' : ($this->hooks?->observe($id, $tx) ?? 'uncertain');
        if ($o->native_command === 'active') return $this->issue($o, 'unknown', 'active_command');
        if ($o->native_command === 'uncertain') return $this->issue($o, 'unknown', 'uncertain_command');
        if ($native['native_revision'] !== $tx['snapshot']['revision']) return $this->issue($o, 'conflict', 'partial_files');
        $this->artifacts->assertCapture($tx['previous_artifact']);
        $current = $this->artifacts->inspect((string) config('statamic.ssg.destination'));
        if ($current['files'] !== $tx['previous_artifact']['files']) return $this->issue($o, 'conflict', 'partial_files');
        $source = null;
        if ($native['result']->kind === 'publish_revision') {
            $key = hash('sha256', $i->mapping->source_id."\0".$native['receipt']->source_revision);
            if (! isset($state['sources'][$key])) return $this->issue($o, 'unknown', 'baseline_missing');
            $source = $this->client->codec()->parseJson($state['sources'][$key]); $this->client->codec()->validate('Source', $source);
        }
        try { $o->verification = $this->verifier->inspect($i->mapping, $source, $native['result'],
            $source === null ? [] : $this->fragments->expected($native['entry']), $i->created_at); }
        catch (\Throwable) { return $this->issue($o, 'unknown', 'public_proof_unavailable'); }
        $o->native_state = 'restored_baseline'; $o->baseline_sha256 = $native['state_sha256'];
        foreach ($state['attention_restorations'] ?? [] as $operation => $record) {
            if ($record['transaction_id'] === $id && ($record['state'] ?? null) === 'restored') $o->restoration_operation_id = $operation;
        }
        return $o;
    }

    /** Exact immutable preparation evidence, without replaying its POST or settling it. */
    public function preparedEvidence(array $state, stdClass $i): bool
    {
        $prepared = $i->prepared_transaction;
        if ($prepared === null) return false;
        $tx = $state['static'][$prepared->transaction_id] ?? null;
        $mutation = $state['mutations']['static-prepare:'.$prepared->transaction_id.':'.$prepared->lease_generation] ?? null;
        if (! is_array($tx) || ! is_array($mutation) || ($mutation['route'] ?? null) !== 'V4-18'
            || ($mutation['path']['work_id'] ?? null) !== $i->work->work_id || ($mutation['result'] ?? null) === null
            || ($tx['prepared_at'] ?? null) !== $prepared->prepared_at || ! isset($tx['native_result'])) return false;
        foreach (['work_id', 'mapping_id', 'source_id', 'source_revision', 'policy_revision', 'action'] as $field) {
            if (! array_key_exists($field, $tx) || $tx[$field] !== $i->work->$field) return false;
        }
        try {
            $body = $this->client->codec()->parseJson($mutation['body']);
            $this->client->codec()->validateRoute('V4-18', ['path' => ['work_id' => $i->work->work_id], 'body' => $body]);
            $ack = $this->client->codec()->decodeResponse('V4-18', $mutation['result'], $mutation['http_status'] ?? 200);
            foreach (['work_id', 'mapping_id', 'source_id', 'source_revision', 'policy_revision', 'action'] as $field) {
                if ($ack->work->$field !== $i->work->$field) return false;
            }
            return $body->authority->lease_generation === $prepared->lease_generation
                && $body->transaction_id === $prepared->transaction_id && $body->prepared_at === $prepared->prepared_at
                && $this->same($body->native_result, $prepared->native_result)
                && $this->same($this->client->codec()->parseJson($tx['native_result']), $prepared->native_result);
        } catch (\Throwable) { return false; }
    }

    private function prove(array $state, stdClass $i, array $native, stdClass $o): stdClass
    {
        $source = null;
        if ($native['result']->kind === 'publish_revision') {
            if ($i->source !== null && $i->source->source_revision === $native['receipt']->source_revision) $source = $i->source;
            else {
                $key = hash('sha256', $i->mapping->source_id."\0".$native['receipt']->source_revision);
                if (isset($state['sources'][$key])) $source = $this->client->codec()->parseJson($state['sources'][$key]);
            }
            if ($source === null) return $this->issue($o, 'unknown', 'baseline_missing');
            $this->client->codec()->validate('Source', $source);
        }
        try { $o->verification = $this->verifier->inspect($i->mapping, $source, $native['result'],
            $source === null ? [] : $this->fragments->expected($native['entry']), $i->created_at); }
        catch (\Throwable) { $o->verification = null; } // Dynamic inspection proof is optional; ordinary new-lease verification remains mandatory.
        return $o;
    }

    private function same(mixed $a, mixed $b): bool { return $this->client->codec()->semanticIdentity($a) === $this->client->codec()->semanticIdentity($b); }
    private function issue(stdClass $o, string $state, string $issue): stdClass { $o->native_state = $state; $o->issue = $issue; return $o; }
}
