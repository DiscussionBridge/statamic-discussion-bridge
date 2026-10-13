<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\ServiceProvider;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets;
use CodeWorksLabs\DiscussionBridgeStatamic\Tags\DiscussionBridge;
use CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns\ManagesPresentationFiles;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use Mockery;
use RuntimeException;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

class PresentationAssetsTest extends TestCase
{
    use ManagesPresentationFiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatePresentationFiles();
    }

    protected function tearDown(): void
    {
        $this->restorePresentationFiles();
        parent::tearDown();
    }

    public function test_normal_addon_publication_does_not_install_optional_files(): void
    {
        $normal = LaravelServiceProvider::pathsToPublish(ServiceProvider::class, 'statamic-discussion-bridge');
        $this->assertNotEmpty($normal);
        $this->assertEmpty(array_intersect_key($normal, $this->optionalPresentationPaths()));
        $this->artisan('vendor:publish', ['--tag' => 'statamic-discussion-bridge', '--force' => true])->assertSuccessful();
        foreach ($this->optionalPresentationPaths() as $target) {
            $this->assertFileDoesNotExist($target);
        }
        $this->assertSame('', PresentationAssets::markup());
    }

    public function test_optional_templates_publish_additively_and_preserve_operator_files(): void
    {
        $theme = resource_path('views/entry.antlers.html');
        $this->writePresentationFile($theme, '<main>Operator theme</main>');
        $paths = LaravelServiceProvider::pathsToPublish(ServiceProvider::class, PresentationAssets::TEMPLATES_TAG);
        $this->artisan('vendor:publish', ['--tag' => PresentationAssets::TEMPLATES_TAG])->assertSuccessful();
        foreach ($paths as $source => $target) {
            $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $target));
        }
        $custom = array_values($paths)[0];
        $this->writePresentationFile($custom, '<main>Customized partial</main>');
        $this->artisan('vendor:publish', ['--tag' => PresentationAssets::TEMPLATES_TAG])->assertSuccessful();
        $this->assertSame('<main>Customized partial</main>', file_get_contents($custom));
        $this->assertSame('<main>Operator theme</main>', file_get_contents($theme));
        $this->assertFileDoesNotExist($this->richTarget());
    }

    public function test_selected_rich_asset_uses_native_publication_and_remains_exact(): void
    {
        $this->artisan('vendor:publish', ['--tag' => PresentationAssets::RICH_TAG])->assertSuccessful();
        PresentationAssets::verifyRich();
        $this->assertSame('', PresentationAssets::markup());
        config(['discussionbridge.rich_content' => true]);
        $this->assertStringContainsString('<link rel="stylesheet" href="/discussionbridge/rich-content.css?v=', PresentationAssets::markup());
        $this->assertStringContainsString('<script src="/discussionbridge/rich-content.js?v=', PresentationAssets::markup());
        $this->writePresentationFile($this->richTarget(), '// Operator replacement');
        $this->artisan('vendor:publish', ['--tag' => PresentationAssets::RICH_TAG])->assertSuccessful();
        $this->assertSame('// Operator replacement', file_get_contents($this->richTarget()));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Preserve operator changes');
        PresentationAssets::verifyRich();
    }

    public function test_native_article_preserves_legacy_activation_until_explicit_choice_and_sanitizes_received_html(): void
    {
        $entry = Mockery::mock(EntryContract::class);
        $entry->shouldReceive('get')->with('content')->andReturn('<section><p onclick="bad()">Article</p><script>alert(1)</script></section><script src="/discussionbridge/rich-content.js" defer></script><script src="https://source.example/external.js" defer></script>');
        $entry->shouldReceive('get')->with('discussionbridge_native_publication')->andReturn(true);
        $entry->shouldReceive('get')->with('discussionbridge_source_status')->andReturn('active');
        $entry->shouldReceive('get')->with('discussionbridge_complete_content')->andReturn(false);
        Entry::shouldReceive('find')->with('native-entry')->andReturn($entry);
        $tag = (new DiscussionBridge())->setContext(['id' => 'native-entry'])->setParameters([]);
        config(['discussionbridge.rich_content' => null]);
        $legacy = $tag->article();
        $this->assertStringContainsString(PresentationAssets::LEGACY_MARKUP, $legacy);
        $this->assertStringNotContainsString('rich-content.css', $legacy);
        $this->assertStringNotContainsString('onclick', $legacy);
        $this->assertStringNotContainsString('alert(1)', $legacy);
        $this->assertStringNotContainsString('source.example/external.js', $legacy);
        config(['discussionbridge.rich_content' => false]);
        $disabled = $tag->article();
        $this->assertStringContainsString('Article', $disabled);
        $this->assertStringNotContainsString('/discussionbridge/rich-content.js', $disabled);
        config(['discussionbridge.rich_content' => true]);
        $enabled = $tag->article();
        $this->assertSame(1, substr_count($enabled, '/discussionbridge/rich-content.js'));
        $this->assertStringNotContainsString('source.example/external.js', $enabled);
    }

    public function test_from_article_has_its_own_render_bound_and_does_not_reinterpret_source_markdown(): void
    {
        config(['discussionbridge.rich_content' => null]);
        $content = '<p>'.str_repeat('x', 65500).' [literal](https://example.com)</p><aside><p>Source author</p></aside>';
        $entry = Mockery::mock(EntryContract::class);
        $entry->shouldReceive('get')->with('content')->andReturn($content);
        $entry->shouldReceive('get')->with('discussionbridge_native_publication')->andReturn(true);
        $entry->shouldReceive('get')->with('discussionbridge_source_status')->andReturn('active');
        $entry->shouldReceive('get')->with('discussionbridge_complete_content')->andReturn(false);
        Entry::shouldReceive('find')->with('large-native-entry')->andReturn($entry);
        $tag = (new DiscussionBridge())->setContext(['id' => 'large-native-entry'])->setParameters([]);
        $result = $tag->article();
        $this->assertStringContainsString('Source author', $result);
        $this->assertStringContainsString('[literal](https://example.com)', $result);
        $this->assertStringNotContainsString('Article unavailable', $result);
        $this->assertStringNotContainsString('<script', $result);
    }
}
