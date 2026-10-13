<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use Illuminate\Support\Facades\DB;
use Mockery;
use CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns\ManagesPresentationFiles;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets;

class PrepareStaticBuildTest extends TestCase
{
    use ManagesPresentationFiles;
    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatePresentationFiles();
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('records')->with(1, null)->andReturn([
            'bridge_records' => [],
            'pagination' => ['page' => 1, 'pages' => 1, 'total' => 0, 'snapshot' => 'snapshot-one'],
        ]);
        $this->app->instance(BridgeClient::class, $client);
    }

    protected function tearDown(): void
    {
        $this->restorePresentationFiles();
        parent::tearDown();
    }

    public function test_static_build_gate_succeeds_with_no_unresolved_deliveries(): void
    {
        $this->artisan('discussionbridge:ssg-prepare')->assertSuccessful();
        foreach ($this->optionalPresentationPaths() as $target) {
            $this->assertFileDoesNotExist($target);
        }
    }

    public function test_static_preparation_makes_only_selected_renderer_available_to_public_copy(): void
    {
        config(['discussionbridge.rich_content' => true]);
        $this->artisan('discussionbridge:ssg-prepare')->assertSuccessful();
        PresentationAssets::verifyRich();
        $output = sys_get_temp_dir().'/discussionbridge-static-renderer-'.bin2hex(random_bytes(6)).'.js';
        try {
            $this->assertTrue(copy($this->richTarget(), $output));
            $this->assertSame(hash_file('sha256', $this->richTarget()), hash_file('sha256', $output));
        } finally {
            @unlink($output);
        }
        foreach ($this->optionalPresentationPaths() as $target) {
            if (! in_array($target, PresentationAssets::richPaths(), true)) {
                $this->assertFileDoesNotExist($target);
            }
        }
    }

    public function test_static_preparation_preserves_custom_asset_and_requires_explicit_refresh(): void
    {
        config(['discussionbridge.rich_content' => true]);
        $this->writePresentationFile($this->richTarget(), '// Operator-owned renderer');
        $this->artisan('discussionbridge:ssg-prepare')
            ->expectsOutputToContain('Preserve operator changes')->assertFailed();
        $this->assertSame('// Operator-owned renderer', file_get_contents($this->richTarget()));
        config(['discussionbridge.rich_content' => false]);
        $this->artisan('discussionbridge:ssg-prepare')->assertSuccessful();
        $this->assertSame('// Operator-owned renderer', file_get_contents($this->richTarget()));
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
