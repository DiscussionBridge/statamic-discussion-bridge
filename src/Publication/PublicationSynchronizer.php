<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Publication;

use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\HtmlSanitizer;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Version;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Structures\Page;
use Throwable;

class PublicationSynchronizer
{
    public function __construct(
        private readonly BridgeClient $client,
        private readonly NativePublication $validator,
        private readonly HtmlSanitizer $sanitizer,
        private readonly PublicationFreshness $freshness,
        private readonly Configuration $configuration,
    ) {
    }

    /** @return array{created:int,updated:int,unchanged:int,skipped:int,failed:int,errors:list<string>} */
    public function synchronize(): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $page = 1;
        $snapshot = null;
        $expectedPages = null;
        $expectedTotal = null;
        $seenResources = [];

        do {
            $response = $this->client->records($page, $snapshot);
            $records = $response['bridge_records'] ?? null;
            $pagination = $response['pagination'] ?? null;
            $reportedSnapshot = $pagination['snapshot'] ?? null;
            $reportedTotal = $pagination['total'] ?? null;
            if (! is_array($records) || ! is_array($pagination) || ($pagination['page'] ?? null) !== $page || ! is_int($pagination['pages'] ?? null) || $pagination['pages'] < 1 || $pagination['pages'] > 10000 || ! is_int($reportedTotal) || $reportedTotal < 0 || ! is_string($reportedSnapshot) || $reportedSnapshot === '' || strlen($reportedSnapshot) > 8192 || ($expectedPages !== null && $pagination['pages'] !== $expectedPages) || ($expectedTotal !== null && $reportedTotal !== $expectedTotal) || ($snapshot !== null && $reportedSnapshot !== $snapshot)) {
                throw new RuntimeException('DiscussionBridge publication feed is invalid.');
            }

            $snapshot ??= $reportedSnapshot;
            $expectedPages ??= $pagination['pages'];
            $expectedTotal ??= $reportedTotal;

            foreach ($records as $record) {
                $resourceId = is_array($record) ? strtolower((string) ($record['resource_id'] ?? '')) : '';
                if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $resourceId) || isset($seenResources[$resourceId])) {
                    throw new RuntimeException('DiscussionBridge publication feed contains a duplicate or invalid resource identity.');
                }
                $seenResources[$resourceId] = true;

                try {
                    $publication = is_array($record) ? $this->validator->fromRecord($record) : throw new RuntimeException('DiscussionBridge publication record is invalid.');
                    if ($publication === null) {
                        $summary['skipped']++;
                        continue;
                    }
                    $this->materialize($publication, $summary);
                } catch (Throwable $error) {
                    $summary['failed']++;
                    if (count($summary['errors']) < 10) {
                        $summary['errors'][] = substr($error->getMessage(), 0, 200);
                    }
                }
            }

            $page++;
        } while ($page <= $pagination['pages']);

        if (count($seenResources) !== $expectedTotal) {
            throw new RuntimeException('DiscussionBridge publication feed did not produce its complete unique census.');
        }

        return $summary;
    }

    /** @return array{created:int,updated:int,unchanged:int,held:int,unpublished:int,failed:int,errors:list<string>} */
    public function synchronizeQueued(int $maximum = 20, int $leaseSeconds = 300): array
    {
        if ($maximum < 1 || $maximum > 20 || $leaseSeconds < 300 || $leaseSeconds > 3600) {
            throw new RuntimeException('DiscussionBridge publication work bounds are invalid.');
        }
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'held' => 0, 'unpublished' => 0, 'failed' => 0, 'errors' => []];
        for ($index = 0; $index < $maximum; $index++) {
            $response = $this->client->claimPublicationWork($leaseSeconds);
            $work = $response['publication_work'] ?? null;
            if ($work === null) {
                break;
            }
            try {
                if (($work['action'] ?? null) === 'publish') {
                    $this->materializeClaimedTopic($work, $summary);
                } else {
                    $this->unpublishClaimedTopic($work, $summary);
                }
            } catch (Throwable $error) {
                $detail = substr(preg_replace('/[\x00-\x1f\x7f]/', ' ', $error->getMessage()), 0, 1000);
                try {
                    $this->client->failPublicationWork('statamic_delivery_failed', $detail);
                } catch (Throwable $reportError) {
                    if (count($summary['errors']) < 10) {
                        $summary['errors'][] = substr($reportError->getMessage(), 0, 200);
                    }
                }
                $summary['failed']++;
                if (count($summary['errors']) < 10) {
                    $summary['errors'][] = substr($detail, 0, 200);
                }
            } finally {
                $this->client->clearPublicationLease();
            }
        }

        return $summary;
    }

    /** @param array<string, mixed> $work
     *  @return array<string, mixed>
     */
    public function prepareClaimedStatic(array $work, ?callable $beforeMutation = null): array
    {
        $topicId = $work['topic_id'] ?? null;
        $leaseToken = $work['lease_token'] ?? null;
        if (! is_int($topicId) || $topicId < 1 || ! is_string($leaseToken)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $leaseToken)) {
            throw new RuntimeException('DiscussionBridge claimed Statamic topic is invalid.');
        }
        $this->client->resumePublicationLease($leaseToken);
        $response = $this->client->sourceTopic($topicId);
        $topic = ($response['eligible'] ?? null) === true ? ($response['source_topic'] ?? null) : null;
        if (! is_array($topic)
            || ($topic['source_revision'] ?? null) !== ($work['source_revision'] ?? null)
            || ($topic['publication_revision'] ?? null) !== ($work['publication_revision'] ?? null)) {
            throw new RuntimeException('DiscussionBridge claimed Statamic source revision changed.');
        }
        $publication = $this->validator->fromSourceTopic($topic);
        $externalId = 'statamic:topic:'.$topicId;
        $request = [
            'source_revision' => $publication['source_revision'],
            'publication_revision' => $publication['publication_revision'],
            'mapping_revision' => $publication['mapping_revision'],
            'destination' => $publication['destination'],
            'external_id' => $externalId,
            'canonical_url' => $publication['canonical_url'],
            'native_materialization' => true,
        ];
        if (($lane = $this->configuration->lane()) !== null) {
            $request['lane'] = $lane;
        }
        $resolved = $this->client->resolveSourceTopic($topicId, $request);
        $resourceId = strtolower((string) ($resolved['resource_id'] ?? ''));
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $resourceId)
            || ! in_array($resolved['outcome'] ?? null, ['created', 'resolved'], true)
            || ($resolved['external_id'] ?? null) !== $externalId
            || ($resolved['canonical_url'] ?? null) !== $publication['canonical_url']) {
            throw new RuntimeException('DiscussionBridge Statamic source-topic resolve response is invalid.');
        }
        $publication['resource_id'] = $resourceId;
        $prepared = [
            'action' => 'publish',
            'topic_id' => $topicId,
            'lease_token' => $leaseToken,
            'lease_expires_at' => $work['lease_expires_at'] ?? null,
            'resource_id' => $resourceId,
            'external_id' => $externalId,
            'canonical_url' => $publication['canonical_url'],
            'source_revision' => $publication['source_revision'],
            'publication_revision' => $publication['publication_revision'],
            'mapping_revision' => $publication['mapping_revision'],
            'destination' => $publication['destination'],
            'publication' => $publication,
            'snapshot' => $this->capturePublicationState($publication),
            'phase' => 'applying',
        ];
        if ($beforeMutation !== null) {
            $beforeMutation($prepared);
        }
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $prepared['outcome'] = $this->materialize($publication, $summary);
        $prepared['phase'] = 'prepared';

        return $prepared;
    }

    /** @param array<string, mixed> $work
     *  @return array<string, mixed>
     */
    public function prepareClaimedStaticUnpublish(array $work, ?callable $beforeMutation = null): array
    {
        $resourceId = strtolower((string) ($work['resource_id'] ?? ''));
        $leaseToken = $work['lease_token'] ?? null;
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $resourceId)
            || ! is_string($leaseToken) || ! preg_match('/\A[a-f0-9]{64}\z/', $leaseToken)) {
            throw new RuntimeException('DiscussionBridge claimed Statamic withdrawal is invalid.');
        }
        $this->client->resumePublicationLease($leaseToken);
        $response = $this->client->sourceRevocation($resourceId);
        $revocation = ($response['revoked'] ?? null) === true ? ($response['publication_revocation'] ?? null) : null;
        if (! is_array($revocation)
            || ($revocation['topic_id'] ?? null) !== ($work['topic_id'] ?? null)
            || ($revocation['publication_revision'] ?? null) !== ($work['publication_revision'] ?? null)) {
            throw new RuntimeException('DiscussionBridge claimed Statamic withdrawal changed.');
        }
        $prior = DB::table('discussionbridge_publications')->where('resource_id', $resourceId)->first();
        if (! $prior || ! ($entry = Entry::find($prior->entry_id))
            || $entry->get('discussionbridge_resource_id') !== $resourceId) {
            throw new RuntimeException('DiscussionBridge Statamic publication for withdrawal is unavailable.');
        }
        $prepared = [
            'action' => 'unpublish',
            'topic_id' => $work['topic_id'],
            'lease_token' => $leaseToken,
            'lease_expires_at' => $work['lease_expires_at'] ?? null,
            'resource_id' => $resourceId,
            'external_id' => 'statamic:topic:'.$work['topic_id'],
            'canonical_url' => $prior->canonical_url,
            'publication_revision' => $revocation['publication_revision'],
            'entry_id' => (string) $entry->id(),
            'was_published' => $entry->published(),
            'phase' => 'applying',
        ];
        if ($beforeMutation !== null) {
            $beforeMutation($prepared);
        }
        if ($entry->published()) {
            $entry->published(false)->save();
        }
        $prepared['outcome'] = 'unpublished';
        $prepared['phase'] = 'prepared';

        return $prepared;
    }

    /** @param array<string, mixed> $prepared */
    public function acknowledgePreparedStatic(array $prepared): array
    {
        $this->client->resumePublicationLease((string) ($prepared['lease_token'] ?? ''));
        $acknowledgement = [
            'publication_revision' => $prepared['publication_revision'],
            'native_destination' => [
                'external_id' => $prepared['external_id'],
                'canonical_url' => $prepared['canonical_url'],
            ],
            'outcome' => $prepared['outcome'],
        ];
        if (($prepared['action'] ?? null) === 'publish') {
            $acknowledgement += [
                'source_revision' => $prepared['source_revision'],
                'mapping_revision' => $prepared['mapping_revision'],
                'destination' => $prepared['destination'],
            ];
        }
        $response = $this->client->acknowledgePublication($prepared['resource_id'], $acknowledgement);
        if (($response['resource_id'] ?? null) !== $prepared['resource_id']) {
            throw new RuntimeException('DiscussionBridge Statamic acknowledgement is invalid.');
        }

        return $response;
    }

    /** @param array<string, mixed> $prepared */
    public function restorePreparedStatic(array $prepared): bool
    {
        if (($prepared['action'] ?? null) === 'unpublish') {
            $entry = Entry::find((string) ($prepared['entry_id'] ?? ''));
            if (! $entry) {
                return false;
            }
            $entry->published((bool) ($prepared['was_published'] ?? false))->save();

            return $entry->published() === (bool) ($prepared['was_published'] ?? false);
        }
        $publication = $prepared['publication'] ?? null;
        $snapshot = $prepared['snapshot'] ?? null;

        return is_array($publication) && is_array($snapshot)
            && $this->restorePublicationState($publication, $snapshot);
    }

    /** @param array<string, mixed> $publication */
    private function materialize(array $publication, array &$summary): string
    {
        $prior = DB::table('discussionbridge_publications')->where('resource_id', $publication['resource_id'])->first();
        if ($prior && $prior->canonical_url !== $publication['canonical_url']
            && (($publication['url_migration']['old_url'] ?? null) !== $prior->canonical_url
                || ($publication['url_migration']['new_url'] ?? null) !== $publication['canonical_url'])) {
            throw new RuntimeException('Statamic publication URL change requires a verified migration and the same entry.');
        }
        if ($prior && $prior->source_revision === $publication['source_revision'] && $prior->canonical_url === $publication['canonical_url']) {
            $existing = Entry::find($prior->entry_id);
            if (! $existing || $existing->get('discussionbridge_resource_id') !== $publication['resource_id']) {
                throw new RuntimeException('Statamic publication state is incomplete.');
            }
            $this->freshness->refresh($existing, $publication['resource_id']);
            $summary['unchanged']++;
            return 'unchanged';
        }

        $site = Site::default()->handle();
        $expectedUri = $publication['path'];
        $entry = $prior ? Entry::find($prior->entry_id) : $this->entryByUri($expectedUri, $site);
        if ($entry) {
            if ($entry->uri() !== $expectedUri || $entry->get('discussionbridge_resource_id') !== $publication['resource_id'] || ($prior && (string) $entry->id() !== $prior->entry_id)) {
                throw new RuntimeException('Statamic publication identity collision.');
            }
        } else {
            $parent = $publication['parent_uri'] ? Entry::findByUri($publication['parent_uri'], $site) : null;
            if ($publication['parent_uri'] && ! $parent) {
                throw new RuntimeException('Statamic publication parent destination is unavailable.');
            }
            $entry = Entry::make()->collection($publication['collection'] ?? 'pages')->locale($site)->slug($publication['slug'])->published(true);
            $entry->afterSave(function ($saved) use ($parent, $site): void {
                $structure = $saved->collection()->structure();
                if (! $structure) {
                    throw new RuntimeException('Statamic pages structure is unavailable.');
                }
                $tree = $structure->in($site);
                if ($parent) {
                    $tree->appendTo($parent->id(), $saved);
                } else {
                    $tree->append($saved);
                }
                $tree->save();
            });
        }

        $content = $this->sanitizer->sanitize($publication['content_html']);
        if ($content === '') {
            throw new RuntimeException('Statamic publication content is empty after sanitization.');
        }
        $content = '<span hidden data-discussionbridge-resource-id="'.htmlspecialchars($publication['resource_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" data-discussionbridge-publication-revision="'.htmlspecialchars($publication['publication_revision'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"></span>'.$content;
        $content .= '<hr><aside class="discussionbridge-publication"><p><strong>Published from <a href="'.htmlspecialchars($publication['topic_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">The Bridge</a></strong></p><p>Source author: '.htmlspecialchars($publication['source_author'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' · Revision '.htmlspecialchars($publication['source_revision'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' · Statamic 6 · DiscussionBridge for Statamic '.Version::VALUE.'</p></aside><script src="/discussionbridge/rich-content.js" defer></script>';
        $entry->data(array_merge($entry->data()->all(), [
            'title' => $publication['title'],
            'content' => $content,
            'discussionbridge_native_publication' => true,
            'discussionbridge_resource_id' => $publication['resource_id'],
            'discussionbridge_topic_id' => $publication['topic_id'],
            'discussionbridge_topic_url' => $publication['topic_url'],
            'discussionbridge_source_revision' => $publication['source_revision'],
            ...(isset($publication['source_created_at']) ? ['date' => $publication['source_created_at']] : []),
            ...(isset($publication['source_updated_at']) ? ['discussionbridge_source_updated_at' => $publication['source_updated_at']] : []),
            ...(isset($publication['destination_author_id']) ? ['author' => $publication['destination_author_id']] : []),
            ...(isset($publication['destination_taxonomies']) ? $publication['destination_taxonomies'] : []),
            'discussionbridge_publish' => false,
        ]));
        $entry->published(true)->save();
        if ($entry->uri() !== $expectedUri) {
            throw new RuntimeException('Statamic publication URL is not canonical.');
        }

        // EntrySaved normally performs this work. Do it synchronously as well because
        // publication synchronization is often run from a separate CLI process.
        $this->freshness->refresh($entry, $publication['resource_id']);

        DB::table('discussionbridge_publications')->updateOrInsert(
            ['resource_id' => $publication['resource_id']],
            ['entry_id' => (string) $entry->id(), 'canonical_url' => $publication['canonical_url'], 'canonical_url_digest' => hash('sha256', $publication['canonical_url']), 'source_revision' => $publication['source_revision'], 'topic_id' => $publication['topic_id'], 'topic_url' => $publication['topic_url'], 'created_at' => $prior?->created_at ?? now(), 'updated_at' => now()],
        );

        $outcome = $prior ? 'updated' : 'created';
        $summary[$outcome]++;

        return $outcome;
    }

    /** @param array<string, mixed> $work */
    private function materializeClaimedTopic(array $work, array &$summary): void
    {
        $topicId = $work['topic_id'] ?? null;
        if (! is_int($topicId) || $topicId < 1) {
            throw new RuntimeException('DiscussionBridge claimed Statamic topic is invalid.');
        }
        $response = $this->client->sourceTopic($topicId);
        $topic = ($response['eligible'] ?? null) === true ? ($response['source_topic'] ?? null) : null;
        if (! is_array($topic)
            || ($topic['source_revision'] ?? null) !== ($work['source_revision'] ?? null)
            || ($topic['publication_revision'] ?? null) !== ($work['publication_revision'] ?? null)) {
            throw new RuntimeException('DiscussionBridge claimed Statamic source revision changed.');
        }
        $publication = $this->validator->fromSourceTopic($topic);
        $externalId = 'statamic:topic:'.$topicId;
        $request = [
            'source_revision' => $publication['source_revision'],
            'publication_revision' => $publication['publication_revision'],
            'mapping_revision' => $publication['mapping_revision'],
            'destination' => $publication['destination'],
            'external_id' => $externalId,
            'canonical_url' => $publication['canonical_url'],
            'native_materialization' => true,
        ];
        if (($lane = $this->configuration->lane()) !== null) {
            $request['lane'] = $lane;
        }
        $resolved = $this->client->resolveSourceTopic($topicId, $request);
        $resourceId = strtolower((string) ($resolved['resource_id'] ?? ''));
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $resourceId)
            || ! in_array($resolved['outcome'] ?? null, ['created', 'resolved'], true)
            || ($resolved['external_id'] ?? null) !== $externalId
            || ($resolved['canonical_url'] ?? null) !== $publication['canonical_url']) {
            throw new RuntimeException('DiscussionBridge Statamic source-topic resolve response is invalid.');
        }
        $publication['resource_id'] = $resourceId;
        $snapshot = $this->capturePublicationState($publication);
        $outcome = null;
        try {
            $outcome = $this->materialize($publication, $summary);
            $acknowledgement = $this->client->acknowledgePublication($resourceId, [
                'source_revision' => $publication['source_revision'],
                'publication_revision' => $publication['publication_revision'],
                'mapping_revision' => $publication['mapping_revision'],
                'destination' => $publication['destination'],
                'native_destination' => [
                    'external_id' => $externalId,
                    'canonical_url' => $publication['canonical_url'],
                ],
                'outcome' => $outcome,
            ]);
            if (($acknowledgement['resource_id'] ?? null) !== $resourceId
                || ($acknowledgement['acknowledged_publication_revision'] ?? null) !== $publication['publication_revision']) {
                throw new RuntimeException('DiscussionBridge Statamic acknowledgement is invalid.');
            }
        } catch (Throwable $error) {
            if (! $this->restorePublicationState($publication, $snapshot)) {
                throw new RuntimeException('DiscussionBridge Statamic publication rollback failed.', 0, $error);
            }
            if (is_string($outcome) && isset($summary[$outcome]) && $summary[$outcome] > 0) {
                $summary[$outcome]--;
            }
            throw $error;
        }
    }

    /** @param array<string, mixed> $work */
    private function unpublishClaimedTopic(array $work, array &$summary): void
    {
        $resourceId = strtolower((string) ($work['resource_id'] ?? ''));
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $resourceId)) {
            throw new RuntimeException('DiscussionBridge claimed Statamic withdrawal is invalid.');
        }
        $response = $this->client->sourceRevocation($resourceId);
        $revocation = ($response['revoked'] ?? null) === true ? ($response['publication_revocation'] ?? null) : null;
        if (! is_array($revocation)
            || ($revocation['topic_id'] ?? null) !== ($work['topic_id'] ?? null)
            || ($revocation['publication_revision'] ?? null) !== ($work['publication_revision'] ?? null)) {
            throw new RuntimeException('DiscussionBridge claimed Statamic withdrawal changed.');
        }
        $prior = DB::table('discussionbridge_publications')->where('resource_id', $resourceId)->first();
        if (! $prior || ! ($entry = Entry::find($prior->entry_id))
            || $entry->get('discussionbridge_resource_id') !== $resourceId) {
            throw new RuntimeException('DiscussionBridge Statamic publication for withdrawal is unavailable.');
        }
        $wasPublished = $entry->published();
        if ($wasPublished) {
            $entry->published(false)->save();
        }
        $externalId = 'statamic:topic:'.$work['topic_id'];
        try {
            $acknowledgement = $this->client->acknowledgePublication($resourceId, [
                'publication_revision' => $revocation['publication_revision'],
                'native_destination' => [
                    'external_id' => $externalId,
                    'canonical_url' => $prior->canonical_url,
                ],
                'outcome' => 'unpublished',
            ]);
            if (($acknowledgement['resource_id'] ?? null) !== $resourceId) {
                throw new RuntimeException('DiscussionBridge Statamic withdrawal acknowledgement is invalid.');
            }
        } catch (Throwable $error) {
            if ($wasPublished) {
                $entry->published(true)->save();
                if (! $entry->published()) {
                    throw new RuntimeException('DiscussionBridge Statamic withdrawal rollback failed.', 0, $error);
                }
            }
            throw $error;
        }
        $summary['unpublished']++;
    }

    /** @param array<string, mixed> $publication
     *  @return array{row:?array,entry:?array}
     */
    private function capturePublicationState(array $publication): array
    {
        $prior = DB::table('discussionbridge_publications')->where('resource_id', $publication['resource_id'])->first();
        $site = Site::default()->handle();
        $entry = $prior ? Entry::find($prior->entry_id) : $this->entryByUri($publication['path'], $site);

        return [
            'row' => $prior ? (array) $prior : null,
            'entry' => $entry ? [
                'id' => (string) $entry->id(),
                'data' => $entry->data()->all(),
                'published' => $entry->published(),
            ] : null,
        ];
    }

    /** @param array<string, mixed> $publication
     *  @param array{row:?array,entry:?array} $snapshot
     */
    private function restorePublicationState(array $publication, array $snapshot): bool
    {
        $current = DB::table('discussionbridge_publications')->where('resource_id', $publication['resource_id'])->first();
        $currentEntry = $current
            ? Entry::find($current->entry_id)
            : $this->entryByUri($publication['path'], Site::default()->handle());
        if ($snapshot['entry'] === null) {
            if ($currentEntry
                && $currentEntry->get('discussionbridge_resource_id') === $publication['resource_id']) {
                $currentEntry->delete();
            }
        } else {
            $entry = Entry::find($snapshot['entry']['id']);
            if (! $entry) {
                return false;
            }
            $entry->data($snapshot['entry']['data'])
                ->published($snapshot['entry']['published'])
                ->save();
        }

        DB::table('discussionbridge_publications')->where('resource_id', $publication['resource_id'])->delete();
        if ($snapshot['row'] !== null) {
            DB::table('discussionbridge_publications')->insert($snapshot['row']);
        }

        $restored = DB::table('discussionbridge_publications')->where('resource_id', $publication['resource_id'])->first();
        if ($snapshot['row'] === null) {
            return $restored === null
                && (! $currentEntry || Entry::find((string) $currentEntry->id()) === null);
        }
        $entry = Entry::find($snapshot['entry']['id']);

        return $restored !== null
            && (array) $restored === $snapshot['row']
            && $entry !== null
            && $entry->data()->all() === $snapshot['entry']['data']
            && $entry->published() === $snapshot['entry']['published'];
    }

    private function entryByUri(string $uri, string $site): ?\Statamic\Contracts\Entries\Entry
    {
        $entry = Entry::findByUri($uri, $site);

        return $entry instanceof Page ? $entry->entry() : $entry;
    }
}
