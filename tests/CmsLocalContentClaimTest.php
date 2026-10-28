<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\DynamicWorker;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\PrivateState;
use Illuminate\Support\Facades\Http;

/** Focused scheduling boundary; actual native retention is tested per profile. */
class CmsLocalContentClaimTest extends NativeFlatTestCase
{
    public function test_one_exact_known_loss_skips_only_its_mapping_in_existing_durable_order(): void
    {
        $client = app(CmsClient::class);
        $codec = $client->codec();
        $store = app(PrivateState::class);
        $first = '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb250';
        $second = '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb251';
        $workId = '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb252';
        $mapping = fn ($id) => (object) ['mapping_id' => $id, 'resource_id' => '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb253',
            'connection_id' => config('discussionbridge.connection_id'), 'source_id' => 'discourse:topic:53',
            'external_id' => 'native:'.$id, 'mapping_revision' => 'mapping:1', 'destination_native_id' => null,
            'canonical_url' => 'https://statamic.example/discussionbridge/'.$id, 'retired_url_count' => 0,
            'mapping_state' => 'pending', 'presentation_mode' => 'full', 'desired_source_revision' => 'edition:1',
            'synchronized_source_revision' => null, 'publicly_verified_source_revision' => null,
            'destination_native_revision' => null, 'source_created_at' => null, 'source_updated_at' => null,
            'synchronized_at' => null, 'publicly_verified_at' => null];
        $mappings = [$first => $mapping($first), $second => $mapping($second)];
        $work = (object) ['work_id' => $workId, 'mapping_id' => $first, 'source_id' => 'discourse:topic:53',
            'source_revision' => 'edition:1', 'policy_revision' => 'policy:1', 'action' => 'publish_revision',
            'state' => 'leased', 'attempt_cycle' => '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb254', 'attempt' => 2,
            'lease_generation' => 8, 'retry_at' => null, 'last_failure_code' => null];
        $html = '<p>Complete source context.</p><video controls src="https://media.example/clip.mp4">Clip</video>';
        $source = (object) ['source_id' => $work->source_id, 'source_revision' => $work->source_revision,
            'topic_id' => '53', 'topic_url' => 'https://forum.example/t/source/53', 'title' => 'Known source',
            'content_sha256' => hash('sha256', $html), 'content_bytes' => strlen($html),
            'source_created_at' => null, 'source_updated_at' => null, 'source_authors' => []];
        $lease = (object) ['work' => clone $work, 'lease_token' => rtrim(strtr(base64_encode(str_repeat('l', 32)), '+/', '-_'), '='),
            'lease_generation' => 8, 'expires_at' => '2026-10-14T01:05:00.000Z', 'server_time' => '2026-10-14T01:00:00.000Z'];
        $codec->validate('Source', $source);
        $codec->validate('Lease', $lease);
        Http::preventStrayRequests();
        $paths = [];
        Http::fake(function ($request) use (&$work, &$mappings, &$paths, $workId) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $paths[] = $path;
            $this->assertSame('GET', $request->method(), 'Known local loss must not claim or renew again.');
            if ($path === '/discussion-bridge/v4/work/'.$workId.'.json') {
                $value = (object) ['work' => $work, 'mapping' => $mappings[$work->mapping_id], 'lease_expires_at' => null];
            } else {
                $value = $mappings[basename($path, '.json')];
            }
            return Http::response(json_encode($value, JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']);
        });
        $store->locked(function () use ($store, $codec, $first, $second, $workId, &$mappings, &$work, $source, $lease, $html, &$paths) {
            $state = $store->read();
            foreach ($mappings as $id => $value) {
                $state['mappings'][$id] = ['mapping' => $codec->encodeJson($value)];
            }
            $key = hash('sha256', $source->source_id."\0".$source->source_revision);
            $state['sources'][$key] = $codec->encodeJson($source);
            $state['work'][$workId] = $codec->encodeJson($lease);
            $state['local_content_issues'][$workId] = ['mapping_id' => $first, 'source_id' => $source->source_id,
                'source_revision' => $source->source_revision, 'policy_revision' => 'policy:1',
                'attempt_cycle' => $work->attempt_cycle, 'action' => $work->action,
                'reason' => 'native_media_unrepresented', 'lease_generation' => 8];
            $store->write('content-'.$key.'.html', $html);
            $store->save($state);
            $method = new \ReflectionMethod(DynamicWorker::class, 'claimMapping');
            $visited = [];
            $policy = (object) ['policy_revision' => 'policy:1'];
            $arguments = [&$state, $policy, &$visited];
            $this->assertSame($second, $method->invokeArgs(app(DynamicWorker::class), $arguments));
            $this->assertSame([$first => true, $second => true], $visited);
            $this->assertArrayHasKey($workId, $state['local_content_issues']);
            $this->assertSame(2, $work->attempt);
            $this->assertSame($lease->work->attempt_cycle, $work->attempt_cycle);
            $this->assertSame($html, file_get_contents($store->path('content-'.$key.'.html')));
            $this->assertSame([], $state['mutations']);
            // A real observed cycle change is reevaluated; old refusal is not a
            // permanent mapping blacklist or permission to reset receiver work.
            $work->attempt_cycle = '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb255';
            $visited = [];
            $this->assertSame($first, $method->invokeArgs(app(DynamicWorker::class), $arguments));
            $this->assertSame([], $state['local_content_issues']);
            $this->assertCount(1, $state['local_content_issue_history']);
            $this->assertSame(2, $work->attempt);
            $this->assertSame([], $state['mutations']);
        });
    }
}