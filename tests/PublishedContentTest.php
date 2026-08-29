<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\PublishedContent;
use Mockery;
use RuntimeException;
use Statamic\Contracts\Entries\Entry as EntryContract;

class PublishedContentTest extends TestCase
{
    public function test_it_renders_a_bounded_markdown_snapshot(): void
    {
        $entry = Mockery::mock(EntryContract::class);
        $entry->shouldReceive('get')->with('content')->andReturn("## Useful content\n\nA real article.");

        $html = PublishedContent::fromEntry($entry);

        $this->assertStringContainsString('<h2>Useful content</h2>', $html);
        $this->assertStringContainsString('<p>A real article.</p>', $html);
    }

    public function test_it_rejects_missing_or_oversized_content(): void
    {
        foreach ([null, '  ', str_repeat('x', PublishedContent::MAX_HTML_BYTES + 1)] as $source) {
            $entry = Mockery::mock(EntryContract::class);
            $entry->shouldReceive('get')->with('content')->andReturn($source);

            try {
                PublishedContent::fromEntry($entry);
                $this->fail('Invalid published content was accepted.');
            } catch (RuntimeException $error) {
                $this->assertSame('Statamic published content is invalid.', $error->getMessage());
            }
        }
    }
}
