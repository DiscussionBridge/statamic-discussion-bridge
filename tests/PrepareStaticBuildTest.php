<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use Illuminate\Support\Facades\DB;
use Mockery;

class PrepareStaticBuildTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('records')->with(1)->andReturn([
            'bridge_records' => [],
            'pagination' => ['page' => 1, 'pages' => 1],
        ]);
        $this->app->instance(BridgeClient::class, $client);
    }

    public function test_static_build_gate_succeeds_with_no_unresolved_deliveries(): void
    {
        $this->artisan('discussionbridge:ssg-prepare')->assertSuccessful();
    }

    public function test_static_build_gate_fails_closed_for_reconciliation_required_state(): void
    {
        DB::table('discussionbridge_deliveries')->insert([
            'entry_id' => 'entry-1',
            'collection_handle' => 'pages',
            'site_handle' => 'default',
            'external_id' => 'statamic:test:entry-1',
            'canonical_url' => 'https://statamic.example/entry-1',
            'status' => 'reconciliation_required',
            'correlation_id' => 'correlation-1',
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('discussionbridge:ssg-prepare')->assertFailed();
    }

    public function test_static_build_gate_rejects_unbounded_options(): void
    {
        $this->artisan('discussionbridge:ssg-prepare', ['--limit' => 101])->assertExitCode(2);
        $this->artisan('discussionbridge:ssg-prepare', ['--passes' => 0])->assertExitCode(2);
    }
}
