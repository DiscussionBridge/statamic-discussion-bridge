<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationSynchronizer;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\NativePublication;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use Illuminate\Support\Facades\DB;
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

    public function test_existing_publication_rejects_url_drift_without_receiver_migration_proof(): void
    {
        $resourceId = '11111111-1111-4111-8111-111111111111';
        DB::table('discussionbridge_publications')->insert([
            'resource_id' => $resourceId,
            'entry_id' => 'existing-entry',
            'canonical_url' => 'https://statamic.example/old-publication',
            'canonical_url_digest' => hash('sha256', 'https://statamic.example/old-publication'),
            'source_revision' => 'post:149:version:1',
            'topic_id' => 53,
            'topic_url' => 'https://forum.example/t/topic/53',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $record = ['resource_id' => $resourceId];
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('records')->once()->with(1, null)->andReturn([
            'bridge_records' => [$record],
            'pagination' => ['page' => 1, 'pages' => 1, 'total' => 1, 'snapshot' => 'snapshot-one'],
        ]);
        $validator = Mockery::mock(NativePublication::class);
        $validator->shouldReceive('fromRecord')->once()->with($record)->andReturn([
            'resource_id' => $resourceId,
            'canonical_url' => 'https://statamic.example/new-publication',
            'url_migration' => null,
        ]);
        $this->app->instance(BridgeClient::class, $client);
        $this->app->instance(NativePublication::class, $validator);

        $summary = app(PublicationSynchronizer::class)->synchronize();
        $this->assertSame(1, $summary['failed']);
        $this->assertStringContainsString('requires a verified migration', $summary['errors'][0]);
        $this->assertSame('https://statamic.example/old-publication', DB::table('discussionbridge_publications')->where('resource_id', $resourceId)->value('canonical_url'));
    }
}
