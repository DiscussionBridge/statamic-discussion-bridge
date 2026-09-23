<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\HtmlSanitizer;

class HtmlSanitizerTest extends TestCase
{
    public function test_it_keeps_bounded_content_and_removes_active_markup(): void
    {
        $html = '<p onclick="bad()">Hello <strong>world</strong><script>alert(1)</script>'
            .'<a href="javascript:bad()" style="color:red">bad</a>'
            .'<a href="https://safe.example/path" target="_blank">safe</a></p>';
        $result = (new HtmlSanitizer())->sanitize($html);

        $this->assertStringContainsString('<strong>world</strong>', $result);
        $this->assertStringContainsString('https://safe.example/path', $result);
        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('target=', $result);
    }

    public function test_it_rejects_oversized_content(): void
    {
        $sanitizer = new HtmlSanitizer();

        $this->assertSame(
            str_repeat('x', HtmlSanitizer::MAX_HTML_BYTES),
            $sanitizer->sanitize(str_repeat('x', HtmlSanitizer::MAX_HTML_BYTES)),
        );
        $this->assertSame('', $sanitizer->sanitize(str_repeat('x', HtmlSanitizer::MAX_HTML_BYTES + 1)));
    }

    public function test_it_preserves_portable_media_and_removes_discourse_controls(): void
    {
        $html = '<p>[discotoc]</p><div data-theme-toc="true"></div>'
            .'<div class="lightbox-wrapper"><a href="https://forum.example/image.svg"><img src="https://forum.example/image.svg" alt="Flow" width="960" height="320"><div class="meta"><span>Flow</span><span>960×320 1.71 KB</span></div></a></div>'
            .'<table><thead><tr><th>Bridge</th></tr></thead><tbody><tr><td>Statamic</td></tr></tbody></table>';
        $result = (new HtmlSanitizer())->sanitize($html);

        $this->assertStringContainsString('<img src="https://forum.example/image.svg" alt="Flow" width="960" height="320">', $result);
        $this->assertStringContainsString('<table>', $result);
        $this->assertStringNotContainsString('[discotoc]', $result);
        $this->assertStringNotContainsString('data-theme-toc', $result);
        $this->assertStringNotContainsString('1.71 KB', $result);
    }
}
