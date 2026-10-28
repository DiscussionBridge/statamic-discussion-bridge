<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\DeliveryPresenter;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\PresentationChrome;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\TemplateIntegration;
use Illuminate\Support\Facades\DB;
use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** Real template verifier/parser/view finder; disposable Testbench application, no native repository. */
class TemplateModeBindingTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir().'/db-statamic-mode-'.bin2hex(random_bytes(10));
        if (file_exists($this->fixtureRoot) || ! mkdir($this->fixtureRoot, 0700)) {
            throw new RuntimeException('Cannot create an exclusive mode fixture.');
        }
        foreach (['bootstrap/cache', 'config', 'resources/views', 'storage/framework/views', 'storage/framework/cache', 'storage/logs'] as $directory) {
            mkdir($this->fixtureRoot.'/'.$directory, 0700, true);
        }
        file_put_contents($this->fixtureRoot.'/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        parent::setUp();
        view()->getFinder()->addExtension('antlers.html');
    }

    protected function getApplicationBasePath()
    {
        return $this->fixtureRoot;
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('view.paths', [$this->fixtureRoot.'/resources/views']);
        $app['config']->set('view.compiled', $this->fixtureRoot.'/storage/framework/views');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('logging.default', 'null');
        $app['config']->set('discussionbridge.forum_url', 'https://forum.example');
        $app['config']->set('discussionbridge.site_origin', 'https://statamic.example');
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if (isset($this->fixtureRoot) && is_dir($this->fixtureRoot)) {
                $resolved = realpath($this->fixtureRoot);
                $expected = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.basename($this->fixtureRoot);
                if ($resolved !== $expected || ! preg_match('/\Adb-statamic-mode-[a-f0-9]{20}\z/', basename($resolved))) {
                    throw new RuntimeException('Refusing cleanup outside the newly owned fixture.');
                }
                $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($entries as $entry) {
                    if ($entry->isLink()) {
                        throw new RuntimeException('Refusing linked fixture cleanup.');
                    }
                    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
                }
                rmdir($resolved);
            }
        }
    }

    public static function genericWrongModes(): array
    {
        $cases = [];
        foreach (['statamic_flat', 'statamic_db', 'statamic_ssg'] as $profile) {
            foreach (['simple', 'full'] as $mode) {
                $cases[$profile.'-'.$mode] = [$profile, $mode];
            }
        }
        return $cases;
    }

    #[DataProvider('genericWrongModes')]
    public function test_generic_to_discussion_does_not_certify_simple_or_full(string $profile, string $mode): void
    {
        config(['discussionbridge.cms_profile' => $profile]);
        $this->template('generic', '{{ discussionbridge:article entry="{id}" }}{{ discussionbridge:discussion entry="{id}" }}');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('integration is incomplete');
        app(TemplateIntegration::class)->verify('generic', 'to_discourse', $mode);
    }

    public function test_generic_interactive_wiring_preserves_the_real_delivered_topic_renderer(): void
    {
        $this->template('generic', '{{ discussionbridge:article }}{{ discussionbridge:discussion }}');
        app(TemplateIntegration::class)->verify('generic', 'to_discourse', 'interactive');
        $rows = collect([(object) ['topic_id' => '42', 'topic_url' => 'https://forum.example/t/source/42', 'forum_name' => 'Native forum']]);
        $query = Mockery::mock();
        DB::shouldReceive('table')->once()->with('discussionbridge_deliveries')->andReturn($query);
        $query->shouldReceive('where')->once()->with('entry_id', 'native-entry')->andReturnSelf();
        $query->shouldReceive('where')->once()->with('status', 'delivered')->andReturnSelf();
        $query->shouldReceive('limit')->once()->with(2)->andReturnSelf();
        $query->shouldReceive('get')->once()->andReturn($rows);
        $html = (new DeliveryPresenter(new Configuration(), new PresentationChrome()))->render('native-entry');
        $this->assertStringContainsString('"topicId":"42"', $html);
        $this->assertStringContainsString('"fullApp":true', $html);
        $this->assertStringContainsString('https://forum.example/javascripts/embed.js', $html);
    }

    public static function selectedModes(): array
    {
        return ['Simple' => ['simple'], 'Full' => ['full'], 'Interactive' => ['interactive']];
    }

    #[DataProvider('selectedModes')]
    public function test_explicit_mode_call_is_valid_for_both_directions_and_rejects_other_selections(string $mode): void
    {
        $this->template('explicit', '{{ discussionbridge:article entry="{id}" }}{{ discussionbridge:'.$mode.' topic="{discussionbridge_topic_id}" canonical="{discussionbridge_canonical_url}" }}');
        foreach (['to_discourse', 'from_discourse'] as $direction) {
            app(TemplateIntegration::class)->verify('explicit', $direction, $mode);
            $this->addToAssertionCount(1);
            foreach (array_diff(['simple', 'full', 'interactive'], [$mode]) as $wrongMode) {
                try {
                    app(TemplateIntegration::class)->verify('explicit', $direction, $wrongMode);
                    $this->fail('An explicit '.$mode.' call cannot certify '.$wrongMode.'.');
                } catch (RuntimeException $error) {
                    $this->assertStringContainsString('integration is incomplete', $error->getMessage());
                }
            }
        }
    }

    #[DataProvider('selectedModes')]
    public function test_generic_from_publication_remains_selected_mode_aware(string $mode): void
    {
        $this->template('publication', '{{ discussionbridge:article }}{{ discussionbridge:publication }}');
        app(TemplateIntegration::class)->verify('publication', 'from_discourse', $mode);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('selectedModes')]
    public function test_both_packaged_starters_remain_valid_for_each_selected_mode_and_direction(string $mode): void
    {
        foreach (array_keys(PresentationAssets::templatePaths()) as $index => $source) {
            $this->template('starter-'.$index, file_get_contents($source));
            foreach (['to_discourse', 'from_discourse'] as $direction) {
                app(TemplateIntegration::class)->verify('starter-'.$index, $direction, $mode);
                $this->addToAssertionCount(1);
            }
        }
    }

    private function template(string $name, string $source): void
    {
        $path = $this->fixtureRoot.'/resources/views/'.$name.'.antlers.html';
        $this->assertFileDoesNotExist($path);
        file_put_contents($path, $source);
        view()->getFinder()->flush();
    }
}
