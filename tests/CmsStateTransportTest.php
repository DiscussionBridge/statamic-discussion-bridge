<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\PrivateState;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ReplayJournal;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\SourceContinuation;
use DiscussionBridge\CmsV4\NoContent;
use DiscussionBridge\CmsV4\ValidationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class CmsStateTransportTest extends TestCase
{
    private string $privateDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateDirectory = sys_get_temp_dir().'/discussionbridge-cms-'.bin2hex(random_bytes(8));
        Http::preventStrayRequests();
    }

    public function test_uncertain_claim_replays_exact_mutation_after_new_journal_instance_and_204_is_completed(): void
    {
        $bodies = [];
        Http::fake(function ($request) use (&$bodies) {
            $bodies[] = $request->body();
            if (count($bodies) === 1) {
                throw new ConnectionException('Synthetic timeout, no receiver outcome known.');
            }
            return Http::response('', 204);
        });
        $client = app(CmsClient::class);
        $store = new PrivateState($this->privateDirectory);
        $store->locked(function ($store) use ($client) {
            $state = $store->read();
            try {
                (new ReplayJournal($client, $store))->mutate($state, 'claim-1', 'V4-13', [], (object) []);
                $this->fail('The uncertain request must surface.');
            } catch (CmsException $error) {
                $this->assertSame('transport_timeout', $error->failureCode);
            }
        });
        (new PrivateState($this->privateDirectory))->locked(function ($store) use ($client) {
            $state = $store->read();
            $journal = new ReplayJournal($client, $store);
            $this->assertSame(NoContent::VALUE, $journal->mutate($state, 'claim-1', 'V4-13', [], (object) []));
            $this->assertSame(NoContent::VALUE, $journal->mutate($state, 'claim-1', 'V4-13', [], (object) []));
        });
        $this->assertCount(2, $bodies);
        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertMatchesRegularExpression('/"mutation_id":"[0-9a-f-]{36}"/', $bodies[0]);
        Http::assertSent(fn ($request) => $request->hasHeader('X-DiscussionBridge-Connection', 'dbc_0123456789abcdef01234567')
            && $request->hasHeader('Accept-Encoding', 'identity'));
    }

    public function test_source_pages_stage_before_cursor_and_complete_body_exceeds_metadata_cap_without_id_coercion(): void
    {
        $body = str_repeat('<p>Native complete body.</p>', 14000).'😀';
        $source = (object) ['source_id' => 'discourse:9223372036854775807', 'source_revision' => 'revision:wiki:2',
            'topic_id' => '9223372036854775807', 'topic_url' => 'https://forum.example/t/native/9223372036854775807',
            'title' => 'Current wiki source', 'content_sha256' => hash('sha256', $body), 'content_bytes' => strlen($body),
            'source_created_at' => '2026-10-14T00:00:00.000Z', 'source_updated_at' => '2026-10-14T01:00:00.000Z',
            'source_authors' => [(object) ['id' => 'discourse:author:1', 'name' => 'Source Author']]];
        $page = 0;
        Http::fake(function ($request) use ($source, $body, &$page) {
            if (str_contains($request->url(), '/sources.json')) {
                $page++;
                return Http::response(['stream' => $page === 1 ? 'initial' : 'changes',
                    'items' => $page === 1 ? [['event_type' => 'publication', 'event_id' => '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb24c',
                        'event_sequence' => '9007199254740993', 'source' => $source]] : [],
                    'next_cursor' => null, 'checkpoint_cursor' => 'checkpoint_'.$page], 200, ['Content-Type' => 'application/json']);
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $offset = (int) $query['offset'];
            $bytes = substr($body, $offset, 32768);
            return Http::response(['source_id' => $source->source_id, 'source_revision' => $source->source_revision,
                'content_sha256' => $source->content_sha256, 'content_bytes' => strlen($body), 'offset' => $offset,
                'next_offset' => $offset + strlen($bytes) === strlen($body) ? null : $offset + strlen($bytes),
                'content_base64' => base64_encode($bytes)], 200, ['Content-Type' => 'application/json']);
        });
        $client = app(CmsClient::class);
        (new PrivateState($this->privateDirectory))->locked(function ($store) use ($client, $source, $body) {
            $state = $store->read();
            $feed = new SourceContinuation($client, $store);
            $this->assertSame(1, $feed->stage($state));
            $durable = $store->read();
            $this->assertSame(['stream' => 'changes', 'cursor' => 'checkpoint_1'], $durable['feed']);
            $this->assertCount(1, $durable['events']);
            $this->assertStringContainsString('"topic_id":"9223372036854775807"', reset($durable['sources']));
            $this->assertSame($body, file_get_contents($feed->content($source)));
            $this->assertSame(0, $feed->stage($state));
            $this->assertSame('checkpoint_2', $store->read()['feed']['cursor']);
        });
        Http::assertSent(fn ($request) => str_contains($request->url(), '/revisions/revision%3Awiki%3A2/content.json'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'stream=changes&cursor=checkpoint_1'));
    }

    public function test_native_writer_lock_is_exclusive_and_state_is_not_public(): void
    {
        $first = new PrivateState($this->privateDirectory);
        $second = new PrivateState($this->privateDirectory);
        $first->locked(function () use ($second) {
            try {
                $second->locked(fn () => $this->fail('A second writer acquired the native state.'));
                $this->fail('Second writer must stop.');
            } catch (CmsException $error) {
                $this->assertSame('work_busy', $error->failureCode);
            }
        });
        $this->expectException(CmsException::class);
        new PrivateState(public_path());
    }

    public function test_definitive_preservation_error_is_journaled_separately_and_refused_after_restart(): void
    {
        $error = (object) ['outcome' => 'rejected', 'code' => 'content_preservation_unavailable',
            'retryable' => false, 'retry_after_seconds' => null, 'correlation_id' => null, 'core_fallback' => false];
        Http::fake(['*' => Http::response((array) $error, 422, ['Content-Type' => 'application/json'])]);
        $client = app(CmsClient::class);
        $html = '<p>Complete prepared source.</p>';
        $payload = (object) ['bridge_record' => (object) ['direction' => 'to_discourse', 'external_id' => 'native:entry:rejected',
            'canonical_url' => 'https://statamic.example/rejected', 'title' => 'Rejected native article', 'content_html' => $html,
            'published' => true, 'source_revision' => 'native:rejected:1', 'expected_source_revision' => null,
            'source_created_at' => null, 'source_updated_at' => null, 'representation' => (object) ['kind' => 'full'],
            'source_content_sha256' => hash('sha256', $html), 'source_content_bytes' => strlen($html)]];
        $first = null;
        foreach ([0, 1] as $pass) {
            (new PrivateState($this->privateDirectory))->locked(function ($store) use ($client, $payload, &$first) {
                $state = $store->read();
                try {
                    (new ReplayJournal($client, $store))->mutate($state, 'rejected-to', 'V4-02', [], $payload);
                    $this->fail('A definitive rejection became success or was silently cleared.');
                } catch (\CodeWorksLabs\DiscussionBridgeStatamic\Cms\DefinitiveRejection $rejected) {
                    $this->assertSame('V4-02', $rejected->routeId);
                    $this->assertSame(422, $rejected->httpStatus);
                    $this->assertSame('content_preservation_unavailable', $rejected->failureCode);
                }
                $saved = $store->read()['mutations']['rejected-to'];
                $this->assertNull($saved['result']);
                $this->assertCount(6, json_decode($saved['rejection']['envelope'], true, 512, JSON_THROW_ON_ERROR));
                if ($first === null) {
                    $first = $saved;
                } else {
                    $this->assertSame($first, $saved);
                }
            });
        }
        Http::assertSentCount(1);
        $this->assertSame('native:rejected:1', json_decode($first['body'])->bridge_record->source_revision);
    }

    public function test_preservation_error_is_to_only_and_malformed_error_does_not_become_definitive_evidence(): void
    {
        $codec = app(CmsClient::class)->codec();
        $error = ['outcome' => 'rejected', 'code' => 'content_preservation_unavailable', 'retryable' => false,
            'retry_after_seconds' => null, 'correlation_id' => null, 'core_fallback' => false];
        foreach (['V4-02', 'V4-29'] as $route) {
            $this->assertSame($error['code'], $codec->decodeResponse($route, json_encode($error), 422)->code);
        }
        foreach (['V4-01', 'V4-05', 'V4-06', 'V4-16', 'V4-17', 'V4-19'] as $route) {
            try {
                $codec->decodeResponse($route, json_encode($error), 422);
                $this->fail('The To-only error expanded to '.$route);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $error['retryable'] = true;
        $malformedRejected = false;
        try {
            \CodeWorksLabs\DiscussionBridgeStatamic\Cms\DefinitiveRejection::fromResponse($codec, 'V4-02', (object) $error, 422);
        } catch (ValidationException|\LogicException) {
            $malformedRejected = true;
        }
        $this->assertTrue($malformedRejected, 'Malformed retry metadata became definitive rejection evidence.');
        $failure = (object) ['mutation_id' => 'b258e768-59e7-4659-9dfa-b9092d54c726',
            'authority' => (object) ['work_id' => '63bad04c-1c2e-4c38-80ce-b379137dbb2c',
                'lease_token' => rtrim(strtr(base64_encode(str_repeat('l', 32)), '+/', '-_'), '='),
                'lease_generation' => 1, 'source_revision' => 'source:1', 'policy_revision' => 'policy:1'],
            'code' => 'public_verification_failed', 'native_change_applied' => false, 'safe_detail' => null,
            'retry_after_seconds' => null];
        $this->assertSame($failure, $codec->validate('Failure', $failure));
        $failure->code = 'content_preservation_unavailable';
        try {
            $codec->validate('Failure', $failure);
            $this->fail('From Failure accepted a To-only error.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_expired_feed_cursor_reconciles_durable_inventory_before_explicit_fresh_initial_without_withdrawal(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['outcome' => 'rejected', 'code' => 'cursor_expired', 'retryable' => false,
                'retry_after_seconds' => null, 'correlation_id' => null, 'core_fallback' => false], 410, ['Content-Type' => 'application/json'])
            ->push(['items' => [], 'next_cursor' => null], 200, ['Content-Type' => 'application/json'])
            ->push(['stream' => 'initial', 'items' => [], 'next_cursor' => null, 'checkpoint_cursor' => 'fresh_checkpoint'], 200, ['Content-Type' => 'application/json'])]);
        $client = app(CmsClient::class);
        (new PrivateState($this->privateDirectory))->locked(function ($store) use ($client) {
            $state = $store->read();
            $state['feed'] = ['stream' => 'changes', 'cursor' => 'expired_checkpoint'];
            $store->save($state);
            $continuation = new SourceContinuation($client, $store);
            try {
                $continuation->stage($state);
                $this->fail('Cursor expiry must be surfaced and recorded.');
            } catch (CmsException $error) {
                $this->assertSame('cursor_expired', $error->failureCode);
            }
            $durable = $store->read();
            $this->assertSame('expired_checkpoint', $durable['feed']['cursor']);
            $this->assertSame('cursor_expired', $durable['feed_reconciliation']['reason']);
            $this->assertSame(0, $continuation->stage($durable));
            $this->assertSame('cursor_expired', $store->read()['last_feed_reconciliation']['reason']);
            $this->assertSame(['stream' => 'changes', 'cursor' => 'fresh_checkpoint'], $store->read()['feed']);
            $this->assertSame([], $store->read()['mappings']);
        });
        $requests = Http::recorded();
        $this->assertStringContainsString('/mappings.json', $requests[1][0]->url());
        $this->assertStringEndsWith('stream=initial', $requests[2][0]->url());
    }

    public function test_redirect_and_duplicate_json_and_compression_are_rejected_without_fallback(): void
    {
        $client = app(CmsClient::class);
        $cases = [
            [Http::response('', 302, ['Location' => 'https://elsewhere.example']), CmsException::class],
            [Http::response('{"stream":"initial","stream":"changes"}', 200, ['Content-Type' => 'application/json']), ValidationException::class],
            [Http::response('{}', 200, ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip']), CmsException::class],
        ];
        $sequence = Http::sequence();
        foreach ($cases as [$response]) {
            $sequence->pushResponse($response);
        }
        Http::fake(['*' => $sequence]);
        foreach ($cases as [$response, $exception]) {
            try {
                $client->request('V4-06', [], (object) ['stream' => 'initial']);
                $this->fail('Unsafe response accepted.');
            } catch (\Throwable $error) {
                $this->assertInstanceOf($exception, $error);
            }
        }
    }

    public function test_typed_to_transport_preserves_opaque_revision_string_core_ids_and_reconciliation(): void
    {
        $client = app(CmsClient::class);
        $revision = 'native:'.str_repeat('r', 121);
        $body = '<p>Complete native source.</p>';
        $record = (object) ['direction' => 'to_discourse', 'external_id' => 'native:entry:one',
            'canonical_url' => 'https://statamic.example/source', 'title' => 'Native source', 'content_html' => $body,
            'published' => true, 'source_revision' => $revision, 'expected_source_revision' => null,
            'source_created_at' => null, 'source_updated_at' => null, 'representation' => (object) ['kind' => 'full'],
            'source_content_sha256' => hash('sha256', $body), 'source_content_bytes' => strlen($body)];
        $resolved = ['outcome' => 'created', 'reason' => 'record_created',
            'resource_id' => '63bad04c-1c2e-4c38-80ce-b379137dbb2c', 'topic_id' => '9223372036854775807',
            'topic_url' => 'https://forum.example/t/native/9223372036854775807', 'direction' => 'to_discourse',
            'source_revision' => $revision, 'synchronized_at' => '2026-10-14T00:00:00.000Z', 'core_fallback' => false];
        $detail = ['resource_id' => $resolved['resource_id'], 'direction' => 'to_discourse', 'state' => 'active',
            'topic_id' => $resolved['topic_id'], 'topic_url' => $resolved['topic_url'], 'external_id' => $record->external_id,
            'canonical_url' => $record->canonical_url, 'source_revision' => $revision,
            'source_created_at' => null, 'source_updated_at' => null, 'synchronized_at' => $resolved['synchronized_at'], 'mapping_id' => null];
        $reconciliation = ['outcome' => 'reconciliation_required', 'reason' => 'native_ownership_conflict',
            'resource_id' => null, 'topic_id' => null, 'topic_url' => null, 'direction' => 'to_discourse',
            'source_revision' => null, 'synchronized_at' => null, 'core_fallback' => false];
        Http::fake(['*' => Http::sequence()->push($resolved, 201, ['Content-Type' => 'application/json'])
            ->push(['items' => [$detail], 'next_cursor' => null], 200, ['Content-Type' => 'application/json'])
            ->push($detail, 200, ['Content-Type' => 'application/json'])
            ->push($reconciliation, 409, ['Content-Type' => 'application/json'])]);
        $payload = (object) ['mutation_id' => 'b258e768-59e7-4659-9dfa-b9092d54c726', 'bridge_record' => $record];
        $result = $client->request('V4-02', [], null, $payload);
        $this->assertSame($revision, $result->source_revision);
        $this->assertSame('9223372036854775807', $result->topic_id);
        $page = $client->request('V4-03');
        $this->assertSame($detail['topic_id'], $page->items[0]->topic_id);
        $this->assertSame($revision, $client->request('V4-04', ['resource_id' => $resolved['resource_id']])->source_revision);
        $this->assertSame('native_ownership_conflict', $client->request('V4-02', [], null, $payload)->reason);
        $requests = Http::recorded();
        $this->assertStringContainsString('"source_revision":"'.$revision.'"', $requests[0][0]->body());
        $this->assertStringEndsWith('/bridge-records.json', $requests[1][0]->url());
        $this->assertStringEndsWith('/bridge-records/'.$resolved['resource_id'].'.json', $requests[2][0]->url());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->privateDirectory.'/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($this->privateDirectory)) {
            rmdir($this->privateDirectory);
        }
        parent::tearDown();
    }
}
