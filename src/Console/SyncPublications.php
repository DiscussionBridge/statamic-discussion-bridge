<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;

use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\HtmlSanitizer;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\NativePublication;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Statamic\Facades\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Throwable;

class SyncPublications extends Command
{
    protected $signature = 'discussionbridge:sync-publications';

    protected $description = 'Create or update explicitly authorized native Statamic publications';

    public function handle(BridgeClient $client, NativePublication $validator, HtmlSanitizer $sanitizer): int
    {
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];
        $page = 1;
        do {
            $response = $client->records($page);
            $records = $response['bridge_records'] ?? null;
            $pagination = $response['pagination'] ?? null;
            if (! is_array($records) || ! is_array($pagination) || ($pagination['page'] ?? null) !== $page || ! is_int($pagination['pages'] ?? null) || $pagination['pages'] < 1 || $pagination['pages'] > 10000) {
                throw new RuntimeException('DiscussionBridge publication feed is invalid.');
            }
            foreach ($records as $record) {
                try {
                    $publication = is_array($record) ? $validator->fromRecord($record) : throw new RuntimeException('DiscussionBridge publication record is invalid.');
                    if ($publication === null) {
                        $summary['skipped']++;
                        continue;
                    }
                    $this->materialize($publication, $sanitizer, $summary);
                } catch (Throwable $error) {
                    $summary['failed']++;
                    $this->error(substr($error->getMessage(), 0, 200));
                }
            }
            $page++;
        } while ($page <= $pagination['pages']);

        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

        return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function materialize(array $publication, HtmlSanitizer $sanitizer, array &$summary): void
    {
        $prior = DB::table('discussionbridge_publications')->where('resource_id', $publication['resource_id'])->first();
        if ($prior && $prior->source_revision === $publication['source_revision'] && $prior->canonical_url === $publication['canonical_url']) {
            $summary['unchanged']++;
            return;
        }

        $site = Site::default()->handle();
        $expectedUri = '/discussionbridge/'.$publication['slug'];
        $entry = $prior ? Entry::find($prior->entry_id) : Entry::findByUri($expectedUri, $site);
        if ($entry) {
            if ($entry->uri() !== $expectedUri || $entry->get('discussionbridge_resource_id') !== $publication['resource_id'] || ($prior && (string) $entry->id() !== $prior->entry_id)) {
                throw new RuntimeException('Statamic publication identity collision.');
            }
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

        $content = $sanitizer->sanitize($publication['content_html']);
        if ($content === '') {
            throw new RuntimeException('Statamic publication content is empty after sanitization.');
        }
        $content .= '<hr><aside class="discussionbridge-publication"><p><strong>Published from <a href="'.htmlspecialchars($publication['topic_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">The Bridge</a></strong></p><p>Source author: '.htmlspecialchars($publication['source_author'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' · Revision '.htmlspecialchars($publication['source_revision'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' · Statamic 6 · DiscussionBridge for Statamic 0.1.0-alpha.17</p></aside><script src="/discussionbridge/rich-content.js" defer></script>';
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

        DB::table('discussionbridge_publications')->updateOrInsert(
            ['resource_id' => $publication['resource_id']],
            ['entry_id' => (string) $entry->id(), 'canonical_url' => $publication['canonical_url'], 'canonical_url_digest' => hash('sha256', $publication['canonical_url']), 'source_revision' => $publication['source_revision'], 'topic_id' => $publication['topic_id'], 'topic_url' => $publication['topic_url'], 'created_at' => $prior?->created_at ?? now(), 'updated_at' => now()],
        );
        $summary[$prior ? 'updated' : 'created']++;
    }
}
