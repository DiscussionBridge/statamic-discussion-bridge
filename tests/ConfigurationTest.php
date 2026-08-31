<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use RuntimeException;

class ConfigurationTest extends TestCase
{
    public function test_canonical_page_url_accepts_same_site_absolute_and_relative_paths(): void
    {
        $configuration = app(Configuration::class);

        $this->assertSame('https://statamic.example/discussionbridge/full', $configuration->canonicalPageUrl('/discussionbridge/full'));
        $this->assertSame('https://statamic.example/discussionbridge/full', $configuration->canonicalPageUrl('https://statamic.example/discussionbridge/full'));
    }

    public function test_canonical_page_url_rejects_cross_origin_query_and_authority_forms(): void
    {
        $configuration = app(Configuration::class);

        foreach (['https://other.example/full', '/full?mode=1', '//other.example/full'] as $value) {
            try {
                $configuration->canonicalPageUrl($value);
                $this->fail('Expected invalid canonical URL to be rejected.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
