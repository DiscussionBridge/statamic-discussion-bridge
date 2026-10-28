<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use DiscussionBridge\CmsV4\NoContent;
use Statamic\Facades\Entry;
use stdClass;

class DynamicWorker
{
    public function __construct(private readonly CmsClient $client, private readonly PrivateState $store,
        private readonly NativePolicy $policyMapper, private readonly NativeWriter $writer,
        private readonly OwnedFragments $fragments, private readonly PublicVerifier $verifier,
        private readonly StaticCoordinator $static, private readonly ?AttentionRecovery $recovery = null)
    {
    }

    public function run(int $maximum = 25): array
    {
        NativeRepositories::install();
        return $this->store->locked(function () use ($maximum) {
            $state = $this->store->read();
            $connection = $this->client->request('V4-01');
            $policy = $this->client->request('V4-08');
            $this->policyMapper->authorize($connection, $policy);
            $identity = ['connection_id' => $connection->connection_id, 'profile' => $connection->profile,
                'destination_origin' => $connection->destination_origin,
                'forum_origin' => config('discussionbridge.forum_url')];
            if ($state['binding'] !== null && $state['binding'] !== $identity) {
                throw new CmsException('scope_denied');
            }
            $state['binding'] = $identity;
            $this->store->save($state);
            $journal = new ReplayJournal($this->client, $this->store);
            $this->recovery?->poll($state);
            $continuation = new SourceContinuation($this->client, $this->store);
            if ($policy->enabled) {
                $continuation->stage($state);
                $this->prepareMappings($state, $journal, $policy);
            }
            $summary = ['processed' => 0, 'verified' => 0, 'attention' => 0];
            $visited = [];
            for ($i = 0; $i < $maximum; $i++) {
                // An unfinished claim remains the same scoped mutation; a new
                // poll after completed204 or finished work gets a fresh identity.
                $poll = $state['claim_poll'] ?? 0;
                $claimKey = 'claim:'.$poll;
                $priorClaim = $state['mutations'][$claimKey] ?? null;
                $recoveryLease = $this->recovery?->claim($state, $journal);
                if ($recoveryLease !== null) {
                    $claimBody = (object) ['mapping_id' => $recoveryLease->work->mapping_id];
                } elseif ($priorClaim !== null) {
                    // Never change an uncertain/unfinished accepted claim tree.
                    $claimBody = $this->client->codec()->parseJson($priorClaim['body']);
                } else {
                    $mappingId = $this->claimMapping($state, $policy, $visited);
                    if ($mappingId === null) {
                        break;
                    }
                    $claimBody = (object) ['mapping_id' => $mappingId];
                }
                if (isset($claimBody->mapping_id)) {
                    $visited[$claimBody->mapping_id] = true;
                }
                $lease = $recoveryLease ?? $journal->mutate($state, $claimKey, 'V4-13', [], $claimBody);
                if ($lease === NoContent::VALUE) {
                    $state['claim_poll'] = $poll + 1;
                    $this->store->save($state);
                    continue;
                }
                if (isset($claimBody->mapping_id) && $lease->work->mapping_id !== $claimBody->mapping_id) {
                    throw new CmsException('identity_conflict');
                }
                $existingStatus = $this->client->request('V4-14', ['work_id' => $lease->work->work_id]);
                $this->identity($lease, $existingStatus);
                if (in_array($existingStatus->work->state, ['verified', 'attention', 'cancelled', 'superseded', 'retry_wait', 'pending'], true)
                    || $existingStatus->lease_expires_at !== null && \Carbon\CarbonImmutable::now('UTC')
                        ->greaterThanOrEqualTo(\Carbon\CarbonImmutable::parse($existingStatus->lease_expires_at))) {
                    $this->writer->reconcile($state, $lease->work->mapping_id);
                    if ($policy->publication_kind === 'static' && in_array($existingStatus->work->state, ['verified', 'attention'], true)) {
                        $this->static->terminal($state, $journal, $lease, $existingStatus);
                    }
                    $this->observeReceiverState($summary, $lease, $existingStatus);
                    $this->recovery?->handedOff($state, $lease);
                    $state['claim_poll'] = $poll + 1;
                    $this->store->save($state);
                    continue;
                }
                $state['work'][$lease->work->work_id] = $this->client->codec()->encodeJson($lease);
                $this->store->save($state);
                $summary['processed']++;
                try {
                    $this->perform($state, $journal, $continuation, $lease);
                    $summary['verified']++;
                    $this->recovery?->handedOff($state, $lease);
                    $state['claim_poll'] = $poll + 1;
                    $this->store->save($state);
                } catch (ContentPreservationLimitation $error) {
                    // Local semantic loss is not a declared receiver Failure.
                    // Retain the exact claim/source/native state for current-
                    // authority reconciliation after a faithful repair.
                    $state['local_content_issues'][$lease->work->work_id] = [
                        'reason' => $error->reason, 'mapping_id' => $lease->work->mapping_id,
                        'lost_function' => $error->lostFunction,
                        'lost_data' => $error->lostData,
                        'source_id' => $lease->work->source_id, 'source_revision' => $lease->work->source_revision,
                        'policy_revision' => $lease->work->policy_revision, 'action' => $lease->work->action,
                        'attempt_cycle' => $lease->work->attempt_cycle, 'lease_generation' => $lease->lease_generation,
                    ];
                    $state['claim_poll'] = $poll + 1;
                    $this->store->save($state);
                    $summary['local_issues'] = ($summary['local_issues'] ?? 0) + 1;
                    $this->recovery?->handedOff($state, $lease);
                    continue;
                } catch (CmsException $error) {
                    if ($error instanceof UncertainMutation) {
                        // Preserve the POST journal/claim pointer. The next pass
                        // reads status and replays exactly; never substitute fail.
                        throw $error;
                    }
                    $summary['attempt_failures'] = ($summary['attempt_failures'] ?? 0) + 1;
                    if ($policy->publication_kind === 'static' && $this->static->aborted($state, $lease->work->mapping_id)) {
                        $settled = $this->settledStaticAbort($state, $lease);
                        if ($settled !== null) {
                            $this->observeReceiverState($summary, $lease, $settled);
                        }
                        $state['claim_poll'] = $poll + 1;
                        $this->recovery?->handedOff($state, $lease);
                        $this->store->save($state);
                        break;
                    }
                    $code = $error->failureCode;
                    if ($this->client->codec()->hasType('ErrorCode')) {
                        $this->client->codec()->validate('ErrorCode', $code);
                    }
                    // Current authority can reject this if expired/superseded.
                    // Preserve the native pending result and exact fail replay.
                    try {
                        $failed = $journal->mutate($state, 'fail:'.$lease->work->work_id.':'.$lease->lease_generation,
                            'V4-17', ['work_id' => $lease->work->work_id], (object) [
                                'authority' => $this->authority($lease), 'code' => $code,
                                'native_change_applied' => isset($state['mappings'][$lease->work->mapping_id]['pending'])
                                    || ($state['mappings'][$lease->work->mapping_id]['generation'] ?? 0) === $lease->lease_generation,
                                'safe_detail' => null, 'retry_after_seconds' => null]);
                        $this->observeReceiverState($summary, $lease, $failed);
                    } catch (CmsException) {
                        // Uncertain/rejected authority: don't discard native state.
                        $this->store->save($state);
                        throw $error;
                    }
                    $state['claim_poll'] = $poll + 1;
                    $this->recovery?->handedOff($state, $lease);
                    $this->store->save($state);
                    break;
                }
            }
            return $summary;
        });
    }

    /** Count observed receiver Work states, never infer them from an exception. */
    private function observeReceiverState(array &$summary, stdClass $lease, stdClass $status): void
    {
        $this->identity($lease, $status);
        if ($status->work->state === 'attention') {
            $summary['attention']++;
        } elseif ($status->work->state === 'retry_wait') {
            $summary['retry_wait'] = ($summary['retry_wait'] ?? 0) + 1;
        }
    }

    /** Static abort readback is already journaled; do not add a status request. */
    private function settledStaticAbort(array $state, stdClass $lease): ?stdClass
    {
        $id = $state['mappings'][$lease->work->mapping_id]['static_transaction'] ?? null;
        $entry = $id === null ? null : ($state['mutations']['static-abort:'.$id.':'.$lease->lease_generation] ?? null);
        if ($entry === null || ($entry['result'] ?? null) === null) {
            return null;
        }
        if (($entry['route'] ?? null) !== 'V4-20' || ($entry['path']['work_id'] ?? null) !== $lease->work->work_id) {
            throw new CmsException('native_restore_required');
        }
        return $this->client->codec()->decodeResponse('V4-20', $entry['result'], $entry['http_status'] ?? 200);
    }

    /** Traverse the existing durable mapping order, never a new priority policy. */
    private function claimMapping(array &$state, stdClass $policy, array &$visited): ?string
    {
        foreach ($state['mappings'] as $mappingId => $binding) {
            if ($this->recovery?->ownsMapping($state, $mappingId)) continue;
            if (isset($visited[$mappingId])) {
                continue;
            }
            $visited[$mappingId] = true;
            $mapping = $this->client->request('V4-11', ['mapping_id' => $mappingId]);
            $saved = $this->client->codec()->parseJson($binding['mapping'], 262144);
            foreach (['mapping_id', 'resource_id', 'connection_id', 'source_id', 'external_id', 'canonical_url'] as $field) {
                if ($mapping->$field !== $saved->$field) {
                    throw new CmsException('identity_conflict');
                }
            }
            $state['mappings'][$mappingId]['mapping'] = $this->client->codec()->encodeJson($mapping);
            $this->store->save($state);
            $held = false;
            foreach ($state['local_content_issues'] ?? [] as $workId => $issue) {
                if ($issue['mapping_id'] !== $mappingId) {
                    continue;
                }
                // A desired revision alone cannot identify a Work/cycle/action.
                $status = $this->client->request('V4-14', ['work_id' => $workId]);
                $known = $this->client->codec()->parseJson($state['work'][$workId], 262144);
                $this->identity($known, $status);
                $this->writer->reconcile($state, $mappingId);
                $sameIntent = $status->work->attempt_cycle === $issue['attempt_cycle']
                    && $status->work->action === $issue['action']
                    && $status->work->source_revision === $issue['source_revision']
                    && $status->work->policy_revision === $issue['policy_revision']
                    && $policy->policy_revision === $issue['policy_revision']
                    && $mapping->desired_source_revision === $issue['source_revision'];
                if ($sameIntent && $issue['action'] === 'publish_revision'
                    && ! in_array($status->work->state, ['verified', 'attention', 'cancelled', 'superseded'], true)) {
                    $key = hash('sha256', $issue['source_id']."\0".$issue['source_revision']);
                    $source = $this->client->codec()->parseJson($state['sources'][$key], 262144);
                    $this->client->codec()->validate('Source', $source);
                    $content = file_get_contents($this->store->path('content-'.$key.'.html'));
                    if (! is_string($content) || strlen($content) !== $source->content_bytes
                        || hash('sha256', $content) !== $source->content_sha256 || preg_match('//u', $content) !== 1) {
                        throw new CmsException('content_hash_mismatch');
                    }
                    app(\CodeWorksLabs\DiscussionBridgeStatamic\Publication\NativePublication::class)
                        ->sourceTopicUrl($source->topic_url, (int) $source->topic_id);
                    try {
                        app(\CodeWorksLabs\DiscussionBridgeStatamic\Presentation\HtmlSanitizer::class)
                            ->sanitizeArticle($content, $source->topic_url);
                    } catch (ContentPreservationLimitation $error) {
                        if ($error->reason === $issue['reason']) {
                            if (($issue['lost_function'] ?? null) !== $error->lostFunction
                                || ($issue['lost_data'] ?? null) !== $error->lostData) {
                                $state['local_content_issues'][$workId]['lost_function'] = $error->lostFunction;
                                $state['local_content_issues'][$workId]['lost_data'] = $error->lostData;
                                $this->store->save($state);
                            }
                            $held = true;
                            continue;
                        }
                    }
                }
                // Faithful repair or genuinely changed/terminal authority is
                // reevaluated through a current receiver claim, never a reset.
                $state['local_content_issue_history'][] = ['work_id' => $workId, ...$issue];
                unset($state['local_content_issues'][$workId]);
                $this->store->save($state);
            }
            if (! $held) {
                return $mappingId;
            }
        }
        return null;
    }
    private function prepareMappings(array &$state, ReplayJournal $journal, stdClass $policy): void
    {
        foreach ($state['events'] as $eventId => $raw) {
            if (isset($state['handled_events'][$eventId])) {
                continue;
            }
            $event = $this->client->codec()->parseJson($raw, 262144);
            $this->client->codec()->validate('SourceEvent', $event);
            if ($event->event_type === 'publication') {
                $source = $event->source;
                $existing = null;
                foreach ($state['mappings'] as $binding) {
                    $mapping = $this->client->codec()->parseJson($binding['mapping'], 262144);
                    if ($mapping->source_id === $source->source_id) {
                        $existing = $mapping;
                        break;
                    }
                }
                $destination = $existing === null ? $this->writer->destination($source)
                    : ['external_id' => $existing->external_id, 'canonical_url' => $existing->canonical_url];
                $mapping = $journal->mutate($state, 'prepare:'.$eventId.':'.$policy->policy_revision, 'V4-09', [], (object) [
                    'source_id' => $source->source_id, 'source_revision' => $source->source_revision,
                    'policy_revision' => $policy->policy_revision, ...$destination]);
                if ($mapping->source_id !== $source->source_id || $mapping->external_id !== $destination['external_id']
                    || $mapping->canonical_url !== $destination['canonical_url']) {
                    throw new CmsException('identity_conflict');
                }
                if (! isset($state['mappings'][$mapping->mapping_id]) && $mapping->destination_native_id !== null) {
                    throw new CmsException('native_ownership_conflict');
                }
                $state['mappings'][$mapping->mapping_id]['mapping'] = $this->client->codec()->encodeJson($mapping);
            }
            // Revocation is a durable event; only current receiver work authorizes
            // native withdrawal. An absent feed item never unpublishes anything.
            $state['handled_events'][$eventId] = true;
            $this->store->save($state);
        }
    }

    private function perform(array &$state, ReplayJournal $journal, SourceContinuation $continuation, stdClass $lease): void
    {
        $work = $lease->work;
        $status = $this->client->request('V4-14', ['work_id' => $work->work_id]);
        $this->current($lease, $status);
        $mapping = $status->mapping;
        if (! isset($state['mappings'][$mapping->mapping_id])) {
            throw new CmsException('native_ownership_conflict');
        }
        $binding = &$state['mappings'][$mapping->mapping_id];
        if ($lease->lease_generation < ($binding['seen_generation'] ?? 0)) {
            throw new CmsException('lease_conflict');
        }
        $binding['seen_generation'] = $lease->lease_generation;
        $this->store->save($state);
        unset($binding);
        $connection = $this->client->request('V4-01');
        $policy = $this->client->request('V4-08');
        $this->policyMapper->authorize($connection, $policy);
        if ($policy->policy_revision !== $work->policy_revision) {
            throw new CmsException('policy_changed');
        }
        $source = null;
        $path = null;
        if ($work->action === 'publish_revision') {
            $key = hash('sha256', $work->source_id."\0".$work->source_revision);
            if (! isset($state['sources'][$key])) {
                throw new CmsException('revision_unavailable');
            }
            $source = $this->client->codec()->parseJson($state['sources'][$key], 262144);
            $this->client->codec()->validate('Source', $source);
            $path = $continuation->content($source, function () use (&$lease, &$state, $journal) {
                if (\Carbon\CarbonImmutable::now('UTC')->greaterThanOrEqualTo(\Carbon\CarbonImmutable::parse($lease->expires_at)->subSeconds(90))) {
                    $lease = $this->renew($state, $journal, $lease, 'source-content');
                }
            });
        }
        $lease = $this->renew($state, $journal, $lease, 'before-native');
        $status = $this->client->request('V4-14', ['work_id' => $work->work_id]);
        $this->current($lease, $status);
        if ($source !== null) {
            // Refuse known faithful-rendering loss before even starting a local
            // static transaction. The writer repeats this under its native fence.
            app(\CodeWorksLabs\DiscussionBridgeStatamic\Publication\NativePublication::class)
                ->sourceTopicUrl($source->topic_url, (int) $source->topic_id);
            app(\CodeWorksLabs\DiscussionBridgeStatamic\Presentation\HtmlSanitizer::class)
                ->sanitizeArticle(file_get_contents($path), $source->topic_url);
        }
        $result = $policy->publication_kind === 'static' ? $this->static->begin($state, $status->mapping, $lease) : null;
        $result ??= $this->writer->apply($state, $connection, $policy, $status->mapping, $lease, $source, $path);
        if ($policy->publication_kind === 'static') {
            $heartbeat = function () use (&$lease, &$state, $journal) {
                if (\Carbon\CarbonImmutable::now('UTC')->greaterThanOrEqualTo(\Carbon\CarbonImmutable::parse($lease->expires_at))) {
                    throw new CmsException('lease_expired');
                }
                if (\Carbon\CarbonImmutable::now('UTC')->greaterThanOrEqualTo(\Carbon\CarbonImmutable::parse($lease->expires_at)->subSeconds(90))) {
                    $lease = $this->renew($state, $journal, $lease, 'static-native');
                }
                $this->current($lease, $this->client->request('V4-14', ['work_id' => $lease->work->work_id]));
            };
            $this->static->perform($state, $journal, $lease, $status->mapping, $source, $result, $heartbeat);
            return;
        }
        $ack = $journal->mutate($state, 'sync:'.$work->work_id.':'.$lease->lease_generation, 'V4-16', ['work_id' => $work->work_id],
            (object) ['stage' => 'synchronized', 'authority' => $this->authority($lease), 'native_result' => $result]);
        $this->current($lease, $ack);
        if (! is_string($ack->mapping->synchronized_at) || $ack->mapping->destination_native_id !== $result->native_id
            || $work->action === 'publish_revision' && $ack->mapping->synchronized_source_revision !== $work->source_revision) {
            throw new CmsException('identity_conflict');
        }
        $lease = $this->renew($state, $journal, $lease, 'before-public');
        $binding = $state['mappings'][$mapping->mapping_id];
        $entry = Entry::find($result->native_id);
        if (! $entry || $this->writer->revision($entry) !== $binding['native_revision']) {
            throw new CmsException('native_ownership_conflict');
        }
        $expected = $work->action === 'publish_revision' ? $this->fragments->expected($entry) : [];
        $verifyKey = 'verify:'.$work->work_id.':'.$lease->lease_generation;
        if (isset($state['mutations'][$verifyKey])) {
            $prior = $this->client->codec()->parseJson($state['mutations'][$verifyKey]['body']);
            $proof = $prior->verification;
            if ($work->action === 'publish_revision' && $proof->native_publication_sha256 !== $this->fragments->sha256($expected)) {
                throw new CmsException('native_ownership_conflict');
            }
        } else {
            $proof = $this->verifier->verify($mapping, $source, $result, $expected, $ack->mapping->synchronized_at, $lease->server_time);
        }
        if ($this->writer->revision($entry) !== $binding['native_revision']) {
            throw new CmsException('native_ownership_conflict');
        }
        $verified = $journal->mutate($state, $verifyKey, 'V4-16', ['work_id' => $work->work_id],
            (object) ['stage' => 'verified', 'authority' => $this->authority($lease), 'verification' => $proof]);
        if ($verified->work->state !== 'verified' || $verified->mapping->destination_native_id !== $result->native_id
            || $work->action === 'publish_revision' && $verified->mapping->publicly_verified_source_revision !== $work->source_revision
            || $work->action === 'withdraw' && $verified->mapping->mapping_state !== 'withdrawn'
            || $verified->mapping->publicly_verified_at !== $proof->checked_at) {
            throw new CmsException('identity_conflict');
        }
        $this->identity($lease, $verified);
        $state['mappings'][$mapping->mapping_id]['last_verified'] = $this->client->codec()->encodeJson($proof);
        $this->store->save($state);
    }

    private function renew(array &$state, ReplayJournal $journal, stdClass $lease, string $stage): stdClass
    {
        $base = 'renew:'.$lease->work->work_id.':'.$lease->lease_generation.':'.$stage;
        $index = $state['renew_indexes'][$base] ?? 0;
        if (isset($state['mutations'][$base.':'.$index]) && $state['mutations'][$base.':'.$index]['result'] !== null) {
            $index++;
        }
        $state['renew_indexes'][$base] = $index;
        $this->store->save($state);
        $renewed = $journal->mutate($state, $base.':'.$index,
            'V4-15', ['work_id' => $lease->work->work_id], (object) ['authority' => $this->authority($lease)]);
        if ($renewed->lease_token !== $lease->lease_token || $renewed->lease_generation !== $lease->lease_generation
            || $renewed->work->work_id !== $lease->work->work_id || $renewed->work->attempt !== $lease->work->attempt) {
            throw new CmsException('lease_conflict');
        }
        foreach (['mapping_id', 'source_id', 'source_revision', 'policy_revision', 'action'] as $field) {
            if ($renewed->work->$field !== $lease->work->$field) {
                throw new CmsException('work_superseded');
            }
        }
        return $renewed;
    }

    private function current(stdClass $lease, stdClass $status): void
    {
        $this->identity($lease, $status);
        if ($lease->lease_generation !== $status->work->lease_generation || $lease->lease_generation !== $lease->work->lease_generation
            || ! in_array($status->work->state, config('discussionbridge.cms_profile') === 'statamic_ssg'
                ? ['leased', 'synchronized', 'deployed'] : ['leased', 'synchronized'], true)) {
            throw new CmsException('lease_conflict');
        }
    }

    private function identity(stdClass $lease, stdClass $status): void
    {
        foreach (['work_id', 'mapping_id', 'source_id', 'source_revision', 'policy_revision', 'action'] as $field) {
            if ($lease->work->$field !== $status->work->$field) {
                throw new CmsException('work_superseded');
            }
        }
        if ($status->mapping->mapping_id !== $lease->work->mapping_id || $status->mapping->source_id !== $lease->work->source_id) {
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
