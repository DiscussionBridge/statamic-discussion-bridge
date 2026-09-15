<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Http\Controllers\PublicationSyncController;
use CodeWorksLabs\DiscussionBridgeStatamic\Http\Controllers\StaticGenerationController;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationSynchronizer;
use CodeWorksLabs\DiscussionBridgeStatamic\StaticSite\StaticSiteGenerator;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Statamic\Statamic;
use Statamic\Facades\Utility;

class ControlPanelUtilityTest extends TestCase
{
    public function test_it_registers_the_permissioned_discussionbridge_utility(): void
    {
        Utility::boot();

        $utility = Utility::find('discussionbridge');

        $this->assertNotNull($utility);
        $this->assertSame('DiscussionBridge', $utility->title());
        $this->assertSame('discussionbridge::utility', $utility->view());
        $this->assertNotNull($utility->routes());
        $data = $utility->viewData(request());
        $this->assertSame('0.2.0-alpha.27', $data['adapterVersion']);
        $this->assertSame('dbc_0123456789abcdef01234567', $data['connectionId']);
        $this->assertSame(0, $data['publicationCount']);
        $this->assertArrayHasKey('lastResult', $data);
        $this->assertFalse($data['staticGenerationAvailable']);
        $this->assertArrayHasKey('lastGenerationResult', $data);

        $html = view($utility->view(), $data)->render();
        $this->assertStringContainsString('DiscussionBridge for Statamic', $html);
        $this->assertStringContainsString('Connection ready', $html);
        $this->assertStringContainsString('Publish from Discourse', $html);
        $this->assertStringContainsString('Synchronize now', $html);
        $this->assertStringContainsString('no duplicate pages are created', $html);
        $this->assertStringContainsString('Static site generation', $html);
        $this->assertStringContainsString('Not active for this profile', $html);
        $this->assertStringContainsString('Regenerate static site', $html);
        $this->assertStringContainsString('This Statamic profile renders dynamically', $html);
        $this->assertStringContainsString('class="db-utility__metrics"', $html);
        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringNotContainsString((string) config('discussionbridge.secret_file'), $html);

        $styles = Statamic::availableStyles(request());
        $this->assertArrayHasKey('statamic-discussion-bridge', $styles);
        $this->assertStringContainsString('control-panel.css?v=', $styles['statamic-discussion-bridge'][0]);
        $this->assertFileExists(__DIR__.'/../resources/css/control-panel.css');
        $this->assertStringContainsString('.db-utility__action button {', (string) file_get_contents(__DIR__.'/../resources/css/control-panel.css'));
    }

    public function test_control_panel_static_generation_persists_a_safe_last_run_summary(): void
    {
        $generator = Mockery::mock(StaticSiteGenerator::class);
        $generator->shouldReceive('available')->once()->andReturnTrue();
        $generator->shouldReceive('generate')->once()->andReturn([
            'prepared' => true,
            'generated' => true,
        ]);

        request()->headers->set('referer', 'https://statamic.example/cp/utilities/discussionbridge');
        app(StaticGenerationController::class)($generator);

        $lastResult = Cache::get(StaticGenerationController::LAST_RESULT_CACHE_KEY);
        $this->assertTrue($lastResult['succeeded']);
        $this->assertTrue($lastResult['prepared']);
        $this->assertTrue($lastResult['generated']);
        $this->assertNotEmpty($lastResult['completed_at']);
        $this->assertArrayNotHasKey('output', $lastResult);
    }

    public function test_control_panel_synchronization_persists_a_safe_last_run_summary(): void
    {
        $synchronizer = Mockery::mock(PublicationSynchronizer::class);
        $synchronizer->shouldReceive('synchronize')->once()->andReturn([
            'created' => 0,
            'updated' => 1,
            'unchanged' => 0,
            'skipped' => 1,
            'failed' => 0,
            'errors' => [],
        ]);

        request()->headers->set('referer', 'https://statamic.example/cp/utilities/discussionbridge');
        app(PublicationSyncController::class)($synchronizer);

        $lastResult = Cache::get(PublicationSyncController::LAST_RESULT_CACHE_KEY);
        $this->assertTrue($lastResult['succeeded']);
        $this->assertSame(1, $lastResult['updated']);
        $this->assertSame(1, $lastResult['skipped']);
        $this->assertNotEmpty($lastResult['completed_at']);
        $this->assertArrayNotHasKey('secret', $lastResult);
    }
}
