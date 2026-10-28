<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use Closure;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\{CmsClient,CmsException,DefinitiveRejection,DynamicWorker,NativePolicy,NativeWriter,OwnedFragments,PrivateState,PublicVerifier,ReplayJournal,StaticCoordinator,ToDelivery,ToPublication,ToSourcePreparation};
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\{ConnectionException,Factory};
use Illuminate\Support\Facades\{DB,Facade,Http};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Real codec/client/journal/continuation; in-memory native capture, queue, storage and HTTP seams. */
class AdministratorRetryTest extends TestCase
{
    private RetryMemoryState $store;
    private RetryMemoryQueue $queue;
    private RetryCapture $capture;
    private CmsClient $client;
    private Factory $http;
    private Configuration $configuration;
    private stdClass $connection;
    private stdClass $record;
    private string $responseCode = 'invalid_field';
    private bool $transportFailure = false;
    private array $postBodies = [];
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = [];
        $bytes = file_get_contents(__DIR__.'/fixtures/retry-author-fixtures.json');
        foreach (json_decode(ltrim($bytes, "\xef\xbb\xbf"), false, 512, JSON_THROW_ON_ERROR)->fixtures as $fixture) $this->fixture[$fixture->id] = $fixture->value;
        $container = new Container; Container::setInstance($container); Facade::setFacadeApplication($container);
        $container->instance('config', new Repository(['discussionbridge' => ['enabled' => true, 'site_origin' => 'https://publisher.example',
            'forum_url' => 'https://forum.example', 'connection_id' => 'dbc_111111111111111111111111', 'cms_profile' => 'statamic_flat']]));
        $container->instance(\Statamic\Contracts\Entries\EntryRepository::class, new stdClass);
        $this->configuration = new class extends Configuration { public function secret(): string { return str_repeat('a', 43); } };
        $this->client = new CmsClient($this->configuration);
        $this->http = new Factory; $this->http->preventStrayRequests(); Http::swap($this->http);
        $this->store = new RetryMemoryState;
        $this->record = clone $this->fixture['to_resolve']->bridge_record;
        config(['discussionbridge.adapter_id' => 'statamic', 'discussionbridge.adapter_version' => '0.4.0-alpha.1']);
        $this->connection = clone $this->fixture['connection'];
        $this->connection->profile = 'statamic_flat'; $this->connection->destination_origin = $this->configuration->siteOrigin();
        $this->capture = new RetryCapture(['record' => ['canonical_url' => $this->record->canonical_url, 'title' => $this->record->title,
            'source_authors' => [['id' => 'native-user', 'name' => 'Native Author']], 'primary_source_author_id' => 'native-user',
            'source_publication' => ['revision' => 'native-r1', 'created_at' => $this->record->source_created_at, 'updated_at' => $this->record->source_updated_at,
                'content_sha256' => $this->record->source_content_sha256, 'content_bytes' => $this->record->source_content_bytes]],
            'full_html' => $this->record->content_html, 'native_source' => ['article' => 'original source', 'article_url' => $this->record->canonical_url]]);
        $this->queue = new RetryMemoryQueue((object) ['id' => 1, 'entry_id' => 'native-entry', 'site_handle' => 'default', 'collection_handle' => 'articles',
            'external_id' => $this->record->external_id, 'canonical_url' => $this->record->canonical_url, 'status' => 'reconciliation_required',
            'last_error' => 'invalid_field', 'attempts' => 3, 'desired_revision' => 'native-r1', 'cms_native_revision' => null,
            'cms_source_revision' => null, 'resource_id' => null, 'topic_id' => null, 'correlation_id' => 'native-delivery-correlation']);
        DB::swap($this->queue);
        $this->http->fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/connection.json')) return $this->http->response(json_encode($this->connection), 200, ['Content-Type' => 'application/json']);
            if ($request->method() === 'GET') return $this->http->response(['items' => [], 'next_cursor' => null], 200, ['Content-Type' => 'application/json']);
            $this->postBodies[] = $request->body();
            if ($this->transportFailure) throw new ConnectionException('Synthetic lost To response');
            return $this->http->response(['outcome' => 'rejected', 'code' => $this->responseCode, 'retryable' => false,
                'retry_after_seconds' => null, 'correlation_id' => 'actual-server-correlation', 'core_fallback' => false], 422, ['Content-Type' => 'application/json']);
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Container::setInstance(null);
        parent::tearDown();
    }

    private function delivery(): ToDelivery
    {
        $native = new class extends NativePolicy { public function __construct() {} public function profile(): string { return config('discussionbridge.cms_profile'); } };
        return new ToDelivery($this->client, $this->store, $this->configuration, $native, $this->capture,
            new ToPublication);
    }

    private function refuse(bool $dispatch = true): array
    {
        $state = $this->store->read();
        $state['to_binding'] = ['connection_id' => $this->connection->connection_id, 'profile' => $this->connection->profile,
            'destination_origin' => $this->connection->destination_origin, 'forum_origin' => $this->configuration->forumOrigin(),
            'publication_lane' => $this->connection->publication_lane, 'scope_revision' => $this->connection->scope_revision];
        $journal = new ReplayJournal($this->client, $this->store);
        $journal->prepare($state, 'old-intent', 'V4-02', [], (object) ['bridge_record' => $this->record]);
        $state['to'][$this->record->external_id] = ['pending' => ['key' => 'old-intent', 'record' => $this->client->codec()->encodeJson($this->record),
            'native_revision' => 'native-r1', 'native_source' => $this->capture->value['native_source'], 'prepared_source' => $this->capture->value,
            'forum_name' => 'Example Forum', 'prior_resource_id' => null, 'prior_topic_id' => null]];
        $this->store->save($state);
        if ($dispatch) {
            try { $journal->mutate($state, 'old-intent', 'V4-02', [], (object) ['bridge_record' => $this->record]); }
            catch (CmsException) {}
        }
        return $this->store->read();
    }

    public function test_future_typed_invalid_field_retains_actual_error_with_exact_request_before_queue_status(): void
    {
        $state = $this->refuse();
        $this->assertArrayHasKey('rejection', $state['mutations']['old-intent']);
        $error = json_decode($state['mutations']['old-intent']['rejection']['envelope']);
        $this->assertSame('invalid_field', $error->code);
        $this->assertSame('actual-server-correlation', $error->correlation_id);
        $this->assertNull($state['mutations']['old-intent']['result']);
    }

    public static function profiles(): array { return [['statamic_flat'], ['statamic_db'], ['statamic_ssg']]; }

    #[DataProvider('profiles')]
    public function test_explicit_unchanged_retry_links_one_fresh_intent_and_duplicate_action_does_not_reset_budget(string $profile): void
    {
        config(['discussionbridge.cms_profile' => $profile]); $this->connection->profile = $profile;
        $before = $this->refuse(); $identity = hash('sha256', $before['mutations']['old-intent']['identity']);
        $result = $this->delivery()->retryInvalidField(1, $identity, 'real-native-user');
        $after = $this->store->read(); $slot = $after['to'][$this->record->external_id];
        $this->assertSame($before['mutations']['old-intent'], $after['mutations']['old-intent']);
        $this->assertNotSame('old-intent', $slot['pending']['key']);
        $this->assertSame($before['to'][$this->record->external_id]['pending']['record'], $slot['pending']['record']);
        $this->assertNotSame($before['mutations']['old-intent']['mutation_id'], $after['mutations'][$slot['pending']['key']]['mutation_id']);
        $this->assertSame('real-native-user', $slot['retry_actions'][$identity]['actor_id']);
        $this->assertSame('pending', $this->queue->row->status); $this->assertSame(0, $this->queue->row->attempts);
        $this->queue->row->attempts = 2; $this->queue->row->status = 'failed';
        $repeat = $this->delivery()->retryInvalidField(1, $identity, 'real-native-user');
        $this->assertSame($result['intent_key'], $repeat['intent_key']);
        $this->assertSame('queued', $result['state']); $this->assertSame('already_recorded', $repeat['state']);
        $this->assertSame(2, $this->queue->row->attempts); $this->assertSame('failed', $this->queue->row->status);
        $this->assertCount(2, $this->store->read()['mutations']);
    }

    public function test_uncertain_or_generic_sql_status_does_not_become_definitive_refusal(): void
    {
        $before = $this->refuse(false); $id = hash('sha256', $before['mutations']['old-intent']['identity']);
        $this->expectException(CmsException::class);
        try { $this->delivery()->retryInvalidField(1, $id, 'native-user'); }
        finally { $this->assertSame($before, $this->store->read()); $this->assertSame(3, $this->queue->row->attempts); }
    }

    public static function unknownEnvelopes(): array { return [[false], [true]]; }

    #[DataProvider('unknownEnvelopes')]
    public function test_structured_retained_witness_can_keep_missing_actual_error_fields_unknown(bool $explicitNull): void
    {
        $state = $this->refuse(false); $mutation = $state['mutations']['old-intent'];
        $state['mutations']['old-intent']['rejection'] = ['route' => 'V4-02', 'http_status' => 422, 'code' => 'invalid_field',
            'mutation_id' => $mutation['mutation_id'], 'request_identity' => $mutation['identity']];
        if ($explicitNull) $state['mutations']['old-intent']['rejection']['envelope'] = null;
        $this->store->save($state);
        $id = hash('sha256', $mutation['identity']);
        $this->delivery()->retryInvalidField(1, $id, 'native-user');
        $history = $this->store->read()['to'][$this->record->external_id]['rejected_history']['old-intent'];
        $this->assertSame($state['mutations']['old-intent']['rejection'], $history['rejection']);
        $this->assertContains('decoded_error', $history['unknown_fields']);
        if ($explicitNull) $this->assertNull($history['rejection']['envelope']);
        else $this->assertArrayNotHasKey('envelope', $history['rejection']);
    }

    public function test_link_commit_failure_cannot_rearm_queue_or_leave_an_orphan_active_intent(): void
    {
        $before = $this->refuse(); $id = hash('sha256', $before['mutations']['old-intent']['identity']);
        $this->store->beforeSave = function ($state) { if (isset($state['to'][$this->record->external_id]['retry_actions'])) throw new \RuntimeException('controlled link commit failure'); };
        try { $this->delivery()->retryInvalidField(1, $id, 'native-user'); $this->fail('Missing simulated crash'); }
        catch (\RuntimeException $error) { $this->assertSame('controlled link commit failure', $error->getMessage()); }
        $this->assertSame($before, $this->store->read()); $this->assertSame('reconciliation_required', $this->queue->row->status);
    }

    public function test_restart_after_link_before_queue_rearm_reuses_one_committed_uuid(): void
    {
        $before = $this->refuse(); $id = hash('sha256', $before['mutations']['old-intent']['identity']);
        $this->queue->beforeUpdate = fn () => throw new \RuntimeException('controlled queue failure');
        try { $this->delivery()->retryInvalidField(1, $id, 'native-user'); $this->fail('Missing simulated crash'); }
        catch (\RuntimeException $error) { $this->assertSame('controlled queue failure', $error->getMessage()); }
        $intent = $this->store->read()['to'][$this->record->external_id]['pending']['key'];
        $this->assertCount(2, $this->store->read()['mutations']); $this->assertSame(3, $this->queue->row->attempts);
        $this->queue->beforeUpdate = null;
        $result = $this->delivery()->retryInvalidField(1, $id, 'native-user');
        $this->assertSame($intent, $result['intent_key']); $this->assertSame(0, $this->queue->row->attempts);
        $this->assertCount(2, $this->store->read()['mutations']);
    }

    public function test_restart_after_queue_before_completion_flag_does_not_reset_consumed_budget(): void
    {
        $before = $this->refuse(); $id = hash('sha256', $before['mutations']['old-intent']['identity']);
        $this->store->beforeSave = function ($state) use ($id) { if ($state['to'][$this->record->external_id]['retry_actions'][$id]['queue_armed'] ?? false) throw new \RuntimeException('controlled completion flag failure'); };
        try { $this->delivery()->retryInvalidField(1, $id, 'native-user'); $this->fail('Missing simulated crash'); }
        catch (\RuntimeException $error) { $this->assertSame('controlled completion flag failure', $error->getMessage()); }
        $this->assertSame('pending', $this->queue->row->status);
        $this->queue->row->attempts = 2; $this->store->beforeSave = null;
        $this->delivery()->retryInvalidField(1, $id, 'native-user');
        $this->assertSame(2, $this->queue->row->attempts); $this->assertCount(2, $this->store->read()['mutations']);
    }

    public function test_uncertain_new_retry_replays_its_exact_uuid_and_body_without_resetting_old_evidence(): void
    {
        $before = $this->refuse(); $id = hash('sha256', $before['mutations']['old-intent']['identity']);
        $this->delivery()->retryInvalidField(1, $id, 'native-user'); $this->transportFailure = true;
        foreach ([1, 2] as $attempt) {
            try { $this->delivery()->deliver(clone $this->queue->row); $this->fail('Missing response loss'); }
            catch (\CodeWorksLabs\DiscussionBridgeStatamic\Cms\UncertainMutation) {}
        }
        $this->assertSame($this->postBodies[1], $this->postBodies[2]);
        $this->assertNotSame($this->postBodies[0], $this->postBodies[1]);
        $this->queue->row->attempts = 2; $this->queue->row->status = 'failed';
        $this->delivery()->retryInvalidField(1, $id, 'native-user');
        $this->assertSame(2, $this->queue->row->attempts); $this->assertCount(2, $this->store->read()['mutations']);
        $this->assertSame($before['mutations']['old-intent'], $this->store->read()['mutations']['old-intent']);
    }

    public function test_genuine_new_source_before_retry_dispatch_keeps_ordinary_eligibility_and_unsent_history(): void
    {
        $before = $this->refuse(); $id = hash('sha256', $before['mutations']['old-intent']['identity']);
        $this->delivery()->retryInvalidField(1, $id, 'native-user');
        $retryKey = $this->store->read()['to'][$this->record->external_id]['pending']['key'];
        $this->capture->value['record']['source_publication']['revision'] = 'native-r2';
        $this->capture->value['record']['title'] = 'Actual new native title';
        $this->capture->value['native_source']['article'] = 'Actual new source edition';
        $this->queue->row->desired_revision = 'native-r2'; $this->transportFailure = true;
        try { $this->delivery()->deliver(clone $this->queue->row); $this->fail('Missing ordinary response loss'); }
        catch (\CodeWorksLabs\DiscussionBridgeStatamic\Cms\UncertainMutation) {}
        $state = $this->store->read(); $slot = $state['to'][$this->record->external_id];
        $this->assertNotSame($retryKey, $slot['pending']['key']);
        $this->assertArrayHasKey($retryKey, $slot['undispatched_history']);
        $this->assertSame('native-r2', json_decode($slot['pending']['record'])->source_revision);
    }

    public function test_stale_old_action_after_a_repeated_refusal_reports_reconciliation_without_a_third_intent(): void
    {
        $before = $this->refuse(); $id = hash('sha256', $before['mutations']['old-intent']['identity']);
        $this->delivery()->retryInvalidField(1, $id, 'native-user');
        try { $this->delivery()->deliver(clone $this->queue->row); $this->fail('Missing repeated refusal'); }
        catch (DefinitiveRejection $error) { $this->assertSame('invalid_field', $error->failureCode); }
        $this->queue->row->status = 'reconciliation_required'; $this->queue->row->last_error = 'invalid_field'; $this->queue->row->attempts = 1;
        $result = $this->delivery()->retryInvalidField(1, $id, 'native-user');
        $this->assertSame('reconciliation_required', $result['state']);
        $this->assertCount(2, $this->store->read()['mutations']); $this->assertSame(1, $this->queue->row->attempts);
    }

    public function test_changed_current_connection_scope_cannot_create_link_or_reset_queue(): void
    {
        $before = $this->refuse(); $id = hash('sha256', $before['mutations']['old-intent']['identity']);
        $before['to_binding'] = ['connection_id' => $this->connection->connection_id, 'profile' => $this->connection->profile,
            'destination_origin' => $this->connection->destination_origin, 'forum_origin' => $this->configuration->forumOrigin(),
            'publication_lane' => $this->connection->publication_lane, 'scope_revision' => 'actually-different-retained-scope'];
        $this->store->save($before);
        $this->expectException(CmsException::class);
        try { $this->delivery()->retryInvalidField(1, $id, 'native-user'); }
        finally { $this->assertSame($before, $this->store->read()); $this->assertSame(3, $this->queue->row->attempts); }
    }

    public function test_current_accepted_predecessor_disagreement_preserves_refusal_and_stops_cas(): void
    {
        $known = clone $this->fixture['record'];
        $this->record->expected_source_revision = $known->source_revision;
        $before = $this->refuse(); $id = hash('sha256', $before['mutations']['old-intent']['identity']);
        $accepted = ['record' => $this->client->codec()->encodeJson($known), 'resource_id' => $known->resource_id,
            'topic_id' => $known->topic_id, 'topic_url' => $known->topic_url, 'source_revision' => $known->source_revision];
        $before['to'][$this->record->external_id]['accepted'] = $accepted;
        $this->queue->row->resource_id = $known->resource_id; $this->queue->row->topic_id = $known->topic_id;
        $this->queue->row->cms_source_revision = $known->source_revision;
        $this->store->save($before);
        $changed = clone $known; $changed->source_revision = 'other-current-edition';
        $this->http = new Factory; $this->http->preventStrayRequests(); Http::swap($this->http);
        $this->http->fake(function ($request) use ($changed) {
            $value = str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/connection.json') ? $this->connection : $changed;
            return $this->http->response(json_encode($value), 200, ['Content-Type' => 'application/json']);
        });
        $this->expectException(CmsException::class);
        try { $this->delivery()->retryInvalidField(1, $id, 'native-user'); }
        finally { $this->assertSame($before, $this->store->read()); $this->assertSame(3, $this->queue->row->attempts); }
    }

    public static function admissionFailures(): array { return [['source'], ['closure'], ['move'], ['wrong-fingerprint'], ['preservation'], ['missing-scope']]; }

    #[DataProvider('admissionFailures')]
    public function test_changed_source_lifecycle_wrong_action_and_preservation_refusal_do_not_unlock(string $fault): void
    {
        if ($fault === 'preservation') $this->responseCode = 'content_preservation_unavailable';
        $before = $this->refuse(); $id = hash('sha256', $before['mutations']['old-intent']['identity']);
        if ($fault === 'source') $this->capture->value['full_html'] .= 'actual native edit';
        if ($fault === 'closure' || $fault === 'move') {
            $before['to'][$this->record->external_id][$fault === 'closure' ? 'closure' : 'source_move'] = ['retained' => true];
            $this->store->save($before);
        }
        if ($fault === 'wrong-fingerprint') $id = str_repeat('0', 64);
        if ($fault === 'missing-scope') { unset($before['to_binding']); $this->store->save($before); }
        $this->expectException(CmsException::class);
        try { $this->delivery()->retryInvalidField(1, $id, 'native-user'); }
        finally { $this->assertSame($before, $this->store->read()); $this->assertSame(3, $this->queue->row->attempts); }
    }
}

class RetryMemoryState extends PrivateState
{
    public array $value = ['mutations' => [], 'to' => []];
    public ?Closure $beforeSave = null;
    public function __construct() {}
    public function locked(Closure $operation): mixed { return $operation(); }
    public function read(): array { return unserialize(serialize($this->value)); }
    public function save(array $state): void { if ($this->beforeSave) ($this->beforeSave)($state); $this->value = unserialize(serialize($state)); }
}
class RetryCapture extends ToSourcePreparation
{
    public function __construct(public array $value) {}
    public function capture(object $row): array { return $this->value; }
}
class RetryMemoryQueue
{
    public ?Closure $beforeUpdate = null;
    public function __construct(public object $row) {}
    public function table(string $table): RetryMemoryQuery { return new RetryMemoryQuery($this); }
}
class RetryMemoryQuery
{
    private array $conditions = [];
    public function __construct(private RetryMemoryQueue $queue) {}
    public function where(string $field, mixed $value): self { $this->conditions[$field] = $value; return $this; }
    public function first(): ?object { foreach ($this->conditions as $field => $value) if (($this->queue->row->$field ?? null) !== $value) return null; return clone $this->queue->row; }
    public function update(array $values): int { if ($this->first() === null) return 0; if ($this->queue->beforeUpdate) ($this->queue->beforeUpdate)(); foreach ($values as $field => $value) $this->queue->row->$field = $value; return 1; }
}
