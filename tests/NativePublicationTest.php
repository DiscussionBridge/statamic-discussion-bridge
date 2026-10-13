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

    public function test_topic_url_must_identify_the_actual_first_post_source(): void
    {
        $validator = new NativePublication(app(Configuration::class));
        foreach (['/t/53', '/t/53/1', '/t/source-title/53', '/t/source-title/53/1'] as $path) {
            $record = $this->record();
            $record['source']['topic_url'] = 'https://forum.example'.$path;
            $this->assertSame($record['source']['topic_url'], $validator->fromRecord($record)['topic_url']);
        }
        foreach (['https://forum.example/t/title/999', 'https://forum.example/t/title/53/2', 'https://forum.example/t/53/999', 'https://forum.example/t/title/53?x=1', 'https://forum.example/t/title/53#reply', 'https://user@forum.example/t/title/53', 'https://forum.example/t/title/53/../999', 'https://forum.example/t/title%2fother/53', 'https://forum.example//t/title/53'] as $url) {
            $record = $this->record();
            $record['source']['topic_url'] = $url;
            try {
                $validator->fromRecord($record);
                $this->fail('Expected source URL rejection: '.$url);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['?x=1', '#fragment'] as $suffix) {
            $record = $this->record();
            $record['bindings'][0]['canonical_url'] .= $suffix;
            try {
                $validator->fromRecord($record);
                $this->fail('Expected destination URL rejection.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_source_dates_reject_normalized_calendar_clock_and_offset_values(): void
    {
        $validator = new NativePublication(app(Configuration::class));
        foreach (['0000-01-01T00:00:00Z', '2026-02-29T00:00:00Z', '2026-04-31T00:00:00Z', '2026-09-01T24:00:00Z', '2026-09-01T00:00:60Z', '2026-09-01T00:00:00+24:00', '2026-09-01T00:00:00+01:60'] as $value) {
            try {
                $validator->isoDate($value);
                $this->fail('Expected invalid source date rejection: '.$value);
            } catch (RuntimeException $error) {
                $this->assertSame('DiscussionBridge source date is invalid.', $error->getMessage());
            }
        }
        $this->assertSame('2024-02-29T23:59:59.123456789-08:00', $validator->isoDate('2024-02-29T23:59:59.123456789-08:00'));
    }

    public function test_source_chronology_preserves_nanoseconds_and_compares_offset_instants(): void
    {
        $validator = new NativePublication(app(Configuration::class));
        $record = $this->record();
        $record['source']['created_at'] = '2026-08-01T10:00:00.123456789+02:00';
        $record['source']['updated_at'] = '2026-08-01T08:00:00.123456790Z';
        $this->assertSame($record['source']['updated_at'], $validator->fromRecord($record)['source_updated_at']);
        $record['source']['updated_at'] = '2026-08-01T08:00:00.123456788Z';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DiscussionBridge source chronology is invalid.');
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
