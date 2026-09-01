<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\NativePublication;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use RuntimeException;

class NativePublicationTest extends TestCase
{
    public function test_it_requires_explicit_native_authority_and_exact_source_identity(): void
    {
        $validator = new NativePublication(app(Configuration::class));
        $record = $this->record();
        $publication = $validator->fromRecord($record);

        $this->assertSame('the-bridge-publishes-everywhere', $publication['slug']);
        $this->assertSame('post:149:version:1', $publication['source_revision']);
        $this->assertSame('https://statamic.example/discussionbridge/the-bridge-publishes-everywhere', $publication['canonical_url']);

        $record['bindings'][0]['native_materialization'] = false;
        $this->assertNull($validator->fromRecord($record));

        $record = $this->record();
        $record['source']['origin'] = 'https://other.example';
        $this->expectException(RuntimeException::class);
        $validator->fromRecord($record);
    }

    public function test_it_rejects_destination_escape_and_ambiguous_authority(): void
    {
        $validator = new NativePublication(app(Configuration::class));
        $record = $this->record();
        $record['bindings'][0]['canonical_url'] = 'https://statamic.example/outside/the-bridge-publishes-everywhere';
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
                'canonical_url' => 'https://statamic.example/discussionbridge/the-bridge-publishes-everywhere',
                'native_materialization' => true,
            ]],
        ];
    }
}
