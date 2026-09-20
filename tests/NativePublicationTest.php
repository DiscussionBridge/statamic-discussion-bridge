<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\NativePublication;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PlatformCatalog;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use RuntimeException;
use Statamic\Facades\Collection;

class NativePublicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Collection::make('pages')->routes(['default' => '/{slug}'])->save();
    }

    public function test_it_requires_explicit_native_authority_and_exact_source_identity(): void
    {
        $validator = new NativePublication(app(Configuration::class), app(PlatformCatalog::class));
        $record = $this->record();
        $publication = $validator->fromRecord($record);

        $this->assertSame('the-bridge-publishes-everywhere', $publication['slug']);
        $this->assertSame('/the-bridge-publishes-everywhere', $publication['path']);
        $this->assertNull($publication['parent_uri']);
        $this->assertSame('post:149:version:1', $publication['source_revision']);
        $this->assertSame('https://statamic.example/the-bridge-publishes-everywhere', $publication['canonical_url']);

        $record = $this->record();
        $record['bindings'][0]['canonical_url'] = 'https://statamic.example/from-the-bridge/the-bridge-publishes-everywhere';
        $nested = $validator->fromRecord($record);
        $this->assertSame('/from-the-bridge/the-bridge-publishes-everywhere', $nested['path']);
        $this->assertSame('/from-the-bridge', $nested['parent_uri']);

        $record['bindings'][0]['native_materialization'] = false;
        $this->assertNull($validator->fromRecord($record));

        $record = $this->record();
        $record['source']['origin'] = 'https://other.example';
        $this->expectException(RuntimeException::class);
        $validator->fromRecord($record);
    }

    public function test_it_rejects_destination_escape_and_ambiguous_authority(): void
    {
        $validator = new NativePublication(app(Configuration::class), app(PlatformCatalog::class));
        $record = $this->record();
        $record['bindings'][0]['canonical_url'] = 'https://statamic.example/from-the-bridge/%2e%2e/escape';
        try {
            $validator->fromRecord($record);
            $this->fail('Expected path rejection.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('path', $error->getMessage());
        }

        $record = $this->record();
        $record['bindings'][] = $record['bindings'][0];
        $this->expectException(RuntimeException::class);
        $validator->fromRecord($record);
    }

    public function test_it_accepts_only_an_exact_verified_url_migration_pair(): void
    {
        $validator = new NativePublication(app(Configuration::class), app(PlatformCatalog::class));
        $record = $this->record();
        $oldUrl = $record['bindings'][0]['canonical_url'];
        $newUrl = 'https://statamic.example/moved-publication';
        $record['bindings'][0]['canonical_url'] = $newUrl;
        $record['bindings'][0]['url_migration'] = [
            'old_url' => $oldUrl,
            'new_url' => $newUrl,
            'redirect_status' => 301,
            'verified_at' => '2026-09-16T12:00:00.000000Z',
        ];

        $publication = $validator->fromRecord($record);
        $this->assertSame(['old_url' => $oldUrl, 'new_url' => $newUrl], $publication['url_migration']);

        $record['bindings'][0]['url_migration']['old_url'] = 'https://other.example/unrelated';
        $this->expectException(RuntimeException::class);
        $validator->fromRecord($record);
    }

    public function test_it_builds_one_native_plan_from_an_exact_receiver_source_topic(): void
    {
        $validator = new NativePublication(app(Configuration::class), app(PlatformCatalog::class));
        $publication = $validator->fromSourceTopic([
            'topic_id' => 53,
            'topic_url' => 'https://forum.example/t/forum-scale-canary/53',
            'title' => 'Forum Scale Canary',
            'source_revision' => 'post:149:version:2',
            'publication_revision' => str_repeat('a', 64),
            'source_created_at' => '2026-09-19T15:00:00.000000Z',
            'source_updated_at' => '2026-09-20T16:00:00.000000Z',
            'content_html' => '<h2>One source</h2><p>Native Statamic content.</p>',
            'author' => [
                'name' => 'DiscussionBridge',
                'profile_url' => 'https://forum.example/u/discussionbridge',
            ],
            'destination' => [
                'state' => 'ready',
                'destination_container_id' => 'pages',
                'mapping_revision' => str_repeat('b', 64),
                'slug_policy' => 'topic_id',
                'destination_author_id' => 'user:statamic-service-user',
                'destination_terms' => [],
            ],
        ]);

        $this->assertSame('/forum-topic-53', $publication['path']);
        $this->assertSame('https://statamic.example/forum-topic-53/', $publication['canonical_url']);
        $this->assertSame('2026-09-19T15:00:00.000000Z', $publication['source_created_at']);
        $this->assertSame('2026-09-20T16:00:00.000000Z', $publication['source_updated_at']);
        $this->assertSame('pages', $publication['collection']);
        $this->assertSame('statamic-service-user', $publication['destination_author_id']);
    }

    private function record(): array
    {
        return [
            'resource_id' => '11111111-1111-4111-8111-111111111111',
            'direction' => 'from_discourse',
            'state' => 'healthy',
            'title' => 'The Bridge publishes everywhere',
            'topic_id' => 53,
            'content_html' => '<h2>One source</h2><p>Native Statamic content.</p>',
            'source' => [
                'platform' => 'discourse',
                'origin' => 'https://forum.example',
                'topic_id' => 53,
                'topic_url' => 'https://forum.example/t/the-bridge-publishes-everywhere/53',
                'post_id' => 149,
                'post_number' => 1,
                'post_version' => 1,
                'revision' => 'post:149:version:1',
                'author' => ['name' => 'DiscussionBridge', 'profile_url' => 'https://forum.example/u/discussionbridge'],
            ],
            'bindings' => [[
                'role' => 'presentation',
                'state' => 'active',
                'canonical_url' => 'https://statamic.example/the-bridge-publishes-everywhere',
                'native_materialization' => true,
            ]],
        ];
    }
}
