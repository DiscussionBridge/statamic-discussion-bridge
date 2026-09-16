<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Publication;

use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\HtmlSanitizer;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Version;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Throwable;

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

    /** @param array<string, mixed> $publication */
    private function materialize(array $publication, array &$summary): void
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
            return;
        }

        $site = Site::default()->handle();
        $expectedUri = $publication['path'];
        $entry = $prior ? Entry::find($prior->entry_id) : Entry::findByUri($expectedUri, $site);
        if ($entry) {
            if ($entry->uri() !== $expectedUri || $entry->get('discussionbridge_resource_id') !== $publication['resource_id'] || ($prior && (string) $entry->id() !== $prior->entry_id)) {
                throw new RuntimeException('Statamic publication identity collision.');
            }
        } else {
            $parent = $publication['parent_uri'] ? Entry::findByUri($publication['parent_uri'], $site) : null;
            if ($publication['parent_uri'] && ! $parent) {
                throw new RuntimeException('Statamic publication parent destination is unavailable.');
            }
            $entry = Entry::make()->collection('pages')->locale($site)->slug($publication['slug'])->published(true);
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
        $content .= '<hr><aside class="discussionbridge-publication"><p><strong>Published from <a href="'.htmlspecialchars($publication['topic_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">The Bridge</a></strong></p><p>Source author: '.htmlspecialchars($publication['source_author'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' · Revision '.htmlspecialchars($publication['source_revision'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' · Statamic 6 · DiscussionBridge for Statamic '.Version::VALUE.'</p></aside><script src="/discussionbridge/rich-content.js" defer></script>';
        $entry->data(array_merge($entry->data()->all(), [
            'title' => $publication['title'],
            'content' => $content,
            'discussionbridge_native_publication' => true,
            'discussionbridge_resource_id' => $publication['resource_id'],
            'discussionbridge_topic_id' => $publication['topic_id'],
            'discussionbridge_topic_url' => $publication['topic_url'],
            'discussionbridge_source_revision' => $publication['source_revision'],
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

        $summary[$prior ? 'updated' : 'created']++;
    }
}
