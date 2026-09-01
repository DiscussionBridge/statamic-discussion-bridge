<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

class StarterKitTest extends TestCase
{
    public function test_simple_and_full_use_the_native_article_presenter(): void
    {
        $template = file_get_contents(dirname(__DIR__).'/starter-kit/entry.antlers.html');

        $this->assertIsString($template);
        $this->assertStringContainsString(
            "{{ if discussionbridge_mode == \"simple\" }}\n    {{ discussionbridge:article entry=\"{id}\" }}\n    {{ discussionbridge:simple topic=\"{discussionbridge_topic_id}\" }}",
            $template,
        );
        $this->assertStringContainsString(
            "{{ elseif discussionbridge_mode == \"full\" }}\n    {{ discussionbridge:article entry=\"{id}\" }}\n    {{ discussionbridge:full canonical=\"{discussionbridge_canonical_url}\" }}",
            $template,
        );
        $this->assertStringContainsString(
            "{{ elseif discussionbridge_native_publication }}\n  {{ discussionbridge:article entry=\"{id}\" }}\n  {{ discussionbridge:publication entry=\"{id}\" }}",
            $template,
        );
    }

    public function test_ssg_template_preserves_static_copy_wrapper_and_native_publications(): void
    {
        $template = file_get_contents(dirname(__DIR__).'/starter-kit/entry-ssg.antlers.html');

        $this->assertIsString($template);
        $this->assertStringContainsString('{{ elseif discussionbridge_native_publication }}', $template);
        $this->assertStringContainsString('<article class="db-copy">{{ content }}</article>', $template);
    }
}
