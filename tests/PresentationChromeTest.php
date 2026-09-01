<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\PageNavigation;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\PresentationChrome;

class PresentationChromeTest extends TestCase
{
    public function test_it_builds_native_navigation_and_full_interactive_discussion(): void
    {
        $content = (new PageNavigation())->render('<h2>First section</h2><p>One</p><h2>Second section</h2><p>Two</p>');
        $discussion = (new PresentationChrome())->discussion(42, 'https://forum.example/t/topic/42', 'https://forum.example', true);

        $this->assertStringContainsString('aria-label="On this page"', $content);
        $this->assertStringContainsString('href="#first-section"', $content);
        $this->assertStringContainsString('"topicId":42', $discussion);
        $this->assertStringContainsString('"fullApp":true', $discussion);
        $this->assertStringContainsString('discussion-bridge-source-presentation', $discussion);
        $this->assertStringContainsString('<span class="discussionbridge-credit__prefix">Connected by</span>', $discussion);
        $this->assertStringContainsString('class="discussionbridge-credit__brand"', $discussion);
        $this->assertStringContainsString('.discussionbridge-discussion{box-sizing:border-box;width:100%;max-width:48rem', $discussion);
        $this->assertStringContainsString('.discussionbridge-discussion iframe{display:block;box-sizing:border-box;width:100%;max-width:100%', $discussion);
        $this->assertStringContainsString('min-height:0', $discussion);
        $this->assertStringNotContainsString('height:800px', $discussion);
    }

    public function test_standard_embed_uses_only_the_plugin_free_canonical_url_contract(): void
    {
        $discussion = (new PresentationChrome())->standardEmbed(
            'https://statamic.example/discussionbridge/full',
            'https://forum.example',
        );

        $this->assertStringContainsString('"discourseEmbedUrl":"https://statamic.example/discussionbridge/full"', $discussion);
        $this->assertStringContainsString('"discourseUrl":"https://forum.example/"', $discussion);
        $this->assertStringNotContainsString('"topicId"', $discussion);
        $this->assertStringNotContainsString('"fullApp"', $discussion);
        $this->assertStringNotContainsString('X-DiscussionBridge', $discussion);
    }

    public function test_each_render_includes_styles_for_long_running_ssg_generation(): void
    {
        $chrome = new PresentationChrome();
        $discussion = $chrome->discussion(42, 'https://forum.example/t/topic/42', 'https://forum.example', false);
        $full = $chrome->standardEmbed('https://statamic.example/discussionbridge/full', 'https://forum.example');

        $this->assertSame(1, substr_count($discussion, 'data-discussionbridge-presentation'));
        $this->assertSame(1, substr_count($full, 'data-discussionbridge-presentation'));
        $this->assertStringContainsString('.discussionbridge-credit__prefix{opacity:.72}', $discussion);
        $this->assertStringContainsString('transition:color 160ms ease,opacity 160ms ease', $discussion);
    }
}
