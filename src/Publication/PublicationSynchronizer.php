<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Publication;

use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\HtmlSanitizer;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Version;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Throwable;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeRequestException;
use Carbon\CarbonImmutable;

class PublicationSynchronizer
{
    public function __construct(
        private readonly BridgeClient $client,
        private readonly NativePublication $validator,
        private readonly HtmlSanitizer $sanitizer,
        private readonly PublicationFreshness $freshness,
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
                    for ($attempt = 0; $attempt < 3; $attempt++) {
                        $publication = is_array($record) ? $this->validator->fromRecord($record) : throw new RuntimeException('DiscussionBridge publication record is invalid.');
                        if ($publication === null) {
                            $summary['skipped']++;
                            break;
                        }
                        try {
                            $this->materialize($publication, $summary, $record);
                            break;
                        } catch (BridgeRequestException $error) {
                            if ($error->status !== 409 || $attempt === 2) {
                                throw $error;
                            }
                            $refreshed = $this->client->record($resourceId)['bridge_record'] ?? null;
                            if (! is_array($refreshed) || ($refreshed['resource_id'] ?? null) !== $resourceId) {
                                throw new RuntimeException('DiscussionBridge refreshed source identity is invalid.');
                            }
                            $record = $refreshed;
                        }
                    }
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

    /** @param array<string, mixed> $publication */
    private function materialize(array $publication, array &$summary, array $record): void
    {
        // A SQL transaction cannot roll back Statamic's Flat native files.
        // Compensate existing entries only through the proven built-in Flat
        // repository. Other stores need their own qualified boundary/journal.
        $flat = Entry::getFacadeRoot() instanceof \Statamic\Stache\Repositories\EntryRepository;
        $prior = $flat ? DB::table('discussionbridge_publications')->where('resource_id', $publication['resource_id'])->first() : null;
        $existing = $prior ? Entry::find($prior->entry_id) : null;
        $snapshot = $existing ? clone $existing : null;
        $native = null;
        $before = $summary;
        try {
            DB::transaction(function () use ($publication, &$summary, $record, &$native): void {
                $this->applyPublication($publication, $summary, $record, $native);
            });
        } catch (Throwable $error) {
            $summary = $before;
            if ($snapshot && $native) {
                try {
                    $current = Entry::find($snapshot->id());
                    if (! $current || $current->path() !== $snapshot->path()
                        || $current->get('discussionbridge_resource_id') !== $publication['resource_id']
                        || $current->get('discussionbridge_topic_id') !== $publication['topic_id']
                        || $current->data()->all() !== $native->data()->all() || $current->published() !== $native->published()) {
                        throw new RuntimeException('The Flat native entry changed during compensation. Preserve it for reconciliation.');
                    }
                    if ($snapshot->save() === false) {
                        throw new RuntimeException('Native Statamic entry restoration was refused.');
                    }
                    $this->freshness->refresh($snapshot, $publication['resource_id']);
                } catch (Throwable $rollback) {
                    throw new RuntimeException('Statamic native_rollback_incomplete: preserve the entry and reconcile before retry.', 0, $rollback);
                }
            }
            throw $error;
        }
    }

    private function applyPublication(array $publication, array &$summary, array $record, &$native): void
    {
        $prior = DB::table('discussionbridge_publications')->where('resource_id', $publication['resource_id'])->first();
        $freshness = $publication['source_freshness'] ?? $publication['source_revision'];
        if (($publication['source_status'] ?? 'active') === 'withdrawn') {
            if (! $prior) {
                $summary['skipped']++;
                return;
            }
            $entry = Entry::find($prior->entry_id);
            $this->assertOwned($entry, $publication, $prior);
            if ($entry->get('discussionbridge_source_status') === 'withdrawn' && $prior->source_revision === $freshness && $entry->published() === false) {
                $summary['unchanged']++;
                return;
            }
            if ($entry->get('discussionbridge_source_status') !== 'withdrawn') {
                $entry->set('discussionbridge_prior_published', $entry->published());
            }
            $native = $entry;
            $entry->set('discussionbridge_source_status', 'withdrawn')->set('discussionbridge_source_edition', $freshness)->set('discussionbridge_synchronized_at', now()->toIso8601String());
            if ($entry->published(false)->save() === false) {
                throw new RuntimeException('Statamic native publication withdrawal was refused.');
            }
            $this->freshness->refresh($entry, $publication['resource_id']);
            DB::table('discussionbridge_publications')->where('resource_id', $publication['resource_id'])->update(['source_revision' => $freshness, 'updated_at' => now()]);
            $summary['updated']++;
            return;
        }
        if ($prior && $prior->source_revision === $freshness && $prior->canonical_url === $publication['canonical_url']) {
            $existing = Entry::find($prior->entry_id);
            $this->assertOwned($existing, $publication, $prior);
            $this->freshness->refresh($existing, $publication['resource_id']);
            $summary['unchanged']++;
            return;
        }

        $site = Site::default()->handle();
        $expectedUri = '/discussionbridge/'.$publication['slug'];
        $entry = $prior ? Entry::find($prior->entry_id) : Entry::findByUri($expectedUri, $site);
        $newNativeEntry = ! $entry;
        if ($entry) {
            $this->assertOwned($entry, $publication, $prior);
        } else {
            $collection = Collection::findByHandle('discussionbridge');
            if ($collection) {
                $entry = Entry::make()->collection($collection)->locale($site)->slug($publication['slug'])->published(true);
            } else {
                $parent = Entry::findByUri('/discussionbridge', $site);
                if (! $parent) {
                    throw new RuntimeException('Statamic DiscussionBridge destination is unavailable.');
                }
                $entry = Entry::make()->collection('pages')->locale($site)->slug($publication['slug'])->published(true);
                $entry->afterSave(function ($saved) use ($parent, $site): void {
                    $structure = $saved->collection()->structure();
                    if (! $structure) {
                        throw new RuntimeException('Statamic pages structure is unavailable.');
                    }
                    $tree = $structure->in($site);
                    $tree->appendTo($parent->id(), $saved);
                    $tree->save();
                });
            }
        }

        if ($publication['content_html'] === null) {
            $publication['content_html'] = $this->client->publicationContent($record);
        }
        $content = $this->sanitizer->sanitize($publication['content_html'], isset($record['source']['content']) ? null : 65536);
        if ($content === '') {
            throw new RuntimeException('Statamic publication content is empty after sanitization.');
        }
        $forumName = $publication['forum_name'] ?? $this->configurationForumName();
        $updated = $publication['source_updated_at'] ?? null;
        $content .= '<hr><aside class="discussionbridge-publication"><p><strong>Published from <a href="'.htmlspecialchars($publication['topic_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.htmlspecialchars($forumName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</a></strong></p><p>Source author: '.htmlspecialchars($publication['source_author'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').($updated === null ? '' : ' · Updated <time datetime="'.htmlspecialchars($updated, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.htmlspecialchars($updated, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</time>').' · Revision '.htmlspecialchars($publication['source_revision'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' · Statamic 6 · DiscussionBridge for Statamic '.Version::VALUE.'</p></aside>';
        // Updating an existing publication must not silently disable its renderer.
        // Fresh entries have no prior markup and remain opt-in.
        $priorContent = $entry->get('content');
        if (config('discussionbridge.rich_content') === null && is_string($priorContent)) {
            $content .= PresentationAssets::nativeMarkup($priorContent);
        }
        $data = $entry->data()->all();
        if (array_key_exists('discussionbridge_mode', $data) && $data['discussionbridge_mode'] !== null && ! in_array($data['discussionbridge_mode'], ['simple', 'full', 'interactive'], true)) {
            throw new RuntimeException('Existing Statamic presentation choice is invalid.');
        }
        if (! $prior && ! array_key_exists('discussionbridge_mode', $data) && ($publication['presentation_mode'] ?? null) !== null) {
            $data['discussionbridge_mode'] = $publication['presentation_mode'];
        }
        $published = ($data['discussionbridge_source_status'] ?? null) === 'withdrawn' ? ($data['discussionbridge_prior_published'] ?? true) : ($prior ? $entry->published() : true);
        unset($data['discussionbridge_prior_published']);
        $entry->data(array_merge($data, [
            'title' => $publication['title'],
            'content' => $content,
            'discussionbridge_native_publication' => true,
            'discussionbridge_resource_id' => $publication['resource_id'],
            'discussionbridge_topic_id' => $publication['topic_id'],
            'discussionbridge_topic_url' => $publication['topic_url'],
            'discussionbridge_source_revision' => $publication['source_revision'],
            'discussionbridge_source_edition' => $publication['source_freshness'] ?? null,
            'discussionbridge_source_status' => 'active',
            'discussionbridge_source_created_at' => $publication['source_created_at'] ?? null,
            'discussionbridge_source_updated_at' => $updated,
            'discussionbridge_forum_name' => $forumName,
            'discussionbridge_synchronized_at' => now()->toIso8601String(),
            'discussionbridge_complete_content' => isset($record['source']['content']),
            'discussionbridge_publish' => false,
            ...($newNativeEntry || isset($data['discussionbridge_managed_content_sha256'])
                ? ['discussionbridge_managed_content_sha256' => hash('sha256', $content)] : []),
        ]));
        if (($publication['source_created_at'] ?? null) !== null && $entry->collection()->dated()) {
            $entry->date(CarbonImmutable::parse($publication['source_created_at']));
        }
        $native = $entry;
        if ($entry->published($published)->save() === false) {
            throw new RuntimeException('Statamic native publication save was refused.');
        }
        if ($entry->uri() !== $expectedUri) {
            throw new RuntimeException('Statamic publication URL is not canonical.');
        }

        // EntrySaved normally performs this work. Do it synchronously as well because
        // publication synchronization is often run from a separate CLI process.
        $this->freshness->refresh($entry, $publication['resource_id']);

        DB::table('discussionbridge_publications')->updateOrInsert(
            ['resource_id' => $publication['resource_id']],
            ['entry_id' => (string) $entry->id(), 'canonical_url' => $publication['canonical_url'], 'canonical_url_digest' => hash('sha256', $publication['canonical_url']), 'source_revision' => $freshness, 'topic_id' => $publication['topic_id'], 'topic_url' => $publication['topic_url'], 'created_at' => $prior?->created_at ?? now(), 'updated_at' => now()],
        );

        $summary[$prior ? 'updated' : 'created']++;
    }

    private function assertOwned($entry, array $publication, ?object $prior): void
    {
        if (! $entry || $entry->uri() !== '/discussionbridge/'.$publication['slug'] || $entry->get('discussionbridge_native_publication') !== true || $entry->get('discussionbridge_resource_id') !== $publication['resource_id'] || $entry->get('discussionbridge_topic_id') !== $publication['topic_id'] || ($prior && ((string) $entry->id() !== $prior->entry_id || $prior->canonical_url !== $publication['canonical_url']))) {
            throw new RuntimeException('Statamic publication identity collision or URL drift.');
        }
        $receipt = $entry->get('discussionbridge_managed_content_sha256');
        if ($receipt !== null && (! is_string($receipt) || ! preg_match('/\A[0-9a-f]{64}\z/', $receipt)
            || ! is_string($entry->get('content')) || ! hash_equals($receipt, hash('sha256', $entry->get('content'))))) {
            throw new RuntimeException('Statamic native_content_modified: preserve the local body and reconcile before synchronization.');
        }
    }

    private function configurationForumName(): string
    {
        return app(\CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration::class)->forumOrigin();
    }
}
