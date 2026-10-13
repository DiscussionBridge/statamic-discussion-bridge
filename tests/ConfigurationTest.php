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

        foreach (['https://other.example/full', '/full?mode=1', '/full#section', 'https://user@statamic.example/full', 'https://user:password@statamic.example/full', '//other.example/full', "https://statamic.example/full\n", 'https://statamic.example\\full'] as $value) {
            try {
                $configuration->canonicalPageUrl($value);
                $this->fail('Expected invalid canonical URL to be rejected.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_origin_and_author_profile_reject_each_forbidden_component_and_other_ports(): void
    {
        $configuration = app(Configuration::class);
        foreach (['https://user@forum.example', 'https://user:password@forum.example', 'https://forum.example?x=1', 'https://forum.example#x', "https://forum.example\n", 'https://forum.example\\'] as $value) {
            config(['discussionbridge.forum_url' => $value]);
            try {
                $configuration->forumOrigin();
                $this->fail('Expected invalid origin rejection.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['https://user@statamic.example/author', 'https://statamic.example/author?x=1', 'https://statamic.example/author#x', 'https://statamic.example:8443/author'] as $value) {
            config(['discussionbridge.source_author_profile_url' => $value]);
            try {
                $configuration->sourceAuthor();
                $this->fail('Expected invalid author URL rejection.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_connection_secret_and_lane_match_receiver_admission_grammar(): void
    {
        $configuration = app(Configuration::class);
        $secretPath = (string) config('discussionbridge.secret_file');

        foreach ([str_repeat('s', 31), str_repeat('é', 129), str_repeat('s', 32)."\ninside"] as $invalid) {
            file_put_contents($secretPath, $invalid);
            try {
                $configuration->secret();
                $this->fail('Expected invalid connection secret to be rejected.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        file_put_contents($secretPath, str_repeat('s', 32));
        config()->set('discussionbridge.lane', 'Bad Lane');
        $this->expectException(RuntimeException::class);
        $configuration->lane();
    }
}
