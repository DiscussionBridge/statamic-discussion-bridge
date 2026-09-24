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
        $client->shouldReceive('records')->with(1, null)->andReturn([
            'bridge_records' => [],
            'pagination' => ['page' => 1, 'pages' => 1, 'total' => 0, 'snapshot' => 'snapshot-one'],
        ])->byDefault();
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

    public function test_static_build_gate_uses_prepared_bounded_transaction_without_full_feed_sweep(): void
    {
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldNotReceive('records');
        $this->app->instance(BridgeClient::class, $client);
        $this->writeTransaction('prepared', [[
            'resource_id' => '11111111-1111-4111-8111-111111111111',
            'phase' => 'prepared',
        ]]);

        $this->artisan('discussionbridge:ssg-prepare')
            ->expectsOutputToContain('"publication_sync":"bounded_transaction"')
            ->assertSuccessful();
    }

    public function test_static_build_gate_fails_closed_for_incomplete_transaction(): void
    {
        $this->writeTransaction('finalizing', [[
            'resource_id' => '11111111-1111-4111-8111-111111111111',
            'phase' => 'prepared',
        ]]);

        $this->artisan('discussionbridge:ssg-prepare')
            ->expectsOutputToContain('transaction is not ready for static generation')
            ->assertFailed();
    }

    private function writeTransaction(string $phase, array $items): void
    {
        file_put_contents(config('discussionbridge.ssg_transaction_file'), json_encode([
            'schema_version' => 1,
            'transaction_id' => str_repeat('a', 32),
            'phase' => $phase,
            'created_at' => '2026-09-24T00:00:00Z',
            'updated_at' => '2026-09-24T00:00:00Z',
            'items' => $items,
        ], JSON_THROW_ON_ERROR));
    }
}
