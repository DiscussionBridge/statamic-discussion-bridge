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
        $entry->shouldReceive('absoluteUrl')->andReturn('https://statamic.example/statamic-alpha/');
        $entry->shouldReceive('site')->andReturn($site);
        $entry->shouldReceive('id')->andReturn('entry-1');

        $enqueuer = app(DeliveryEnqueuer::class);
        $this->assertTrue($enqueuer->enqueue($entry));
        $this->assertFalse($enqueuer->enqueue($entry));
        $this->assertSame(1, DB::table('discussionbridge_deliveries')->count());

        Entry::shouldReceive('find')->once()->with('entry-1')->andReturn($entry);
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('resolve')->once()->andReturn([
            'outcome' => 'created',
            'resource_id' => '63bad04c-1c2e-4c38-80ce-b379137dbb2c',
            'topic_id' => 7,
            'topic_url' => 'https://forum.example/t/statamic-alpha/7',
            'direction' => 'to_discourse',
            'core_fallback' => false,
        ]);
        $worker = new DeliveryWorker($client, app(Configuration::class));

        $first = $worker->work(25);
        $second = $worker->work(25);
        $row = DB::table('discussionbridge_deliveries')->first();

        $this->assertSame(1, $first['delivered'], json_encode(['result' => $first, 'row' => (array) $row], JSON_THROW_ON_ERROR));
        $this->assertSame(0, $second['processed']);
        $this->assertSame('delivered', $row->status);
        $this->assertSame('63bad04c-1c2e-4c38-80ce-b379137dbb2c', $row->resource_id);
        $this->assertSame(7, $row->topic_id);
        $this->assertSame(1, $row->attempts);
    }
}
