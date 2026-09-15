<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationSynchronizer;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use Mockery;

class PublicationSynchronizerTest extends TestCase
{
    public function test_it_returns_a_bounded_empty_feed_summary(): void
    {
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('records')->once()->with(1, null)->andReturn([
            'bridge_records' => [],
            'pagination' => ['page' => 1, 'pages' => 1, 'total' => 0, 'snapshot' => 'snapshot-one'],
        ]);
        $this->app->instance(BridgeClient::class, $client);

        $summary = app(PublicationSynchronizer::class)->synchronize();

        $this->assertSame([
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ], $summary);
    }

    public function test_command_keeps_the_existing_machine_readable_summary(): void
    {
        $synchronizer = Mockery::mock(PublicationSynchronizer::class);
        $synchronizer->shouldReceive('synchronize')->once()->andReturn([
            'created' => 0,
            'updated' => 0,
            'unchanged' => 1,
            'skipped' => 1,
            'failed' => 0,
            'errors' => [],
        ]);
        $this->app->instance(PublicationSynchronizer::class, $synchronizer);

        $this->artisan('discussionbridge:sync-publications')
            ->expectsOutput('{"created":0,"updated":0,"unchanged":1,"skipped":1,"failed":0}')
            ->assertSuccessful();
    }
}
