<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\RecordPresenter;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use Mockery;
use RuntimeException;

class RecordPresenterTest extends TestCase
{
    public function test_direct_source_display_uses_actual_update_and_generic_forum_name_with_optional_assets_only(): void
    {
        $resource = '11111111-1111-4111-8111-111111111111';
        $record = ['resource_id' => $resource, 'direction' => 'from_discourse', 'state' => 'healthy', 'topic_id' => 53, 'topic_url' => 'https://forum.example/t/source/53', 'content_html' => '<p>Source body</p>', 'source' => ['status' => 'active', 'forum_name' => '<Organization & members>', 'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-02-01T00:00:00Z']];
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('record')->twice()->with($resource)->andReturn(['bridge_record' => $record]);
        $this->app->instance(BridgeClient::class, $client);
        $presenter = app(RecordPresenter::class);
        $rendered = $presenter->render($resource);
        $this->assertStringContainsString('&lt;Organization &amp; members&gt;', $rendered);
        $this->assertStringContainsString('<time datetime="2026-02-01T00:00:00Z">', $rendered);
        $this->assertStringNotContainsString('rich-content.js', $rendered);
        config(['discussionbridge.rich_content' => true]);
        $this->assertStringContainsString('rich-content.css?v=', $presenter->render($resource));
    }

    public function test_current_source_permission_is_checked_before_cached_complete_body_is_used(): void
    {
        $body = '<p>'.str_repeat('x', 70000).'Complete ending</p>';
        $resource = '11111111-1111-4111-8111-111111111111';
        $record = ['resource_id' => $resource, 'direction' => 'from_discourse', 'state' => 'healthy', 'topic_id' => 53, 'topic_url' => 'https://forum.example/t/source/53', 'content_html' => null, 'source' => ['status' => 'active', 'edition' => str_repeat('a', 64), 'content' => ['bytes' => strlen($body), 'sha256' => hash('sha256', $body), 'path' => '/discussion-bridge/v1/bridge-records/'.$resource.'/content.json', 'chunk_bytes' => 32768]]];
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('record')->times(3)->with($resource)->andReturn(['bridge_record' => $record], ['bridge_record' => $record], ['bridge_record' => array_replace($record, ['source' => ['status' => 'withdrawn', 'reason' => 'source_unavailable', 'edition' => str_repeat('b', 64)]])]);
        $client->shouldReceive('publicationContent')->once()->with($record)->andReturn($body);
        $this->app->instance(BridgeClient::class, $client);
        $presenter = app(RecordPresenter::class);
        $this->assertStringContainsString('Complete ending', $presenter->render($resource));
        $this->assertStringContainsString('Complete ending', $presenter->render($resource));
        $this->expectException(RuntimeException::class);
        $presenter->render($resource);
    }

    public function test_public_source_footer_refuses_reverse_nanosecond_chronology(): void
    {
        $resource = '11111111-1111-4111-8111-111111111111';
        $record = ['resource_id' => $resource, 'direction' => 'from_discourse', 'state' => 'healthy', 'topic_id' => 53,
            'topic_url' => 'https://forum.example/t/source/53', 'content_html' => '<p>Source body</p>',
            'source' => ['created_at' => '2026-01-01T00:00:00.123456789Z', 'updated_at' => '2026-01-01T00:00:00.123456788Z']];
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('record')->once()->with($resource)->andReturn(['bridge_record' => $record]);
        $this->app->instance(BridgeClient::class, $client);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DiscussionBridge source chronology is invalid.');
        app(RecordPresenter::class)->render($resource);
    }
}
