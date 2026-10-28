<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ContentPreservationLimitation;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\HtmlSanitizer;
use DOMDocument;
use DOMXPath;

class FromSemanticPreservationTest extends TestCase
{
    private function nativeDom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($document);
    }

    public function test_native_table_caption_groups_and_valid_spans_survive_without_active_attributes(): void
    {
        $html = '<table><caption>Revenue <strong>2026</strong></caption><colgroup><col span="2"></colgroup>'
            .'<thead><tr><th colspan="2" scope="colgroup">Region</th></tr></thead>'
            .'<tbody><tr><td rowspan="0">All remaining rows</td><td>Value</td></tr></tbody>'
            .'<tfoot><tr><td colspan="1000" rowspan="65534">Bounded spans</td></tr></tfoot></table>';
        $output = app(HtmlSanitizer::class)->sanitizeArticle($html, 'https://forum.example/t/source/101');
        $dom = $this->nativeDom($output);
        $this->assertSame('Revenue 2026', $dom->evaluate('string(//table/caption)'));
        $this->assertSame('2', $dom->evaluate('string(//col/@span)'));
        $this->assertSame('2', $dom->evaluate('string(//th/@colspan)'));
        $this->assertSame('colgroup', $dom->evaluate('string(//th/@scope)'));
        $this->assertSame('0', $dom->evaluate('string(//tbody/tr/td/@rowspan)'));
        $this->assertSame('65534', $dom->evaluate('string(//tfoot/tr/td/@rowspan)'));
        $this->assertSame(0, $dom->query('//*[@onclick]')->length);
        $invalid = app(HtmlSanitizer::class)->sanitize('<table><tr><td colspan="0" rowspan="65535">Unchanged text</td></tr></table>');
        $this->assertSame(0, $this->nativeDom($invalid)->query('//*[@colspan or @rowspan]')->length);
        $this->assertStringContainsString('Unchanged text', $invalid);
    }

    public function test_native_numbering_and_figure_caption_meaning_survive(): void
    {
        $html = '<figure><figcaption>Ranked results</figcaption><ol start="-5" reversed type="I">'
            .'<li value="-3">First</li><li>Second</li></ol></figure>';
        $dom = $this->nativeDom(app(HtmlSanitizer::class)->sanitizeArticle($html, 'https://forum.example/t/source/101'));
        $this->assertSame('Ranked results', $dom->evaluate('string(//figure/figcaption)'));
        $this->assertSame('-5', $dom->evaluate('string(//ol/@start)'));
        $this->assertSame('I', $dom->evaluate('string(//ol/@type)'));
        $this->assertSame(1, $dom->query('//ol[@reversed]')->length);
        $this->assertSame('-3', $dom->evaluate('string(//ol/li/@value)'));
    }

    public function test_relative_imported_references_resolve_from_actual_source_page_and_keep_image_meaning(): void
    {
        $html = '<figure><img src="/uploads/example.png" alt="Plot meaning" title="Native plot" width="640" height="480">'
            .'<figcaption>Actual image caption</figcaption></figure><a href="../related/202">Related article</a>';
        $dom = $this->nativeDom(app(HtmlSanitizer::class)->sanitizeArticle($html, 'https://forum.example/t/source/101'));
        $this->assertSame('https://forum.example/uploads/example.png', $dom->evaluate('string(//img/@src)'));
        $this->assertSame('Plot meaning', $dom->evaluate('string(//img/@alt)'));
        $this->assertSame('Actual image caption', $dom->evaluate('string(//figcaption)'));
        $this->assertSame('https://forum.example/t/related/202', $dom->evaluate('string(//a/@href)'));
    }

    public function test_unrepresented_media_requires_attention_instead_of_silent_complete_article_success(): void
    {
        foreach (['<video controls><source src="https://media.example/clip.mp4"><a href="https://media.example/clip.mp4">Download clip</a></video>',
            '<audio controls src="https://media.example/audio.mp3">Audio meaning</audio>',
            '<iframe src="https://provider.example/player">Provider meaning</iframe>',
            '<picture><source srcset="https://media.example/picture.webp"><img src="https://media.example/picture.png" alt="Image fallback"></picture>'] as $media) {
            try {
                app(HtmlSanitizer::class)->sanitizeArticle('<p>Source context remains pinned.</p>'.$media, 'https://forum.example/t/source/101');
                $this->fail('Unsupported media was silently declared a complete native article.');
            } catch (ContentPreservationLimitation $error) {
                $this->assertSame('native_media_unrepresented', $error->reason);
                $this->assertNotEmpty($error->lostFunction);
            }
        }
    }

    public static function activeFunctionCases(): array
    {
        return [
            ['<script>initializeWidget()</script>', 'script initialization'],
            ['<script type="module" src="https://source.example/widget.js"></script>', 'module initialization'],
            ['<script type=" TEXT/JAVASCRIPT ">initializeWidget()</script>', 'script initialization'],
            ['<button onclick="showSourceResult()">Result</button>', 'onclick'],
            ['<img src="https://source.example/image.png" onload="initializeFigure()">', 'onload'],
            ['<form action="https://source.example/search"><input name="query"><button>Search</button></form>', 'form'],
            ['<input type="range" min="1" max="10">', 'input'],
            ['<textarea>Native editable content</textarea>', 'textarea'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('activeFunctionCases')]
    public function test_actual_native_function_loss_is_specific_local_limitation_before_conversion(string $source, string $function): void
    {
        try {
            app(HtmlSanitizer::class)->sanitizeArticle('<p>Original context.</p>'.$source, 'https://forum.example/t/source/101');
            $this->fail('Lost native function was silently declared preserved.');
        } catch (ContentPreservationLimitation $error) {
            $this->assertSame('native_active_function_unrepresented', $error->reason);
            $this->assertStringContainsString($function, $error->lostFunction);
        }
    }

    public function test_supported_native_disclosure_survives_import_and_owned_article_sanitization(): void
    {
        $source = '<details open name="native-source-group"><summary>Open source explanation</summary>'
            .'<p>Complete body <strong>meaning</strong>.</p></details>';
        $first = app(HtmlSanitizer::class)->sanitizeArticle($source, 'https://forum.example/t/source/101');
        $second = app(HtmlSanitizer::class)->sanitize($first, null);
        foreach ([$first, $second] as $output) {
            $dom = $this->nativeDom($output);
            $this->assertSame(1, $dom->query('//details[@open]')->length);
            $this->assertSame('native-source-group', $dom->evaluate('string(//details/@name)'));
            $this->assertSame('Open source explanation', $dom->evaluate('string(//details/summary)'));
            $this->assertSame('Complete body meaning.', $dom->evaluate('string(//details/p)'));
        }
    }

    public function test_displayed_code_does_not_infer_active_function_loss(): void
    {
        $displayed = '<script>initializeWidget()</script> onclick="codeExample()"';
        $source = '<pre><code class="language-javascript">'.htmlspecialchars($displayed, ENT_QUOTES, 'UTF-8').'</code></pre>'
            .'<p>Native original retained.</p>';
        $output = app(HtmlSanitizer::class)->sanitizeArticle($source, 'https://forum.example/t/source/101');
        $dom = $this->nativeDom($output);
        $this->assertSame($displayed, $dom->evaluate('string(//pre/code)'));
        $this->assertSame('language-javascript', $dom->evaluate('string(//pre/code/@class)'));
        $this->assertStringContainsString('Native original retained.', $output);
    }

    public static function inertDataCases(): array
    {
        return [['application/json'], ['application/ld+json'], ['text/javascript;charset=utf-8']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inertDataCases')]
    public function test_actual_nonempty_inert_data_loss_is_separate_from_execution_and_no_conversion_is_invented(string $type): void
    {
        $data = '{"label":"script onclick form","value":17}';
        try {
            app(HtmlSanitizer::class)->sanitizeArticle('<p>Native source context.</p><script type="'.$type.'">'.$data.'</script>',
                'https://forum.example/t/source/101');
            $this->fail('Actual nonempty source data was silently removed.');
        } catch (ContentPreservationLimitation $error) {
            $this->assertSame('native_data_block_unrepresented', $error->reason);
            $this->assertNull($error->lostFunction);
            $this->assertStringContainsString((string) strlen($data), $error->lostData);
            $this->assertStringContainsString(hash('sha256', $data), $error->lostData);
            $this->assertStringNotContainsString($data, $error->getMessage());
        }
    }
}
