<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativePreparationLimitation;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToPublication;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourcePreparation;
use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\SourcePublication;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;
use Statamic\Facades\Markdown;

/** Real configured native fields/renderers; no test preparation override. */
trait ExercisesNativePreparation
{
    private function preparationEntry(mixed $source, array $field)
    {
        config(['discussionbridge.collections' => ['discussionbridge'], 'discussionbridge.source_author_profile_url' => null]);
        $root = dirname(__DIR__, 2).'/.tmp/native-preparation-'.bin2hex(random_bytes(8));
        mkdir($root.'/blueprints', 0700, true);
        Blueprint::setDirectories($root.'/blueprints');
        Blueprint::makeFromFields(['content' => $field])->setHandle('native_preparation')
            ->setNamespace('collections.discussionbridge')->save();
        $entry = Entry::make()->collection('discussionbridge')->blueprint('native_preparation')
            ->locale('default')->slug('captured-article')->published(true)->data([
                'blueprint' => 'native_preparation', 'title' => 'Native field title', 'content' => $source,
                'discussionbridge_publish' => true,
            ]);
        $this->assertTrue($entry->save());
        return $entry;
    }

    private function prepared()
    {
        return app(ToSourcePreparation::class)->capture(DB::table('discussionbridge_deliveries')->sole());
    }

    public function test_native_preparation_uses_named_parser_and_real_field_options(): void
    {
        Markdown::extend('article-configured', fn ($parser) => $parser->withStatamicDefaults()->withMarkupEscaping());
        $source = "First line\nSecond <b>native</b> line.";
        $entry = $this->preparationEntry($source, ['type' => 'markdown', 'parser' => 'article-configured',
            'automatic_line_breaks' => true]);
        $native = $entry->augmentedValue('content')->value();
        $this->assertStringContainsString('&lt;b&gt;', $native);
        $this->assertStringContainsString('<br', $native);
        $this->assertNotSame(Markdown::parse($source), $native);
        $prepared = $this->prepared();
        $this->assertSame($native, $prepared['full_html']);
        $this->assertSame($source, base64_decode($prepared['native_source']['content_base64'], true));
        $this->assertSame($source, $entry->get('content'));
    }

    public function test_native_preparation_preserves_configured_deferred_antlers_context(): void
    {
        $source = 'Hello {{ title }}. [original target](../guide)';
        $entry = $this->preparationEntry($source, ['type' => 'markdown', 'antlers' => true]);
        $prepared = $this->prepared();
        $this->assertStringContainsString('Hello Native field title.', $prepared['full_html']);
        $this->assertStringContainsString('href="https://statamic.example/guide"', $prepared['full_html']);
        $this->assertSame($source, $entry->get('content'));
        $this->assertSame($entry->absoluteUrl(), $prepared['native_source']['article_url']);
    }

    public function test_native_preparation_renders_complete_bard_without_sets_and_retains_typed_original(): void
    {
        $source = [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First native Bard paragraph.']]],
            ['type' => 'image', 'attrs' => ['src' => '../media/bard.png', 'alt' => 'Original Bard image']],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Final native Bard paragraph.']]],
        ];
        $entry = $this->preparationEntry($source, ['type' => 'bard', 'sets' => []]);
        $this->assertIsString($entry->augmentedValue('content')->value());
        $prepared = $this->prepared();
        $this->assertStringContainsString('First native Bard paragraph.', $prepared['full_html']);
        $this->assertStringContainsString('Final native Bard paragraph.', $prepared['full_html']);
        $this->assertStringContainsString('src="https://statamic.example/media/bard.png"', $prepared['full_html']);
        $this->assertStringContainsString('alt="Original Bard image"', $prepared['full_html']);
        $this->assertSame('native_json', $prepared['native_source']['content_encoding']);
        $this->assertSame($source, json_decode(base64_decode($prepared['native_source']['content_base64'], true), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($source, $entry->get('content'));
        $this->assertSame(hash('sha256', $prepared['full_html']), $prepared['record']['source_publication']['content_sha256']);
    }

    public function test_native_preparation_preserves_passive_native_html_and_captured_media_srcset_targets(): void
    {
        $source = '<figure><img src="../media/a.png" srcset="../media/a.png 1x, images/a2.png 2x" alt="Original">'
            .'<figcaption>Native caption</figcaption></figure><details open name="group"><summary>Inspect</summary>'
            .'<ol start="3"><li value="4">Native list</li></ol></details><table><caption>Native table</caption>'
            .'<tr><th rowspan="2">Heading</th><td colspan="2">Spanning value</td></tr></table>'
            .'<pre><code>&lt;script&gt;displayed, not executed&lt;/script&gt;</code></pre>';
        $entry = $this->preparationEntry($source, ['type' => 'bard', 'save_html' => true]);
        $this->assertSame($source, $entry->augmentedValue('content')->value());
        $prepared = $this->prepared();
        foreach (['src="https://statamic.example/media/a.png"',
            'srcset="https://statamic.example/media/a.png 1x, https://statamic.example/discussionbridge/images/a2.png 2x"',
            '<figcaption>Native caption</figcaption>', 'name="group"', 'start="3"', 'value="4"',
            'rowspan="2"', 'colspan="2"', '&lt;script&gt;displayed, not executed&lt;/script&gt;'] as $retained) {
            $this->assertStringContainsString($retained, $prepared['full_html']);
        }
        $this->assertSame($source, $entry->get('content'));
        $this->assertSame(strlen($prepared['full_html']), $prepared['record']['source_publication']['content_bytes']);
    }

    public function test_native_preparation_full_hash_precedes_generated_excerpt_and_native_original_is_unchanged(): void
    {
        $source = '[original target](../original) '.str_repeat('Complete native é article. ', 8000).'FINAL-SOURCE-END';
        $entry = $this->preparationEntry($source, ['type' => 'markdown']);
        $prepared = $this->prepared();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $record = app(ToPublication::class)->record($prepared, $row, (object) ['publication_lane' => 'statamic-alpha'], null,
            app(CmsClient::class)->codec());
        $this->assertGreaterThan(49152, strlen($prepared['full_html']));
        $this->assertSame('generated_excerpt', $record->representation->kind);
        $this->assertSame(strlen($prepared['full_html']), $record->source_content_bytes);
        $this->assertSame(hash('sha256', $prepared['full_html']), $record->source_content_sha256);
        $this->assertStringContainsString('FINAL-SOURCE-END', $prepared['full_html']);
        $this->assertStringContainsString('href="https://statamic.example/original"', $prepared['full_html']);
        $this->assertSame($source, $entry->get('content'));
    }

    public function test_native_preparation_ordered_bard_sets_require_actual_presentation_instead_of_silent_serialization(): void
    {
        config(['discussionbridge.enabled' => false]);
        $source = [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before set.']]],
            ['type' => 'set', 'attrs' => ['id' => 'native-set-one', 'values' => ['type' => 'notice', 'message' => 'Actual set meaning']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'After set.']]],
        ];
        $entry = $this->preparationEntry($source, ['type' => 'bard', 'sets' => [
            'main' => ['display' => 'Main', 'sets' => ['notice' => ['display' => 'Notice', 'fields' => [
                ['handle' => 'message', 'field' => ['type' => 'text']],
            ]]]],
        ]]);
        $native = $entry->augmentedValue('content')->value();
        $this->assertIsArray($native);
        $this->assertCount(3, $native);
        try {
            SourcePublication::fromEntry($entry, app(Configuration::class));
            $this->fail('Ordered native set data was silently turned into an invented article.');
        } catch (NativePreparationLimitation $error) {
            $this->assertSame('native_ordered_set_presentation_unbound', $error->reason);
        }
        $this->assertSame($source, $entry->get('content'));
        $this->assertTrue($entry->published());
        $this->assertTrue($entry->get('discussionbridge_publish'));
    }

    public static function unsupportedNativeArticleCases(): array
    {
        return [
            ['<p>Text</p><script>window.nativeAction = 1;</script>', 'native_article_function_binding_unavailable'],
            ['<p>Text</p><script type="application/json">{"native":"data"}</script>', 'native_article_data_binding_unavailable'],
            ['<video controls poster="../poster.png"><source src="../clip.mp4"></video>', 'native_article_video_binding_unavailable'],
            ['<img src="../photo.png" srcset="data:image/png;base64,AAAA 1x">', 'native_srcset_data_representation_unavailable'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedNativeArticleCases')]
    public function test_native_preparation_reports_specific_loss_without_reclassifying_source_or_blocking_supported_content(string $source, string $reason): void
    {
        // No bridge listener may fabricate acceptance or change native content.
        config(['discussionbridge.enabled' => false]);
        $entry = $this->preparationEntry($source, ['type' => 'bard', 'save_html' => true]);
        try {
            $prepared = SourcePublication::fromEntry($entry, app(Configuration::class));
            app(\CodeWorksLabs\DiscussionBridgeStatamic\Delivery\NativeArticleProof::class)->qualify($prepared['full_html']);
            $this->fail('Actual unavailable function/data was silently qualified.');
        } catch (NativePreparationLimitation $error) {
            $this->assertSame($reason, $error->reason);
            $this->assertSame('native_source_html_unqualified', $error->failureCode);
        }
        $this->assertSame($source, $entry->get('content'));
        $this->assertTrue($entry->published());
        $this->assertTrue($entry->get('discussionbridge_publish'));
        config(['discussionbridge.enabled' => true]);
        $supported = $this->preparationEntry('Independent supported native article.', ['type' => 'markdown']);
        $this->assertStringContainsString('Independent supported native article.', $this->prepared()['full_html']);
        $this->assertNotSame((string) $entry->id(), (string) $supported->id());
    }
}
