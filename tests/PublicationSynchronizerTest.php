<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationSynchronizer;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\NativePublication;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeRequestException;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class PublicationSynchronizerTest extends TestCase
{
    public function test_static_prepare_reestablishes_the_claimed_publish_lease_on_its_client(): void
    {
        $lease = str_repeat('c', 64);
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('resumePublicationLease')->once()->with($lease);
        $client->shouldReceive('sourceTopic')->once()->with(53)->andReturn([
            'eligible' => true,
            'source_topic' => [
                'source_revision' => 'post:149:version:2',
                'publication_revision' => str_repeat('d', 64),
            ],
        ]);
        $this->app->instance(BridgeClient::class, $client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source revision changed');
        app(PublicationSynchronizer::class)->prepareClaimedStatic([
            'topic_id' => 53,
            'source_revision' => 'post:149:version:1',
            'publication_revision' => str_repeat('a', 64),
            'lease_token' => $lease,
        ]);
    }

    public function test_static_prepare_reestablishes_the_claimed_unpublish_lease_on_its_client(): void
    {
        $lease = str_repeat('c', 64);
        $resourceId = '11111111-1111-4111-8111-111111111111';
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('resumePublicationLease')->once()->with($lease);
        $client->shouldReceive('sourceRevocation')->once()->with($resourceId)->andReturn([
            'revoked' => true,
            'publication_revocation' => [
                'topic_id' => 54,
                'publication_revision' => str_repeat('d', 64),
            ],
        ]);
        $this->app->instance(BridgeClient::class, $client);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('withdrawal changed');
        app(PublicationSynchronizer::class)->prepareClaimedStaticUnpublish([
            'topic_id' => 53,
            'resource_id' => $resourceId,
            'publication_revision' => str_repeat('a', 64),
            'lease_token' => $lease,
        ]);
    }

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

    public function test_incremental_queue_reports_a_changed_claim_without_scanning_all_records(): void
    {
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('claimPublicationWork')->once()->with(300)->andReturn([
            'publication_work' => [
                'topic_id' => 53,
                'action' => 'publish',
                'source_revision' => 'post:149:version:1',
                'publication_revision' => str_repeat('a', 64),
                'lease_token' => str_repeat('c', 64),
            ],
        ]);
        $client->shouldReceive('sourceTopic')->once()->with(53)->andReturn([
            'eligible' => true,
            'source_topic' => [
                'topic_id' => 53,
                'source_revision' => 'post:149:version:2',
                'publication_revision' => str_repeat('d', 64),
            ],
        ]);
        $client->shouldReceive('failPublicationWork')->once()->with(
            'statamic_delivery_failed',
            Mockery::on(fn ($value) => str_contains($value, 'source revision changed')),
        );
        $client->shouldReceive('clearPublicationLease')->once();
        $client->shouldNotReceive('records');
        $this->app->instance(BridgeClient::class, $client);

        $summary = app(PublicationSynchronizer::class)->synchronizeQueued(1, 300);

        $this->assertSame(1, $summary['failed']);
        $this->assertStringContainsString('source revision changed', $summary['errors'][0]);
    }

    public function test_incremental_queue_stops_cleanly_on_an_unleased_rate_limit(): void
    {
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('claimPublicationWork')->once()->with(300)->andThrow(
            new BridgeRequestException(429, 'rate_limited'),
        );
        $client->shouldReceive('publicationLeaseToken')->once()->andReturnNull();
        $client->shouldNotReceive('failPublicationWork');
        $this->app->instance(BridgeClient::class, $client);

        $summary = app(PublicationSynchronizer::class)->synchronizeQueued(8, 300);

        $this->assertSame(0, $summary['failed']);
        $this->assertSame([], $summary['errors']);
    }

    public function test_incremental_queue_restores_the_exact_prior_entry_when_acknowledgement_fails(): void
    {
        $resourceId = '22222222-2222-4222-8222-222222222222';
        Collection::make('pages')->routes(['default' => '/{slug}'])->save();
        $entry = Entry::make()
            ->id('statamic-publication-53')
            ->collection('pages')
            ->slug('forum-topic-53')
            ->published(true)
            ->data([
                'title' => 'Prior title',
                'content' => '<p>Prior body</p>',
                'discussionbridge_resource_id' => $resourceId,
                'discussionbridge_source_revision' => 'post:149:version:1',
            ]);
        $entry->save();
        DB::table('discussionbridge_publications')->insert([
            'resource_id' => $resourceId,
            'entry_id' => (string) $entry->id(),
            'canonical_url' => 'https://statamic.example/forum-topic-53/',
            'canonical_url_digest' => hash('sha256', 'https://statamic.example/forum-topic-53/'),
            'source_revision' => 'post:149:version:1',
            'topic_id' => 53,
            'topic_url' => 'https://forum.example/t/forum-scale-canary/53',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $priorData = Entry::find('statamic-publication-53')->data()->all();
        $priorRow = (array) DB::table('discussionbridge_publications')->where('resource_id', $resourceId)->first();
        $lease = str_repeat('c', 64);
        $publicationRevision = str_repeat('a', 64);
        $mappingRevision = str_repeat('b', 64);
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('claimPublicationWork')->once()->with(300)->andReturn([
            'publication_work' => [
                'topic_id' => 53,
                'action' => 'publish',
                'source_revision' => 'post:149:version:2',
                'publication_revision' => $publicationRevision,
                'lease_token' => $lease,
            ],
        ]);
        $client->shouldReceive('sourceTopic')->once()->with(53)->andReturn([
            'eligible' => true,
            'source_topic' => [
                'topic_id' => 53,
                'topic_url' => 'https://forum.example/t/forum-scale-canary/53',
                'title' => 'Changed title',
                'source_revision' => 'post:149:version:2',
                'publication_revision' => $publicationRevision,
                'source_created_at' => '2026-09-19T15:00:00.000000Z',
                'source_updated_at' => '2026-09-20T16:00:00.000000Z',
                'content_html' => '<h2>Changed body</h2>',
                'author' => [
                    'name' => 'DiscussionBridge',
                    'profile_url' => 'https://forum.example/u/discussionbridge',
                ],
                'destination' => [
                    'state' => 'ready',
                    'destination_container_id' => 'pages',
                    'mapping_revision' => $mappingRevision,
                    'slug_policy' => 'topic_id',
                    'destination_author_id' => 'user:statamic-service-user',
                    'destination_terms' => [],
                ],
            ],
        ]);
        $client->shouldReceive('resolveSourceTopic')->once()->andReturn([
            'outcome' => 'resolved',
            'resource_id' => $resourceId,
            'external_id' => 'statamic:topic:53',
            'canonical_url' => 'https://statamic.example/forum-topic-53/',
        ]);
        $client->shouldReceive('acknowledgePublication')->once()->andReturnUsing(function () use ($resourceId, $publicationRevision): never {
            $content = (string) Entry::find('statamic-publication-53')->get('content');
            $this->assertStringContainsString('data-discussionbridge-resource-id="'.$resourceId.'"', $content);
            $this->assertStringContainsString('data-discussionbridge-publication-revision="'.$publicationRevision.'"', $content);
            throw new RuntimeException('receiver unavailable');
        });
        $client->shouldReceive('failPublicationWork')->once()->with(
            'statamic_delivery_failed',
            Mockery::on(fn ($value) => str_contains($value, 'receiver unavailable')),
        );
        $client->shouldReceive('clearPublicationLease')->once();
        $this->app->instance(BridgeClient::class, $client);

        $summary = app(PublicationSynchronizer::class)->synchronizeQueued(1, 300);

        $restored = Entry::find('statamic-publication-53');
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(0, $summary['updated']);
        $this->assertSame($priorData, $restored->data()->all());
        $this->assertTrue($restored->published());
        $this->assertSame($priorRow, (array) DB::table('discussionbridge_publications')->where('resource_id', $resourceId)->first());
    }

    public function test_static_retry_reuses_an_existing_structured_entry(): void
    {
        $resourceId = '33333333-3333-4333-8333-333333333333';
        $revision = str_repeat('a', 64);
        $mappingRevision = str_repeat('b', 64);
        $lease = str_repeat('c', 64);
        $suffix = bin2hex(random_bytes(4));
        $collectionHandle = 'retry-pages-'.$suffix;
        $entryId = 'statamic-orphan-'.$suffix;
        $topicId = random_int(100000, 999999);
        $slug = 'forum-topic-'.$topicId;
        config()->set('discussionbridge.collections', [$collectionHandle]);
        $collection = Collection::make($collectionHandle)
            ->routes(['default' => '/{slug}'])
            ->structureContents(['root' => false]);
        $collection->save();
        $entry = Entry::make()
            ->id($entryId)
            ->collection($collectionHandle)
            ->slug($slug)
            ->published(true)
            ->data([
                'title' => 'Interrupted publication',
                'content' => '<p>Prepared but not recorded.</p>',
                'discussionbridge_resource_id' => $resourceId,
            ]);
        $entry->save();
        $tree = $collection->structure()->in(Site::default()->handle());
        $tree->append($entry);
        $tree->save();
        \Statamic\Facades\Blink::flush();
        $priorData = Entry::find($entryId)->data()->all();
        $this->assertInstanceOf(\Statamic\Contracts\Entries\Entry::class, Entry::findByUri('/'.$slug, Site::default()->handle()));

        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('resumePublicationLease')->once()->with($lease);
        $client->shouldReceive('sourceTopic')->once()->with($topicId)->andReturn([
            'eligible' => true,
            'source_topic' => [
                'topic_id' => $topicId,
                'topic_url' => 'https://forum.example/t/forum-scale-canary/'.$topicId,
                'title' => 'Forum Scale Canary',
                'source_revision' => 'post:149:version:2',
                'publication_revision' => $revision,
                'source_created_at' => '2026-09-19T15:00:00.000000Z',
                'source_updated_at' => '2026-09-20T16:00:00.000000Z',
                'content_html' => '<h2>Recovered body</h2>',
                'author' => [
                    'name' => 'DiscussionBridge',
                    'profile_url' => 'https://forum.example/u/discussionbridge',
                ],
                'destination' => [
                    'state' => 'ready',
                    'destination_container_id' => $collectionHandle,
                    'mapping_revision' => $mappingRevision,
                    'slug_policy' => 'topic_id',
                    'destination_author_id' => 'user:statamic-service-user',
                    'destination_terms' => [],
                ],
            ],
        ]);
        $client->shouldReceive('resolveSourceTopic')->once()->andReturn([
            'outcome' => 'resolved',
            'resource_id' => $resourceId,
            'external_id' => 'statamic:topic:'.$topicId,
            'canonical_url' => 'https://statamic.example/'.$slug.'/',
        ]);
        $this->app->instance(BridgeClient::class, $client);

        $prepared = app(PublicationSynchronizer::class)->prepareClaimedStatic([
            'topic_id' => $topicId,
            'source_revision' => 'post:149:version:2',
            'publication_revision' => $revision,
            'lease_token' => $lease,
        ]);

        $this->assertSame('created', $prepared['outcome']);
        $this->assertSame($entryId, DB::table('discussionbridge_publications')->where('resource_id', $resourceId)->value('entry_id'));
        $this->assertStringContainsString('Recovered body', (string) Entry::find($entryId)->get('content'));

        $this->assertTrue(app(PublicationSynchronizer::class)->restorePreparedStatic($prepared));
        $this->assertNull(DB::table('discussionbridge_publications')->where('resource_id', $resourceId)->first());
        $this->assertSame($priorData, Entry::find($entryId)->data()->all());

        Entry::find($entryId)?->delete();
        $collection->delete();
    }

    public function test_same_source_revision_updates_when_publication_revision_changes(): void
    {
        $resourceId = '44444444-4444-4444-8444-444444444444';
        $oldRevision = str_repeat('a', 64);
        $newRevision = str_repeat('d', 64);
        $mappingRevision = str_repeat('b', 64);
        $lease = str_repeat('c', 64);
        $suffix = bin2hex(random_bytes(4));
        $collectionHandle = 'revision-pages-'.$suffix;
        $entryId = 'revision-entry-'.$suffix;
        $topicId = random_int(100000, 999999);
        $slug = 'forum-topic-'.$topicId;
        config()->set('discussionbridge.collections', [$collectionHandle]);
        $collection = Collection::make($collectionHandle)->routes(['default' => '/{slug}']);
        $collection->save();
        $entry = Entry::make()
            ->id($entryId)
            ->collection($collectionHandle)
            ->slug($slug)
            ->published(true)
            ->data([
                'title' => 'Unchanged source',
                'content' => '<p>Prior mapped publication.</p>',
                'discussionbridge_resource_id' => $resourceId,
                'discussionbridge_source_revision' => 'post:149:version:2',
                'discussionbridge_publication_revision' => $oldRevision,
            ]);
        $entry->save();
        DB::table('discussionbridge_publications')->insert([
            'resource_id' => $resourceId,
            'entry_id' => $entryId,
            'canonical_url' => 'https://statamic.example/'.$slug.'/',
            'canonical_url_digest' => hash('sha256', 'https://statamic.example/'.$slug.'/'),
            'source_revision' => 'post:149:version:2',
            'topic_id' => $topicId,
            'topic_url' => 'https://forum.example/t/forum-scale-canary/'.$topicId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('resumePublicationLease')->once()->with($lease);
        $client->shouldReceive('sourceTopic')->once()->with($topicId)->andReturn([
            'eligible' => true,
            'source_topic' => [
                'topic_id' => $topicId,
                'topic_url' => 'https://forum.example/t/forum-scale-canary/'.$topicId,
                'title' => 'Unchanged source',
                'source_revision' => 'post:149:version:2',
                'publication_revision' => $newRevision,
                'source_created_at' => '2026-09-19T15:00:00.000000Z',
                'source_updated_at' => '2026-09-20T16:00:00.000000Z',
                'content_html' => '<h2>Same source, new mapping</h2>',
                'author' => [
                    'name' => 'DiscussionBridge',
                    'profile_url' => 'https://forum.example/u/discussionbridge',
                ],
                'destination' => [
                    'state' => 'ready',
                    'destination_container_id' => $collectionHandle,
                    'mapping_revision' => $mappingRevision,
                    'slug_policy' => 'topic_id',
                    'destination_author_id' => 'user:statamic-service-user',
                    'destination_terms' => [],
                ],
            ],
        ]);
        $client->shouldReceive('resolveSourceTopic')->once()->andReturn([
            'outcome' => 'resolved',
            'resource_id' => $resourceId,
            'external_id' => 'statamic:topic:'.$topicId,
            'canonical_url' => 'https://statamic.example/'.$slug.'/',
        ]);
        $this->app->instance(BridgeClient::class, $client);

        $prepared = app(PublicationSynchronizer::class)->prepareClaimedStatic([
            'topic_id' => $topicId,
            'source_revision' => 'post:149:version:2',
            'publication_revision' => $newRevision,
            'lease_token' => $lease,
        ]);

        $updated = Entry::find($entryId);
        $this->assertSame('updated', $prepared['outcome']);
        $this->assertSame($newRevision, $updated->get('discussionbridge_publication_revision'));
        $this->assertStringContainsString('data-discussionbridge-publication-revision="'.$newRevision.'"', (string) $updated->get('content'));
        DB::table('discussionbridge_publications')->where('resource_id', $resourceId)->delete();
        $updated->delete();
        $collection->delete();
    }
}
