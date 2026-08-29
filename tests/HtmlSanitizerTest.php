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
        $this->assertSame('', (new HtmlSanitizer())->sanitize(str_repeat('x', 65537)));
    }
}
