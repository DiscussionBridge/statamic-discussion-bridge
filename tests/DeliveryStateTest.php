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
        $canonicalUrl = 'https://statamic.example/statamic-alpha/';
        $entry->shouldReceive('absoluteUrl')->andReturnUsing(function () use (&$canonicalUrl): string {
            return $canonicalUrl;
        });
        $entry->shouldReceive('site')->andReturn($site);
        $entry->shouldReceive('id')->andReturn('entry-1');

        $enqueuer = app(DeliveryEnqueuer::class);
        $this->assertTrue($enqueuer->enqueue($entry));
        $this->assertFalse($enqueuer->enqueue($entry));
        $this->assertSame(1, DB::table('discussionbridge_deliveries')->count());

        Entry::shouldReceive('find')->twice()->with('entry-1')->andReturn($entry);
        $client = Mockery::mock(BridgeClient::class);
        $sent = [];
        $client->shouldReceive('resolve')->twice()->withArgs(function (array $payload) use (&$sent): bool {
            $sent[] = $payload;
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
        ], [
            'outcome' => 'resolved',
            'resource_id' => '63bad04c-1c2e-4c38-80ce-b379137dbb2c',
            'topic_id' => 7,
            'topic_url' => 'https://forum.example/t/statamic-alpha/7',
            'direction' => 'to_discourse',
            'core_fallback' => false,
        ]);
        $worker = new DeliveryWorker($client, app(Configuration::class));

        $first = $worker->work(25);
        $canonicalUrl = 'https://statamic.example/statamic-alpha-moved/';
        $this->artisan('discussionbridge:retry', ['entry' => 'entry-1', '--delivered' => true])->assertSuccessful();
        $second = $worker->work(25);
        $row = DB::table('discussionbridge_deliveries')->first();

        $this->assertSame(1, $first['delivered'], json_encode(['result' => $first, 'row' => (array) $row], JSON_THROW_ON_ERROR));
        $this->assertSame(1, $second['delivered']);
        $this->assertSame('delivered', $row->status);
        $this->assertSame('63bad04c-1c2e-4c38-80ce-b379137dbb2c', $row->resource_id);
        $this->assertSame(7, $row->topic_id);
        $this->assertSame(2, $row->attempts);
        $this->assertSame($sent[0]['external_id'], $sent[1]['external_id']);
        $this->assertSame('https://statamic.example/statamic-alpha/', $sent[0]['canonical_url']);
        $this->assertSame($canonicalUrl, $sent[1]['canonical_url']);
        $this->assertSame($canonicalUrl, $row->canonical_url);
    }
}
