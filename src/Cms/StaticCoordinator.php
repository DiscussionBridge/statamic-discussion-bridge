<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use stdClass;

/** Protected static stage/build/native-deploy/public-proof/finalize journal. */
class StaticCoordinator
{
    public function __construct(private readonly CmsClient $client, private readonly PrivateState $store,
        private readonly NativeWriter $writer, private readonly NativeWriteGuard $guard,
        private readonly NativeStaticBuild $build, private readonly LocalStaticHooks $hooks,
        private readonly StaticArtifact $artifacts, private readonly OwnedFragments $fragments,
        private readonly PublicVerifier $verifier)
    {
    }

    public function begin(array &$state, stdClass $mapping, stdClass $lease): ?stdClass
    {
        $this->hooks->configured();
        $this->build->configured();
        $transactionId = $state['mappings'][$mapping->mapping_id]['static_transaction'] ?? null;
        foreach ($state['static'] ?? [] as $id => $transaction) {
            if (! in_array($transaction['state'], ['verified', 'aborted', 'settled_for_retry'], true) && $id !== $transactionId) {
                throw new CmsException('native_restore_required');
            }
        }
        if ($transactionId !== null) {
            $transaction = $state['static'][$transactionId];
            if ($transaction['work_id'] === $lease->work->work_id && ! in_array($transaction['state'], ['verified', 'aborted'], true)) {
                foreach (['mapping_id', 'source_id', 'source_revision', 'policy_revision', 'action'] as $field) {
                    if ($transaction[$field] !== $lease->work->$field) {
                        throw new CmsException('work_superseded');
                    }
                }
                if (isset($transaction['native_result'])) {
                    $result = $this->client->codec()->parseJson($transaction['native_result'], 262144);
                    $this->currentEntry($state, $mapping, $result);
                    return $result;
                }
                return null;
            }
            if (! in_array($transaction['state'], ['verified', 'aborted', 'settled_for_retry'], true)) {
                throw new CmsException('native_restore_required');
            }
        }
        $transactionId = (string) Str::uuid();
        $state['static'][$transactionId] = ['state' => 'intent', 'work_id' => $lease->work->work_id,
            'mapping_id' => $lease->work->mapping_id, 'source_id' => $lease->work->source_id,
            'source_revision' => $lease->work->source_revision, 'policy_revision' => $lease->work->policy_revision,
            'action' => $lease->work->action, 'snapshot' => $this->writer->staticSnapshot($state, $mapping->mapping_id),
            'commands' => []];
        if (isset($state['last_static_artifact'])) {
            $this->artifacts->assertCapture($state['last_static_artifact']);
            $state['static'][$transactionId]['previous_artifact'] = $state['last_static_artifact'];
        }
        $state['mappings'][$mapping->mapping_id]['static_transaction'] = $transactionId;
        $this->store->save($state);
        return null;
    }

    public function perform(array &$state, ReplayJournal $journal, stdClass &$lease, stdClass $mapping,
        ?stdClass $source, stdClass $result, callable $heartbeat): void
    {
        $id = $state['mappings'][$mapping->mapping_id]['static_transaction'];
        $transaction = &$state['static'][$id];
        if (isset($transaction['native_result']) && $transaction['native_result'] !== $this->client->codec()->encodeJson($result)) {
            throw new CmsException('native_ownership_conflict');
        }
        $transaction['native_result'] = $this->client->codec()->encodeJson($result);
        $transaction['prepared_at'] ??= CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z');
        $transaction['state'] = 'staged';
        $this->store->save($state);
        $prepared = $journal->mutate($state, 'static-prepare:'.$id.':'.$lease->lease_generation, 'V4-18',
            ['work_id' => $lease->work->work_id], (object) ['authority' => $this->authority($lease),
                'transaction_id' => $id, 'native_result' => $result, 'prepared_at' => $transaction['prepared_at']]);
        $this->readback($lease, $prepared, ['synchronized', 'deployed'], $result);
        $transaction['state'] = 'prepared';
        $this->store->save($state);
        try {
            // Hold every existing owned entry's native fence through the whole
            // generated-tree capture and command/proof boundary. Native public
            // selection remains the native generator's configured selection.
            $ids = array_values(array_unique(array_filter(array_column($state['mappings'], 'native_id'))));
            sort($ids, SORT_STRING);
            $this->lockedEntries($ids, function () use (&$state, $journal, &$lease, $mapping, $source, $result, $heartbeat, $id) {
                $transaction = &$state['static'][$id];
                $heartbeat();
                $entry = $this->currentEntry($state, $mapping, $result);
                $this->assertOtherOwnedEntries($state, $mapping->mapping_id);
                if (! isset($transaction['artifact'])) {
                    $this->build->generate($state, $id, $heartbeat);
                }
                $this->currentEntry($state, $mapping, $result);
                $this->assertOtherOwnedEntries($state, $mapping->mapping_id);
                $this->artifacts->assertCapture($transaction['artifact']);
                $expected = $lease->work->action === 'publish_revision' ? $this->fragments->expected($entry) : [];
                $this->artifacts->checkOwnedPage($transaction['artifact'], $entry, $mapping->mapping_id, $expected, $source === null);
                foreach ($state['mappings'] as $otherId => $binding) {
                    if ($otherId !== $mapping->mapping_id && isset($binding['last_verified'], $binding['native_id'])) {
                        $other = $this->writer->freshEntry($binding['native_id']);
                        $this->artifacts->checkOwnedPage($transaction['artifact'], $other, $otherId,
                            $other->published() ? $this->fragments->expected($other) : [], ! $other->published());
                    }
                }
                $heartbeat();
                $deployment = $this->hooks->deploy($state, $id, $heartbeat);
                $transaction['deployment'] = $deployment;
                $transaction['state'] = 'deployed';
                $this->store->save($state);
                $heartbeat();
                $this->currentEntry($state, $mapping, $result);
                $key = 'static-finalize:'.$id.':'.$lease->lease_generation;
                if (isset($state['mutations'][$key])) {
                    $proof = $this->client->codec()->parseJson($state['mutations'][$key]['body'])->verification;
                } else {
                    $proof = $this->verifier->verify($mapping, $source, $result, $expected,
                        $deployment['deployed_at'], $lease->server_time);
                }
                $this->artifacts->assertCapture($transaction['artifact']);
                $this->currentEntry($state, $mapping, $result);
                $finalized = $journal->mutate($state, $key, 'V4-19', ['work_id' => $lease->work->work_id],
                    (object) ['authority' => $this->authority($lease), 'transaction_id' => $id,
                        'deployment_id' => $deployment['deployment_id'], 'deployed_at' => $deployment['deployed_at'],
                        'verification' => $proof]);
                $this->complete($state, $id, $lease, $finalized, $result, $proof);
            });
        } catch (UncertainMutation $error) {
            // Exact prepare/finalize identity and native deployment remain durable.
            throw $error;
        } catch (CmsException $error) {
            $this->abort($state, $journal, $lease, $mapping, $id, $heartbeat);
            throw $error;
        }
    }

    private function abort(array &$state, ReplayJournal $journal, stdClass &$lease, stdClass $mapping,
        string $id, callable $heartbeat): void
    {
        $transaction = &$state['static'][$id];
        $restored = false;
        try {
            $heartbeat();
            $nativeId = $state['mappings'][$mapping->mapping_id]['native_id'];
            $this->guard->locked($nativeId, function () use (&$state, &$transaction, &$restored, &$lease, $mapping, $id, $heartbeat) {
                if (! isset($transaction['previous_artifact']) || ! $transaction['snapshot']['exists']) {
                    return;
                }
                $restore = $this->hooks->restore($state, $id, $heartbeat);
                if (! $this->writer->restoreStatic($state, $mapping->mapping_id, $transaction['snapshot'])) {
                    return;
                }
                $binding = $state['mappings'][$mapping->mapping_id];
                $priorResult = $this->client->codec()->parseJson($binding['result'], 262144);
                $entry = $this->writer->freshEntry($binding['native_id']);
                $priorSource = null;
                if ($priorResult->kind === 'publish_revision') {
                    $key = hash('sha256', $mapping->source_id."\0".$binding['source_revision']);
                    $priorSource = $this->client->codec()->parseJson($state['sources'][$key], 262144);
                }
                $proof = $this->verifier->verify($mapping, $priorSource, $priorResult,
                    $priorSource === null ? [] : $this->fragments->expected($entry), $restore['deployed_at'], $lease->server_time);
                $transaction['restoration_proof'] = $this->client->codec()->encodeJson($proof);
                $restored = true;
            });
        } catch (\Throwable) {
            // A native hook, changed object, missing prior result or failed actual
            // public proof cannot be converted to a successful restoration.
        }
        $heartbeat();
        $response = $journal->mutate($state, 'static-abort:'.$id.':'.$lease->lease_generation, 'V4-20',
            ['work_id' => $lease->work->work_id], (object) ['authority' => $this->authority($lease),
                'transaction_id' => $id, 'native_restored' => $restored, 'safe_detail' => null]);
        $this->readback($lease, $response, ['attention']);
        $transaction['state'] = $restored ? 'aborted' : 'restore_required';
        $transaction['native_restored'] = $restored;
        $this->store->save($state);
    }

    /** Recover only an already journaled terminal finalize/abort; never invent a receipt. */
    public function terminal(array &$state, ReplayJournal $journal, stdClass $lease, stdClass $status): bool
    {
        $id = $state['mappings'][$lease->work->mapping_id]['static_transaction'] ?? null;
        if ($id === null || $state['static'][$id]['work_id'] !== $lease->work->work_id) {
            return false;
        }
        $transaction = &$state['static'][$id];
        foreach (['static-finalize:' => 'V4-19', 'static-abort:' => 'V4-20'] as $prefix => $route) {
            $key = $prefix.$id.':'.$lease->lease_generation;
            if (! isset($state['mutations'][$key])) {
                continue;
            }
            $payload = $this->client->codec()->parseJson($state['mutations'][$key]['body']);
            unset($payload->mutation_id);
            $response = $journal->mutate($state, $key, $route, ['work_id' => $lease->work->work_id], $payload);
            if ($route === 'V4-19') {
                $result = $this->client->codec()->parseJson($transaction['native_result'], 262144);
                $this->currentEntry($state, $status->mapping, $result);
                $this->complete($state, $id, $lease, $response, $result, $payload->verification);
            } else {
                $this->readback($lease, $response, ['attention']);
                $transaction['native_restored'] = $payload->native_restored;
                $transaction['state'] = $payload->native_restored ? 'aborted' : 'restore_required';
                $this->store->save($state);
            }
            return true;
        }
        return false;
    }

    public function aborted(array $state, string $mappingId): bool
    {
        $id = $state['mappings'][$mappingId]['static_transaction'] ?? null;
        return $id !== null && isset($state['static'][$id]['native_restored']);
    }

    private function complete(array &$state, string $id, stdClass $lease, stdClass $response, stdClass $result, stdClass $proof): void
    {
        $this->readback($lease, $response, ['verified'], $result);
        if ($response->mapping->publicly_verified_at !== $proof->checked_at
            || $lease->work->action === 'publish_revision' && $response->mapping->publicly_verified_source_revision !== $lease->work->source_revision
            || $lease->work->action === 'withdraw' && $response->mapping->mapping_state !== 'withdrawn') {
            throw new CmsException('identity_conflict');
        }
        $state['static'][$id]['state'] = 'verified';
        $state['mappings'][$lease->work->mapping_id]['last_verified'] = $this->client->codec()->encodeJson($proof);
        $state['last_static_artifact'] = $state['static'][$id]['artifact'];
        $this->store->save($state);
    }

    private function currentEntry(array $state, stdClass $mapping, stdClass $result)
    {
        $binding = $state['mappings'][$mapping->mapping_id];
        $entry = $this->writer->freshEntry($result->native_id);
        if (! $entry || $entry->get('discussionbridge_cms_mapping_id') !== $mapping->mapping_id
            || $entry->absoluteUrl() !== $mapping->canonical_url || $result->native_id !== $binding['native_id']
            || $this->writer->revision($entry) !== $result->native_revision || $binding['native_revision'] !== $result->native_revision) {
            throw new CmsException('native_ownership_conflict');
        }
        return $entry;
    }

    private function assertOtherOwnedEntries(array $state, string $mappingId): void
    {
        foreach ($state['mappings'] as $id => $binding) {
            if ($id === $mappingId || ! isset($binding['native_id'])) {
                continue;
            }
            $entry = $this->writer->freshEntry($binding['native_id']);
            if (! isset($binding['last_verified']) || ! $entry || $this->writer->revision($entry) !== ($binding['native_revision'] ?? null)) {
                throw new CmsException('native_restore_required');
            }
        }
    }

    private function lockedEntries(array $ids, callable $operation): mixed
    {
        if ($ids === []) {
            return $operation();
        }
        $id = array_shift($ids);
        return $this->guard->locked($id, fn () => $this->lockedEntries($ids, $operation));
    }

    private function readback(stdClass $lease, stdClass $response, array $states, ?stdClass $result = null): void
    {
        foreach (['work_id', 'mapping_id', 'source_id', 'source_revision', 'policy_revision', 'action'] as $field) {
            if ($response->work->$field !== $lease->work->$field) {
                throw new CmsException('work_superseded');
            }
        }
        if ($response->work->lease_generation !== $lease->lease_generation || ! in_array($response->work->state, $states, true)
            || $response->mapping->mapping_id !== $lease->work->mapping_id
            || $result !== null && $response->mapping->destination_native_id !== $result->native_id) {
            throw new CmsException('identity_conflict');
        }
    }

    private function authority(stdClass $lease): stdClass
    {
        return (object) ['work_id' => $lease->work->work_id, 'lease_token' => $lease->lease_token,
            'lease_generation' => $lease->lease_generation, 'source_revision' => $lease->work->source_revision,
            'policy_revision' => $lease->work->policy_revision];
    }
}
