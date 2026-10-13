<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\DeliveryEnqueuer;
use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\DeliveryWorker;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use Illuminate\Support\Facades\DB;
use Mockery;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Sites\Site as SiteContract;
use Statamic\Facades\Entry;

class DeliveryStateTest extends TestCase
{
    public function test_publish_gate_only_enqueues_once_and_worker_resolves_once(): void
    {
        $site = Mockery::mock(SiteContract::class);
        $site->shouldReceive('handle')->andReturn('default');
        $entry = Mockery::mock(EntryContract::class);
        $entry->shouldReceive('collectionHandle')->andReturn('pages');
        $entry->shouldReceive('published')->andReturn(true);
        $entry->shouldReceive('status')->andReturn('published');
        $entry->shouldReceive('get')->with('discussionbridge_publish')->andReturn(true);
        $entry->shouldReceive('get')->with('title')->andReturn('Statamic Alpha');
        $entry->shouldReceive('get')->with('content')->andReturn("## A real Statamic article\n\nThis content crosses the bridge.");
        $entry->shouldReceive('get')->with('created_at')->andReturn(null);
        $entry->shouldReceive('get')->with('date')->andReturn(null);
        $entry->shouldReceive('get')->with('updated_at')->andReturn(null);
        $entry->shouldReceive('absoluteUrl')->andReturn('https://statamic.example/statamic-alpha/');
        $entry->shouldReceive('site')->andReturn($site);
        $entry->shouldReceive('id')->andReturn('entry-1');

        $enqueuer = app(DeliveryEnqueuer::class);
        $this->assertTrue($enqueuer->enqueue($entry));
        $this->assertFalse($enqueuer->enqueue($entry));
        $this->assertSame(1, DB::table('discussionbridge_deliveries')->count());

        Entry::shouldReceive('find')->twice()->with('entry-1')->andReturn($entry);
        $client = Mockery::mock(BridgeClient::class);
        $revision = \CodeWorksLabs\DiscussionBridgeStatamic\Delivery\SourcePublication::fromEntry($entry, app(Configuration::class))['record']['source_publication']['revision'];
        $client->shouldReceive('resolve')->twice()->withArgs(function (array $payload): bool {
            return str_contains($payload['content_html'] ?? '', '<h2>A real Statamic article</h2>')
                && str_contains($payload['content_html'] ?? '', 'This content crosses the bridge.')
                && ($payload['source_authors'][0]['name'] ?? null) === 'Statamic Author'
                && ($payload['source_authors'][0]['profile_url'] ?? null) === 'https://statamic.example/authors/statamic-author'
                && ($payload['primary_source_author_id'] ?? null) === ($payload['source_authors'][0]['id'] ?? null);
        })->andReturn([
            'outcome' => 'created',
            'resource_id' => '63bad04c-1c2e-4c38-80ce-b379137dbb2c',
            'topic_id' => 7,
            'topic_url' => 'https://forum.example/t/statamic-alpha/7',
            'direction' => 'to_discourse',
            'core_fallback' => false,
            'source_revision' => $revision,
        ], [
            'outcome' => 'resolved',
            'resource_id' => '63bad04c-1c2e-4c38-80ce-b379137dbb2c',
            'topic_id' => 7,
            'topic_url' => 'https://forum.example/t/statamic-alpha/7',
            'direction' => 'to_discourse',
            'core_fallback' => false,
            'source_revision' => $revision,
        ]);
        $worker = new DeliveryWorker($client, app(Configuration::class));

        $first = $worker->work(25);
        $this->artisan('discussionbridge:retry', ['entry' => 'entry-1', '--delivered' => true])->assertSuccessful();
        $second = $worker->work(25);
        $row = DB::table('discussionbridge_deliveries')->first();

        $this->assertSame(1, $first['delivered'], json_encode(['result' => $first, 'row' => (array) $row], JSON_THROW_ON_ERROR));
        $this->assertSame(1, $second['delivered']);
        $this->assertSame('delivered', $row->status);
        $this->assertSame('63bad04c-1c2e-4c38-80ce-b379137dbb2c', $row->resource_id);
        $this->assertSame(7, $row->topic_id);
        $this->assertSame(2, $row->attempts);
    }

    public function test_source_edit_during_inflight_acceptance_queues_the_new_edition_with_correct_previous_revision(): void
    {
        $site = Mockery::mock(SiteContract::class);
        $site->shouldReceive('handle')->andReturn('default');
        $content = '<p>First source edition</p>';
        $title = 'First title';
        $entry = Mockery::mock(EntryContract::class);
        $entry->shouldReceive('collectionHandle')->andReturn('pages');
        $entry->shouldReceive('published')->andReturn(true);
        $entry->shouldReceive('status')->andReturn('published');
        $entry->shouldReceive('get')->with('discussionbridge_publish')->andReturn(true);
        $entry->shouldReceive('get')->with('title')->andReturnUsing(function () use (&$title) { return $title; });
        $entry->shouldReceive('get')->with('content')->andReturnUsing(function () use (&$content) { return $content; });
        $entry->shouldReceive('get')->with('created_at')->andReturn(null);
        $entry->shouldReceive('get')->with('date')->andReturn(null);
        $entry->shouldReceive('get')->with('updated_at')->andReturn(null);
        $entry->shouldReceive('absoluteUrl')->andReturn('https://statamic.example/source');
        $entry->shouldReceive('site')->andReturn($site);
        $entry->shouldReceive('id')->andReturn('source-entry');
        $enqueuer = app(DeliveryEnqueuer::class);
        $this->assertTrue($enqueuer->enqueue($entry));
        Entry::shouldReceive('find')->twice()->with('source-entry')->andReturn($entry);
        $revisions = [];
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('resolve')->twice()->andReturnUsing(function ($payload) use (&$content, &$title, &$revisions, $entry, $enqueuer) {
            $revision = $payload['source_publication']['revision'];
            if ($revisions === []) {
                $this->assertNull($payload['source_publication']['previous_revision']);
                $content = '<p>Second source edition</p>';
                $title = 'Changed title';
                $this->assertFalse($enqueuer->enqueue($entry), 'An inflight claim is retained while desired edition advances.');
            } else {
                $this->assertSame($revisions[0], $payload['source_publication']['previous_revision']);
                $this->assertStringContainsString('Second source edition', $payload['content_html']);
            }
            $revisions[] = $revision;
            return ['outcome' => count($revisions) === 1 ? 'created' : 'resolved', 'resource_id' => '63bad04c-1c2e-4c38-80ce-b379137dbb2c', 'topic_id' => 7, 'topic_url' => 'https://forum.example/t/'.(count($revisions) === 1 ? 'first-title' : 'changed-title').'/7', 'direction' => 'to_discourse', 'core_fallback' => false, 'source_revision' => $revision];
        });
        $worker = new DeliveryWorker($client, app(Configuration::class));
        $this->assertSame(1, $worker->work(1)['delivered']);
        $row = DB::table('discussionbridge_deliveries')->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame($revisions[0], $row->source_revision);
        $this->assertSame(1, $worker->work(1)['delivered']);
        $row = DB::table('discussionbridge_deliveries')->first();
        $this->assertSame('delivered', $row->status);
        $this->assertSame($revisions[1], $row->source_revision);
        $this->assertSame(7, $row->topic_id);
        $this->assertSame('https://forum.example/t/changed-title/7', $row->topic_url);
    }
}
