<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationSynchronizer;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use Mockery;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\NativePublication;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationFreshness;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets;
use Illuminate\Support\Facades\DB;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

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

    public function test_source_update_preserves_existing_legacy_renderer_without_installing_new_assets(): void
    {
        config(['discussionbridge.rich_content' => null]);
        $resource = '11111111-1111-4111-8111-111111111111';
        $publication = [
            'resource_id' => $resource,
            'canonical_url' => 'https://statamic.example/discussionbridge/source',
            'slug' => 'source', 'title' => 'Updated source title',
            'content_html' => '<section><script>bad()</script><p>Updated source</p></section>',
            'source_revision' => 'post:149:version:2', 'source_author' => 'Author',
            'topic_id' => 53, 'topic_url' => 'https://forum.example/t/source/53',
        ];
        DB::table('discussionbridge_publications')->insert([
            'resource_id' => $resource, 'entry_id' => 'existing-entry',
            'canonical_url' => $publication['canonical_url'], 'canonical_url_digest' => hash('sha256', $publication['canonical_url']),
            'source_revision' => 'post:149:version:1', 'topic_id' => 53, 'topic_url' => $publication['topic_url'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $entry = Mockery::mock(EntryContract::class);
        $entry->shouldReceive('uri')->andReturn('/discussionbridge/source');
        $entry->shouldReceive('id')->andReturn('existing-entry');
        $collection = Mockery::mock();
        $collection->shouldReceive('dated')->andReturn(false);
        $entry->shouldReceive('collection')->andReturn($collection);
        $entry->shouldReceive('get')->with('discussionbridge_resource_id')->andReturn($resource);
        $entry->shouldReceive('get')->with('discussionbridge_native_publication')->andReturn(true);
        $entry->shouldReceive('get')->with('discussionbridge_topic_id')->andReturn(53);
        $entry->shouldReceive('get')->with('discussionbridge_managed_content_sha256')->andReturnNull();
        $entry->shouldReceive('get')->with('content')->andReturn('<p>Old source</p>'.PresentationAssets::LEGACY_MARKUP);
        $entry->shouldReceive('data')->withNoArgs()->andReturn(collect(['operator_setting' => 'preserved']));
        $saved = null;
        $entry->shouldReceive('data')->with(Mockery::on(function ($data) use (&$saved) { $saved = $data; return true; }))->andReturnSelf();
        $entry->shouldReceive('published')->with(true)->andReturnSelf();
        $entry->shouldReceive('published')->withNoArgs()->andReturn(true);
        $entry->shouldReceive('save')->once();
        Entry::shouldReceive('find')->with('existing-entry')->andReturn($entry);
        $validator = Mockery::mock(NativePublication::class);
        $validator->shouldReceive('fromRecord')->andReturn($publication);
        $this->app->instance(NativePublication::class, $validator);
        $freshness = Mockery::mock(PublicationFreshness::class);
        $freshness->shouldReceive('refresh')->once()->with($entry, $resource);
        $this->app->instance(PublicationFreshness::class, $freshness);
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('records')->with(1, null)->andReturn([
            'bridge_records' => [['resource_id' => $resource]],
            'pagination' => ['page' => 1, 'pages' => 1, 'total' => 1, 'snapshot' => 'update'],
        ]);
        $this->app->instance(BridgeClient::class, $client);

        $summary = app(PublicationSynchronizer::class)->synchronize();
        $this->assertSame(1, $summary['updated'], implode(', ', $summary['errors']));
        $this->assertSame('preserved', $saved['operator_setting']);
        $this->assertStringContainsString('Updated source', $saved['content']);
        $this->assertStringContainsString(PresentationAssets::LEGACY_MARKUP, $saved['content']);
        $this->assertStringNotContainsString('bad()', $saved['content']);
        $this->assertSame('post:149:version:2', DB::table('discussionbridge_publications')->where('resource_id', $resource)->value('source_revision'));
    }

    public function test_complete_source_editions_withdraw_and_restore_the_same_owned_native_entry(): void
    {
        config(['discussionbridge.rich_content' => null]);
        $resource = '11111111-1111-4111-8111-111111111111';
        $body = '<p>'.str_repeat('é', 40000).'Complete ending</p>';
        $active = $this->nativeRecord($body, str_repeat('a', 64));
        $withdrawn = array_replace($active, ['title' => 'Source publication withdrawn', 'content_html' => null, 'source' => ['status' => 'withdrawn', 'reason' => 'source_unavailable', 'edition' => str_repeat('b', 64)]]);
        $restored = $this->nativeRecord('<p>Restored complete source</p>', str_repeat('c', 64));
        $restored['title'] = 'Restored title';
        DB::table('discussionbridge_publications')->insert([
            'resource_id' => $resource, 'entry_id' => 'existing-entry',
            'canonical_url' => $active['bindings'][0]['canonical_url'], 'canonical_url_digest' => hash('sha256', $active['bindings'][0]['canonical_url']),
            'source_revision' => 'post:149:version:1', 'topic_id' => 53, 'topic_url' => $active['source']['topic_url'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $data = ['content' => '<p>Old body</p>'.PresentationAssets::LEGACY_MARKUP, 'discussionbridge_native_publication' => true, 'discussionbridge_resource_id' => $resource, 'discussionbridge_topic_id' => 53, 'discussionbridge_mode' => 'full', 'operator_setting' => 'keep'];
        $published = true;
        $entry = Mockery::mock(EntryContract::class);
        $entry->shouldReceive('uri')->andReturn('/discussionbridge/source');
        $entry->shouldReceive('id')->andReturn('existing-entry');
        $collection = Mockery::mock();
        $collection->shouldReceive('dated')->andReturn(false);
        $entry->shouldReceive('collection')->andReturn($collection);
        $entry->shouldReceive('get')->andReturnUsing(function ($key) use (&$data) { return $data[$key] ?? null; });
        $entry->shouldReceive('set')->andReturnUsing(function ($key, $value) use (&$data, $entry) { $data[$key] = $value; return $entry; });
        $entry->shouldReceive('data')->withNoArgs()->andReturnUsing(function () use (&$data) { return collect($data); });
        $entry->shouldReceive('data')->with(Mockery::type('array'))->andReturnUsing(function ($values) use (&$data, $entry) { $data = $values; return $entry; });
        $entry->shouldReceive('published')->withNoArgs()->andReturnUsing(function () use (&$published) { return $published; });
        $entry->shouldReceive('published')->with(Mockery::type('bool'))->andReturnUsing(function ($value) use (&$published, $entry) { $published = $value; return $entry; });
        $entry->shouldReceive('save')->times(3);
        Entry::shouldReceive('find')->with('existing-entry')->andReturn($entry);
        $freshness = Mockery::mock(PublicationFreshness::class);
        $freshness->shouldReceive('refresh')->times(4)->with($entry, $resource);
        $this->app->instance(PublicationFreshness::class, $freshness);
        $client = Mockery::mock(BridgeClient::class);
        $current = $active;
        $client->shouldReceive('records')->with(1, null)->andReturnUsing(function () use (&$current) { return ['bridge_records' => [$current], 'pagination' => ['page' => 1, 'pages' => 1, 'total' => 1, 'snapshot' => 'current']]; });
        $client->shouldReceive('publicationContent')->once()->with($active)->andReturn($body);
        $client->shouldReceive('publicationContent')->once()->with($restored)->andReturn('<p>Restored complete source</p>');
        $this->app->instance(BridgeClient::class, $client);
        $sync = app(PublicationSynchronizer::class);

        $summary = $sync->synchronize();
        $this->assertSame(1, $summary['updated'], implode(', ', $summary['errors']));
        $this->assertStringContainsString('Complete ending', $data['content']);
        $this->assertStringContainsString('Forum &lt;name&gt; &amp; safe', $data['content']);
        $this->assertSame('2026-08-01T10:00:00+02:00', $data['discussionbridge_source_created_at']);
        $this->assertSame('full', $data['discussionbridge_mode']);
        $this->assertSame('keep', $data['operator_setting']);
        $this->assertSame(1, $sync->synchronize()['unchanged']);
        $recovery = $data['content'];
        $current = $withdrawn;
        $this->assertSame(1, $sync->synchronize()['updated']);
        $this->assertFalse($published);
        $this->assertSame($recovery, $data['content']);
        $this->assertSame('withdrawn', $data['discussionbridge_source_status']);
        $current = $restored;
        $this->assertSame(1, $sync->synchronize()['updated']);
        $this->assertTrue($published);
        $this->assertSame('active', $data['discussionbridge_source_status']);
        $this->assertSame($resource, $data['discussionbridge_resource_id']);
        $this->assertSame(53, $data['discussionbridge_topic_id']);
        $this->assertSame('full', $data['discussionbridge_mode']);
        $this->assertSame(str_repeat('c', 64), DB::table('discussionbridge_publications')->where('resource_id', $resource)->value('source_revision'));
    }

    private function nativeRecord(string $body, string $edition): array
    {
        $resource = '11111111-1111-4111-8111-111111111111';
        return [
            'resource_id' => $resource, 'direction' => 'from_discourse', 'state' => 'healthy', 'title' => 'Source title', 'topic_id' => 53, 'content_html' => null,
            'source' => ['platform' => 'discourse', 'status' => 'active', 'origin' => 'https://forum.example', 'topic_id' => 53, 'topic_url' => 'https://forum.example/t/source/53', 'post_id' => 149, 'post_number' => 1, 'post_version' => 2, 'revision' => 'post:149:version:2', 'edition' => $edition, 'created_at' => '2026-08-01T10:00:00+02:00', 'updated_at' => '2026-09-02T08:00:00Z', 'forum_name' => 'Forum <name> & safe', 'author' => ['name' => 'Author', 'profile_url' => 'https://forum.example/u/author'], 'content' => ['bytes' => strlen($body), 'sha256' => hash('sha256', $body), 'chunk_bytes' => 32768, 'path' => '/discussion-bridge/v1/bridge-records/'.$resource.'/content.json']],
            'bindings' => [['role' => 'presentation', 'state' => 'active', 'canonical_url' => 'https://statamic.example/discussionbridge/source', 'native_materialization' => true, 'presentation_mode' => 'interactive']],
        ];
    }
}
