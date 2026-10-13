<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationFreshness;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationSynchronizer;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use Illuminate\Support\Facades\DB;
use Mockery;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;

class NativePublicationFlatTest extends TestCase
{
    private string $nativeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nativeRoot = sys_get_temp_dir().'/discussionbridge-native-flat-'.bin2hex(random_bytes(5));
        mkdir($this->nativeRoot.'/collections', 0777, true);
        mkdir($this->nativeRoot.'/structures', 0777, true);
        Site::setSites(['default' => ['name' => 'Isolated Flat fixture', 'url' => 'https://statamic.example', 'locale' => 'en_US']]);
        Stache::store('collections')->directory($this->nativeRoot.'/collections');
        Stache::store('entries')->directory($this->nativeRoot.'/collections');
        Stache::store('collection-trees')->directory($this->nativeRoot.'/structures');
        Collection::make('discussionbridge')->routes('/discussionbridge/{slug}')->template('native-publication')->save();
        config(['discussionbridge.rich_content' => false]);
    }

    public function test_real_flat_entry_receipt_protects_operator_body_and_same_identity_across_source_updates(): void
    {
        $record = $this->record(1, '<p>First source body</p>');
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('records')->with(1, null)->andReturnUsing(function () use (&$record) {
            return ['bridge_records' => [$record], 'pagination' => ['page' => 1, 'pages' => 1, 'total' => 1, 'snapshot' => 'native-flat']];
        });
        $this->app->instance(BridgeClient::class, $client);
        $freshness = Mockery::mock(PublicationFreshness::class);
        $freshness->shouldReceive('refresh')->twice();
        $this->app->instance(PublicationFreshness::class, $freshness);
        $sync = app(PublicationSynchronizer::class);
        $summary = $sync->synchronize();
        $this->assertSame(1, $summary['created'], implode(', ', $summary['errors']));
        $row = DB::table('discussionbridge_publications')->first();
        $entry = Entry::find($row->entry_id);
        $path = $entry->path();
        $this->assertStringStartsWith(str_replace('\\', '/', $this->nativeRoot), str_replace('\\', '/', $path));
        $this->assertFileExists($path);
        $this->assertSame(hash('sha256', $entry->get('content')), $entry->get('discussionbridge_managed_content_sha256'));
        $record = $this->record(2, '<p>Second source body</p>');
        $this->assertSame(1, $sync->synchronize()['updated']);
        $this->assertSame($row->entry_id, DB::table('discussionbridge_publications')->first()->entry_id);
        $this->assertSame($path, Entry::find($row->entry_id)->path());
        $entry = Entry::find($row->entry_id);
        $entry->set('content', '<p>Operator changed this native body</p>')->save();
        $record = $this->record(3, '<p>Must not overwrite the local body</p>');
        $summary = $sync->synchronize();
        $this->assertSame(1, $summary['failed']);
        $this->assertStringContainsString('native_content_modified', $summary['errors'][0]);
        $this->assertSame('<p>Operator changed this native body</p>', Entry::find($row->entry_id)->get('content'));
        $this->assertSame('post:149:version:2', DB::table('discussionbridge_publications')->first()->source_revision);
        $this->assertFileExists($path);
    }

    public function test_existing_flat_entry_and_sql_mapping_restore_after_cache_invalidation_failure(): void
    {
        $record = $this->record(1, '<p>First source body</p>');
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('records')->with(1, null)->andReturnUsing(function () use (&$record) {
            return ['bridge_records' => [$record], 'pagination' => ['page' => 1, 'pages' => 1, 'total' => 1, 'snapshot' => 'native-flat']];
        });
        $this->app->instance(BridgeClient::class, $client);
        $fail = false;
        $freshness = Mockery::mock(PublicationFreshness::class);
        $freshness->shouldReceive('refresh')->andReturnUsing(function () use (&$fail) {
            if ($fail) {
                $fail = false;
                throw new \RuntimeException('Synthetic invalidation interruption');
            }
        });
        $this->app->instance(PublicationFreshness::class, $freshness);
        $sync = app(PublicationSynchronizer::class);
        $this->assertSame(1, $sync->synchronize()['created']);
        $row = DB::table('discussionbridge_publications')->first();
        $entry = Entry::find($row->entry_id);
        $before = $entry->data()->all();
        $path = $entry->path();
        $bytes = file_get_contents($path);
        $record = $this->record(2, '<p>Second body must roll back</p>');
        $fail = true;
        $summary = $sync->synchronize();
        $this->assertSame(1, $summary['failed'], implode(', ', $summary['errors']));
        $this->assertSame(0, $summary['updated']);
        $this->assertStringContainsString('Synthetic invalidation interruption', $summary['errors'][0]);
        $this->assertSame($before, Entry::find($row->entry_id)->data()->all());
        $this->assertSame($bytes, file_get_contents($path));
        $this->assertSame('post:149:version:1', DB::table('discussionbridge_publications')->first()->source_revision);
        $this->assertSame(1, $sync->synchronize()['updated']);
        $this->assertSame($row->entry_id, DB::table('discussionbridge_publications')->first()->entry_id);
    }

    public function test_native_save_veto_does_not_advance_the_mapping_or_claim_success(): void
    {
        $record = $this->record(1, '<p>First source body</p>');
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('records')->with(1, null)->andReturnUsing(function () use (&$record) {
            return ['bridge_records' => [$record], 'pagination' => ['page' => 1, 'pages' => 1, 'total' => 1, 'snapshot' => 'native-flat']];
        });
        $this->app->instance(BridgeClient::class, $client);
        $freshness = Mockery::mock(PublicationFreshness::class);
        $freshness->shouldReceive('refresh')->twice();
        $this->app->instance(PublicationFreshness::class, $freshness);
        $sync = app(PublicationSynchronizer::class);
        $this->assertSame(1, $sync->synchronize()['created']);
        $row = DB::table('discussionbridge_publications')->first();
        $path = Entry::find($row->entry_id)->path();
        $bytes = file_get_contents($path);
        \Illuminate\Support\Facades\Event::listen(\Statamic\Events\EntrySaving::class, function ($event) {
            return $event->entry->get('discussionbridge_source_revision') === 'post:149:version:2' ? false : null;
        });
        $record = $this->record(2, '<p>Vetoed source body</p>');
        $summary = $sync->synchronize();
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(0, $summary['updated']);
        $this->assertStringContainsString('save was refused', $summary['errors'][0]);
        $this->assertSame($bytes, file_get_contents($path));
        $this->assertSame('post:149:version:1', DB::table('discussionbridge_publications')->first()->source_revision);
    }

    private function record(int $version, string $content): array
    {
        return [
            'resource_id' => '11111111-1111-4111-8111-111111111111', 'direction' => 'from_discourse', 'state' => 'healthy',
            'title' => 'Native Flat source', 'topic_id' => 53, 'content_html' => $content,
            'source' => ['platform' => 'discourse', 'origin' => 'https://forum.example', 'topic_id' => 53,
                'topic_url' => 'https://forum.example/t/source/53', 'post_id' => 149, 'post_number' => 1,
                'post_version' => $version, 'revision' => 'post:149:version:'.$version,
                'author' => ['name' => 'Author', 'profile_url' => 'https://forum.example/u/author']],
            'bindings' => [['role' => 'presentation', 'state' => 'active', 'canonical_url' => 'https://statamic.example/discussionbridge/source', 'native_materialization' => true]],
        ];
    }
}
