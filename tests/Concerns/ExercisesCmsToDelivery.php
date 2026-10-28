<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\PrivateState;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourcePreparation;
use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\DeliveryWorker;
use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\SourcePublication;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Tests\Fixtures\KnownSafeToSourcePreparation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Entry;

/** Controlled safe preparation; no production HTML-policy or receiver qualification. */
trait ExercisesCmsToDelivery
{
    use ExercisesCmsToWithdrawal;
    use ExercisesCmsToSourceMoves;

    private array $toAccepted = [];
    private array $toRequests = [];
    private ?array $toReceiverRecord = null;
    private bool $toLoseResponse = false;
    private int $toReceiverWrites = 0;
    private array $toWithdrawRequests = [];
    private bool $toLoseWithdrawResponse = false;
    private bool $toRaceWithdraw = false;
    private bool $toRejectResolveBeforeAccept = false;
    private bool $toPreservationRejection = false;
    protected function toProfile(): string
    {
        return Entry::getFacadeRoot() instanceof \Statamic\Eloquent\Entries\EntryRepository ? 'statamic_db' : 'statamic_flat';
    }

    private function toEntry(bool $includeAuthorProfile = true)
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-09-02T09:10:11Z'));
        config(['discussionbridge.collections' => ['discussionbridge'], 'discussionbridge.cms_profile' => $this->toProfile(), 'statamic.system.track_last_update' => true,
            'discussionbridge.cms_state_directory' => dirname(__DIR__, 2).'/.tmp/cms-to-'.bin2hex(random_bytes(8))]);
        if (! $includeAuthorProfile) {
            config(['discussionbridge.source_author_profile_url' => null]);
        }
        $known = ["## Native source\n\nFirst complete é source ending.", "## Native source\n\nChanged complete é source ending."];
        $this->app->instance(ToSourcePreparation::class, new KnownSafeToSourcePreparation(app(Configuration::class), $known));
        $entry = Entry::make()->collection('discussionbridge')->locale('default')->slug('cms-native-to')
            ->published(true)->data(['title' => 'Actual native To source', 'content' => $known[0],
                'discussionbridge_publish' => true, 'created_at' => '2026-09-01T08:00:00Z',
                'updated_at' => (new \DateTimeImmutable('2026-09-02T09:10:11Z'))->getTimestamp()]);
        $this->assertTrue($entry->save());
        $this->toAccepted = $this->toRequests = [];
        $this->toReceiverRecord = null;
        $this->toLoseResponse = false;
        $this->toReceiverWrites = 0;
        $this->toWithdrawRequests = [];
        $this->toLoseWithdrawResponse = $this->toRaceWithdraw = false;
        $this->toRejectResolveBeforeAccept = false;
        $this->toPreservationRejection = false;
        $this->toFake();
        return $entry;
    }

    private function toFake(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (($moveResponse = $this->sourceMoveHttp($request)) !== null) {
                return $moveResponse;
            }
            if ($path === '/discussion-bridge/v4/connection.json') {
                $kind = $this->toProfile() === 'statamic_ssg' ? 'static' : 'dynamic';
                return Http::response(['connection_id' => config('discussionbridge.connection_id'), 'profile' => $this->toProfile(),
                    'destination_origin' => 'https://statamic.example', 'scope_revision' => 'native-to-scope:1', 'forum_name' => 'Native To Forum',
                    'directions' => ['to_discourse'], 'publication_lane' => 'statamic-alpha',
                    'policy' => ['policy_revision' => 'native-to-policy:1', 'enabled' => false, 'selection' => 'all_public',
                        'category_ids' => [], 'include_topic_ids' => [], 'exclude_topic_ids' => [], 'include_unlisted' => false,
                        'publication_kind' => $kind, 'presentation_mode' => 'simple', 'author_ids' => [], 'taxonomy_ids' => []],
                    'native_capabilities' => ['native_revision' => 'native-capabilities:1', 'publication_kind' => $kind,
                        'supported_presentations' => ['simple', 'full', 'interactive'], 'complete_content_supported' => true]], 200, ['Content-Type' => 'application/json']);
            }
            if ($request->method() === 'GET' && $path === '/discussion-bridge/v4/bridge-records.json') {
                return Http::response(['items' => $this->toReceiverRecord === null ? [] : [$this->toReceiverRecord], 'next_cursor' => null], 200, ['Content-Type' => 'application/json']);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/withdraw.json')) {
                $input = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
                $this->toWithdrawRequests[] = $request->body();
                $id = $input['mutation_id'];
                if ($this->toRaceWithdraw) {
                    $this->toReceiverRecord['source_revision'] = 'actual-concurrent-edition:B';
                    $this->toRaceWithdraw = false;
                }
                if (! isset($this->toAccepted[$id])) {
                    if ($input['expected_source_revision'] !== $this->toReceiverRecord['source_revision']) {
                        return Http::response(['outcome' => 'rejected', 'code' => 'revision_conflict', 'retryable' => false,
                            'retry_after_seconds' => null, 'correlation_id' => null, 'core_fallback' => false], 409, ['Content-Type' => 'application/json']);
                    }
                    $this->assertSame('active', $this->toReceiverRecord['state']);
                    $this->toReceiverRecord['state'] = 'withdrawn';
                    $this->toAccepted[$id] = $this->toReceiverRecord;
                    $this->toReceiverWrites++;
                }
                if ($this->toLoseWithdrawResponse) {
                    $this->toLoseWithdrawResponse = false;
                    throw new ConnectionException('Controlled withdrawal response loss.');
                }
                return Http::response($this->toAccepted[$id], 200, ['Content-Type' => 'application/json']);
            }
            if ($request->method() === 'GET' && str_starts_with($path, '/discussion-bridge/v4/bridge-records/')) {
                $this->assertNotNull($this->toReceiverRecord);
                return Http::response($this->toReceiverRecord, 200, ['Content-Type' => 'application/json']);
            }
            $this->assertSame('/discussion-bridge/v4/bridge-records/resolve.json', $path);
            $this->assertSame('POST', $request->method());
            $payload = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            $this->toRequests[] = $request->body();
            if ($this->toPreservationRejection) {
                $this->toPreservationRejection = false;
                return Http::response(['outcome' => 'rejected', 'code' => 'content_preservation_unavailable',
                    'retryable' => false, 'retry_after_seconds' => null, 'correlation_id' => null,
                    'core_fallback' => false], 422, ['Content-Type' => 'application/json']);
            }
            if ($this->toRejectResolveBeforeAccept) {
                $this->toRejectResolveBeforeAccept = false;
                throw new ConnectionException('Controlled unaccepted02 loss.');
            }
            $id = $payload['mutation_id'];
            if (! isset($this->toAccepted[$id])) {
                $input = $payload['bridge_record'];
                $this->assertSame($this->toReceiverRecord['source_revision'] ?? null, $input['expected_source_revision']);
                $this->assertArrayNotHasKey('profile_url', $input['source_authors'][0]);
                $outcome = $this->toReceiverRecord === null ? 'created' : 'updated';
                $result = ['outcome' => $outcome, 'reason' => ['created' => 'record_created', 'resolved' => 'already_resolved', 'updated' => 'source_revision_updated'][$outcome],
                    'resource_id' => '63bad04c-1c2e-4c38-80ce-b379137dbb2c', 'topic_id' => '7',
                    'topic_url' => 'https://forum.example/t/native-to/7', 'direction' => 'to_discourse',
                    'source_revision' => $input['source_revision'], 'synchronized_at' => '2026-10-14T01:02:03.000Z', 'core_fallback' => false];
                $this->toAccepted[$id] = $result;
                $this->toReceiverRecord = ['resource_id' => $result['resource_id'], 'direction' => 'to_discourse', 'state' => 'active',
                    'topic_id' => '7', 'topic_url' => $result['topic_url'], 'external_id' => $input['external_id'],
                    'canonical_url' => $input['canonical_url'], 'source_revision' => $input['source_revision'],
                    'source_created_at' => $input['source_created_at'], 'source_updated_at' => $input['source_updated_at'],
                    'synchronized_at' => $result['synchronized_at'], 'mapping_id' => null];
                $this->toReceiverWrites++;
            }
            if ($this->toLoseResponse) {
                $this->toLoseResponse = false;
                throw new ConnectionException('Controlled accepted response loss.');
            }
            $result = $this->toAccepted[$id];
            return Http::response($result, $result['outcome'] === 'created' ? 201 : 200, ['Content-Type' => 'application/json']);
        });
    }

    public static function knownSafeNativeContentCases(): array
    {
        return [['unformatted native source'], ['![](../media/source-only.png "Native image")']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('knownSafeNativeContentCases')]
    public function test_cms_to_known_safe_plain_and_image_only_native_fixture_uses_actual_pipeline_without_text_gate(string $source): void
    {
        $entry = $this->toEntry(false);
        $this->app->instance(ToSourcePreparation::class, new KnownSafeToSourcePreparation(app(Configuration::class), [$source]));
        $this->assertTrue($entry->set('content', $source)->save());
        $row = DB::table('discussionbridge_deliveries')->sole();
        $captured = app(ToSourcePreparation::class)->capture($row);
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        $sent = json_decode($this->toRequests[0], true, 512, JSON_THROW_ON_ERROR)['bridge_record'];
        $this->assertSame('full', $sent['representation']['kind']);
        $this->assertSame($captured['full_html'], $sent['content_html']);
        $this->assertSame(hash('sha256', $captured['full_html']), $sent['source_content_sha256']);
        $this->assertSame(strlen($captured['full_html']), $sent['source_content_bytes']);
        $accepted = app(PrivateState::class)->locked(fn ($store) => $store->read()['to'][$row->external_id]['accepted']);
        $this->assertSame($captured['native_source'], $accepted['native_source']);
        $this->assertSame($source, base64_decode($accepted['native_source']['content_base64'], true));
        if (str_starts_with($source, '![]')) {
            $this->assertSame('', trim(strip_tags($sent['content_html'])));
            $this->assertStringContainsString('src="https://statamic.example/media/source-only.png"', $sent['content_html']);
        }
    }

    public function test_cms_to_native_queue_replays_exact_accepted_mutation_before_changed_source_without_second_create(): void
    {
        $entry = $this->toEntry();
        $initial = SourcePublication::fromEntry($entry, app(Configuration::class));
        $persisted = app(ToSourcePreparation::class)->capture(DB::table('discussionbridge_deliveries')->sole());
        $this->assertSame($initial, $persisted, 'Save-time and persisted native source publication differ.');
        $this->toLoseResponse = true;
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['failed']);
        $capturedA = app(PrivateState::class)->locked(fn ($store) => $store->read()['to'][DB::table('discussionbridge_deliveries')->sole()->external_id]['pending']['native_source']);
        $this->assertSame($entry->get('content'), base64_decode($capturedA['content_base64'], true));
        $this->assertSame($entry->absoluteUrl(), $capturedA['article_url']);
        $row = DB::table('discussionbridge_deliveries')->sole();
        $this->assertNull($row->cms_source_revision);
        $this->assertSame(1, $this->toReceiverWrites);
        $this->travelTo(\Carbon\Carbon::parse('2026-09-02T09:11:11Z'));
        $entry->set('content', "## Native source\n\nChanged complete é source ending.")->set('updated_at', $entry->get('updated_at') + 60);
        $this->assertTrue($entry->save());
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        $replayed = DB::table('discussionbridge_deliveries')->sole();
        $this->assertSame($capturedA, app(PrivateState::class)->locked(fn ($store) => $store->read()['to'][$replayed->external_id]['accepted']['native_source']));
        $this->assertSame('pending', $replayed->status);
        $this->assertSame($initial['record']['source_publication']['revision'], $replayed->cms_source_revision);
        $this->assertSame($this->toRequests[0], $this->toRequests[1]);
        $this->assertSame(1, $this->toReceiverWrites);
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        $after = DB::table('discussionbridge_deliveries')->sole();
        $this->assertSame('delivered', $after->status);
        $this->assertSame('7', $after->topic_id);
        $this->assertSame($row->entry_id, $after->entry_id);
        $this->assertSame($row->external_id, $after->external_id);
        $this->assertSame($row->canonical_url, $after->canonical_url);
        $this->assertNotSame($replayed->cms_source_revision, $after->cms_source_revision);
        $third = json_decode($this->toRequests[2], true, 512, JSON_THROW_ON_ERROR)['bridge_record'];
        $this->assertSame($replayed->cms_source_revision, $third['expected_source_revision']);
        $this->assertSame($entry instanceof \Statamic\Eloquent\Entries\Entry ? null : '2026-09-01T08:00:00.000Z', $third['source_created_at']);
        $this->assertSame('2026-09-02T09:11:11.000Z', $third['source_updated_at']);
        app(PrivateState::class)->locked(function ($store) use ($after) {
            $state = $store->read();
            $this->assertCount(2, $state['to'][$after->external_id]['accepted_history']);
            $this->assertCount(2, $state['mutations']);
        });
    }

    public function test_cms_to_unqualified_actual_native_html_stops_before_any_receiver_dispatch(): void
    {
        $entry = $this->toEntry();
        $this->app->instance(ToSourcePreparation::class, new ToSourcePreparation(app(Configuration::class)));
        $entry->set('content', 'Native text <script>alert(1)</script>')->save();
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['reconciliation_required']);
        $this->assertSame('native_source_html_unqualified:native_article_function_binding_unavailable', DB::table('discussionbridge_deliveries')->sole()->last_error);
        Http::assertNothingSent();
    }

    public function test_cms_to_configured_native_preparation_delivers_without_a_known_safe_fixture_override(): void
    {
        $entry = $this->toEntry(false);
        $this->app->instance(ToSourcePreparation::class, new ToSourcePreparation(app(Configuration::class)));
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        $this->assertCount(1, $this->toRequests);
        $row = DB::table('discussionbridge_deliveries')->sole();
        $record = json_decode($this->toRequests[0], true, 512, JSON_THROW_ON_ERROR)['bridge_record'];
        $this->assertSame((string) $entry->id(), $row->entry_id);
        $this->assertSame('delivered', $row->status);
        $this->assertSame(hash('sha256', $record['content_html']), $record['source_content_sha256']);
        $this->assertSame(strlen($record['content_html']), $record['source_content_bytes']);
        $this->assertFalse($record['core_fallback'] ?? false);
    }

    public function test_cms_to_definitive_preservation_rejection_retains_exact_pending_and_never_replays_on_work_or_reconcile(): void
    {
        $entry = $this->acceptedToEntry();
        $before = DB::table('discussionbridge_deliveries')->sole();
        $accepted = $this->toState()['to'][$before->external_id]['accepted'];
        $entry->set('content', "## Native source\n\nChanged complete é source ending.")->save();
        $this->toPreservationRejection = true;
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['reconciliation_required']);
        $row = DB::table('discussionbridge_deliveries')->sole();
        $state = $this->toState();
        $pending = $state['to'][$row->external_id]['pending'];
        $mutation = $state['mutations'][$pending['key']];
        $this->assertSame('content_preservation_unavailable', $row->last_error);
        $this->assertNull($row->next_attempt_at);
        $this->assertSame($accepted, $state['to'][$row->external_id]['accepted']);
        $this->assertNull($mutation['result']);
        $this->assertSame('V4-02', $mutation['rejection']['route']);
        $this->assertSame(422, $mutation['rejection']['http_status']);
        $this->assertSame($mutation['mutation_id'], $mutation['rejection']['mutation_id']);
        $this->assertSame($mutation['identity'], $mutation['rejection']['request_identity']);
        $this->assertSame($this->toRequests[1], $mutation['body']);
        $this->assertCount(6, json_decode($mutation['rejection']['envelope'], true, 512, JSON_THROW_ON_ERROR));
        $requests = count(Http::recorded());
        $this->assertSame(0, app(DeliveryWorker::class)->work(1)['processed']);
        $this->artisan('discussionbridge:reconcile')->assertExitCode(0);
        $this->assertSame(0, app(DeliveryWorker::class)->work(1)['processed']);
        $this->assertCount($requests, Http::recorded());
        $this->assertSame($pending, $this->toState()['to'][$row->external_id]['pending']);
        $this->assertSame($mutation, $this->toState()['mutations'][$pending['key']]);
        $this->assertSame(1, $this->toReceiverWrites);
        $this->assertSame($row->attempts, DB::table('discussionbridge_deliveries')->sole()->attempts);
    }

    public function test_cms_to_restart_after_durable_rejection_before_sql_terminalization_sends_no_request(): void
    {
        $entry = $this->acceptedToEntry();
        $entry->set('content', "## Native source\n\nChanged complete é source ending.")->save();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $this->toPreservationRejection = true;
        DB::table('discussionbridge_deliveries')->where('id', $row->id)->update(['status' => 'processing',
            'lock_token' => 'interrupted-native-worker', 'locked_at' => now()->subMinutes(11)]);
        $row = DB::table('discussionbridge_deliveries')->sole();
        app(PrivateState::class)->locked(function () use ($row) {
            try {
                // Exact crash boundary: remote422+private journal persisted,
                // but the DeliveryWorker SQL terminalization has NOT run.
                app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToDelivery::class)->deliver($row);
                $this->fail('The receiver rejection was ignored.');
            } catch (\CodeWorksLabs\DiscussionBridgeStatamic\Cms\DefinitiveRejection $error) {
                $this->assertSame('V4-02', $error->routeId);
            }
        });
        $this->assertSame('processing', DB::table('discussionbridge_deliveries')->sole()->status);
        $state = $this->toState();
        $pending = $state['to'][$row->external_id]['pending'];
        $this->assertArrayHasKey('rejection', $state['mutations'][$pending['key']]);
        $requests = count(Http::recorded());
        $this->app->forgetInstance(CmsClient::class);
        $this->app->forgetInstance(PrivateState::class);
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['reconciliation_required']);
        $this->assertCount($requests, Http::recorded(), 'Restart performed HTTP for a definitively rejected request.');
        $after = $this->toState();
        $this->assertSame($state['mutations'][$pending['key']], $after['mutations'][$pending['key']]);
        $this->assertSame($state['to'][$row->external_id], $after['to'][$row->external_id]);
        $this->assertSame('content_preservation_unavailable', DB::table('discussionbridge_deliveries')->sole()->last_error);
        $this->assertSame(1, $this->toReceiverWrites);
    }

    public function test_cms_to_genuine_changed_current_native_source_continues_without_replaying_rejected_edition(): void
    {
        $entry = $this->acceptedToEntry();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $accepted = $this->toState()['to'][$row->external_id]['accepted'];
        $entry->set('content', "## Native source\n\nChanged complete é source ending.")->save();
        $this->toPreservationRejection = true;
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['reconciliation_required']);
        $rejectedState = $this->toState();
        $rejected = $rejectedState['to'][$row->external_id]['pending'];
        $source = "## Native source\n\nFaithful actual third native edition.";
        $this->app->instance(ToSourcePreparation::class, new KnownSafeToSourcePreparation(app(Configuration::class), [$source]));
        $this->travelTo(\Carbon\Carbon::parse('2026-09-02T09:12:11Z'));
        $this->assertTrue($entry->set('content', $source)->save());
        $this->assertSame('pending', DB::table('discussionbridge_deliveries')->sole()->status);
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        $after = $this->toState();
        $slot = $after['to'][$row->external_id];
        $this->assertSame($rejected, $slot['rejected_history'][$rejected['key']]['pending']);
        $this->assertSame($rejectedState['mutations'][$rejected['key']], $after['mutations'][$rejected['key']]);
        $this->assertSame($accepted, $slot['accepted_history'][$accepted['mutation_key']]);
        $this->assertSame($accepted['resource_id'], $slot['accepted']['resource_id']);
        $this->assertSame($accepted['topic_id'], $slot['accepted']['topic_id']);
        $this->assertCount(3, $this->toRequests);
        $current = json_decode($this->toRequests[2], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($accepted['source_revision'], $current['bridge_record']['expected_source_revision']);
        $this->assertNotSame(json_decode($this->toRequests[1], true)['mutation_id'], $current['mutation_id']);
        $this->assertStringContainsString('Faithful actual third native edition.', $current['bridge_record']['content_html']);
        $this->assertSame(2, $this->toReceiverWrites);
    }

    public function test_cms_to_rejected_first_creation_allows_actual_new_source_without_fabricated_detail_baseline(): void
    {
        $entry = $this->toEntry();
        $this->toPreservationRejection = true;
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['reconciliation_required']);
        $row = DB::table('discussionbridge_deliveries')->sole();
        $pending = $this->toState()['to'][$row->external_id]['pending'];
        $this->assertSame(0, $this->toReceiverWrites);
        $this->assertTrue($entry->set('content', "## Native source\n\nChanged complete é source ending.")->save());
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        $slot = $this->toState()['to'][$row->external_id];
        $this->assertSame($pending, $slot['rejected_history'][$pending['key']]['pending']);
        $this->assertSame(1, $this->toReceiverWrites);
        $this->assertNull(json_decode($this->toRequests[1], true)['bridge_record']['expected_source_revision']);
        Http::assertNotSent(fn ($request) => $request->method() === 'GET'
            && preg_match('~/bridge-records/[0-9a-f-]+\.json$~', $request->url()));
    }

    public function test_cms_to_actual_return_to_accepted_native_snapshot_retires_rejection_without_new_resolve(): void
    {
        $entry = $this->acceptedToEntry();
        $original = $entry->get('content');
        $row = DB::table('discussionbridge_deliveries')->sole();
        $accepted = $this->toState()['to'][$row->external_id]['accepted'];
        $entry->set('content', "## Native source\n\nChanged complete é source ending.")->save();
        $this->toPreservationRejection = true;
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['reconciliation_required']);
        $rejected = $this->toState()['to'][$row->external_id]['pending'];
        $this->assertTrue($entry->set('content', $original)->save());
        $this->assertSame('pending', DB::table('discussionbridge_deliveries')->sole()->status);
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        $slot = $this->toState()['to'][$row->external_id];
        $this->assertArrayNotHasKey('pending', $slot);
        $this->assertSame($accepted, $slot['accepted']);
        $this->assertSame($rejected, $slot['rejected_history'][$rejected['key']]['pending']);
        $this->assertNull($slot['rejected_history'][$rejected['key']]['next_intent_key']);
        $this->assertCount(2, $this->toRequests);
        $this->assertSame(1, $this->toReceiverWrites);
    }

    public function test_cms_to_known_accepted_withdrawal_preserves_rejected_pending_without_replay(): void
    {
        $entry = $this->acceptedToEntry();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $entry->set('content', "## Native source\n\nChanged complete é source ending.")->save();
        $this->toPreservationRejection = true;
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['reconciliation_required']);
        $rejected = $this->toState();
        $pending = $rejected['to'][$row->external_id]['pending'];
        $this->assertTrue($entry->published(false)->save());
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['withdrawn']);
        $after = $this->toState();
        $this->assertSame($pending, $after['to'][$row->external_id]['pending']);
        $this->assertSame($rejected['mutations'][$pending['key']], $after['mutations'][$pending['key']]);
        $this->assertSame($rejected['to'][$row->external_id]['accepted'], $after['to'][$row->external_id]['accepted']);
        $this->assertCount(2, $this->toRequests);
        $this->assertCount(1, $this->toWithdrawRequests);
        $this->assertSame($row->resource_id, DB::table('discussionbridge_deliveries')->sole()->resource_id);
        $this->assertSame('withdrawn', $this->toReceiverRecord['state']);
    }

    public function test_cms_to_and_from_use_the_same_exclusive_writer(): void
    {
        $this->toEntry();
        app(PrivateState::class)->locked(function () {
            try {
                app(DeliveryWorker::class)->work(1);
                $this->fail('A second native publisher acquired the held writer.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('Nested CMS writer lock', $error->getMessage());
            }
        });
        Http::assertNothingSent();
    }

    public function test_cms_to_imported_native_publication_marker_excludes_feedback_even_when_toggle_is_true(): void
    {
        $entry = $this->toEntry();
        DB::table('discussionbridge_deliveries')->delete();
        $this->assertTrue($entry->set('discussionbridge_native_publication', true)->set('discussionbridge_publish', true)->save());
        $this->assertSame(0, DB::table('discussionbridge_deliveries')->count());
        $this->assertSame(0, app(DeliveryWorker::class)->work(1)['processed']);
        Http::assertNothingSent();
    }

    public function test_cms_to_capture_uses_persisted_publication_instead_of_native_preview_substitute(): void
    {
        $entry = $this->toEntry();
        $preview = clone $entry;
        $preview->set('content', 'Unpublished preview that was never committed.')->published(false);
        Entry::getFacadeRoot()->substitute($preview);
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        $record = json_decode($this->toRequests[0], true, 512, JSON_THROW_ON_ERROR)['bridge_record'];
        $this->assertStringContainsString('First complete é source ending.', $record['content_html']);
        $this->assertStringNotContainsString('Unpublished preview', $record['content_html']);
        $this->assertSame('delivered', DB::table('discussionbridge_deliveries')->sole()->status);
    }

    public function test_current_to_acceptance_recovers_missing_sql_readback_without_second_receiver_write(): void
    {
        $entry = $this->toEntry();
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        $row = DB::table('discussionbridge_deliveries')->sole();
        $widget = app(\CodeWorksLabs\DiscussionBridgeStatamic\Presentation\DeliveryPresenter::class)->render($row->entry_id);
        $this->assertStringContainsString('Native To Forum', $widget);
        $this->assertStringContainsString('"topicId":"7"', $widget);
        DB::table('discussionbridge_deliveries')->where('id', $row->id)->update(['resource_id' => null, 'topic_id' => null,
            'topic_url' => null, 'cms_source_revision' => null, 'cms_native_revision' => null, 'status' => 'pending']);
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        $after = DB::table('discussionbridge_deliveries')->sole();
        $this->assertSame($row->resource_id, $after->resource_id);
        $this->assertSame($row->topic_id, $after->topic_id);
        $this->assertSame($row->cms_source_revision, $after->cms_source_revision);
        $this->assertSame(1, $this->toReceiverWrites);
        $this->assertCount(1, $this->toRequests);
    }

    public function test_current_to_withdrawn_receiver_binding_is_not_rewritten_or_automatically_resumed(): void
    {
        $entry = $this->toEntry();
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        $entry->set('content', "## Native source\n\nChanged complete é source ending.")->save();
        $this->toReceiverRecord['state'] = 'withdrawn';
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['reconciliation_required']);
        $this->assertSame('native_ownership_conflict', DB::table('discussionbridge_deliveries')->sole()->last_error);
        $this->assertSame(1, $this->toReceiverWrites);
        $this->assertCount(1, $this->toRequests);
    }
    public static function generatedToCases(): array
    {
        return [['native-html-bound'], ['native-envelope-bound']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('generatedToCases')]
    public function test_cms_to_generated_excerpt_preserves_complete_source_and_exact_owned_article_boundary(string $cause): void
    {
        $entry = $this->toEntry();
        $source = "[original link](../source/link) ![original image](images/original.png)\n\n"
            .($cause === 'native-html-bound' ? str_repeat('Complete é source ', 6000).'FINAL-END' : "```\n".str_repeat(chr(92), 34000)."\n```");
        $this->app->instance(ToSourcePreparation::class, new KnownSafeToSourcePreparation(app(Configuration::class), [$source]));
        $entry->set('content', $source)->save();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $complete = app(ToSourcePreparation::class)->capture($row)['full_html'];
        $this->assertStringContainsString('href="https://statamic.example/source/link"', $complete);
        $this->assertStringContainsString('src="https://statamic.example/discussionbridge/images/original.png"', $complete);
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        $request = json_decode($this->toRequests[0], false, 512, JSON_THROW_ON_ERROR);
        $record = $request->bridge_record;
        $this->assertSame('generated_excerpt', $record->representation->kind);
        $this->assertSame(strlen($complete), $record->source_content_bytes);
        $this->assertSame(hash('sha256', $complete), $record->source_content_sha256);
        $this->assertLessThanOrEqual(49152, strlen($record->content_html));
        $this->assertLessThanOrEqual(65536, strlen($this->toRequests[0]));
        if ($cause === 'native-envelope-bound') {
            $this->assertLessThanOrEqual(49152, strlen($complete));
            $this->assertGreaterThan(65536, $record->representation->selection_full_request_bytes);
        } else {
            $this->assertGreaterThan(49152, strlen($complete));
        }
        $codec = app(CmsClient::class)->codec();
        $this->assertSame($codec->measureToFullEnvelope($record, $complete), $record->representation->selection_full_request_bytes);
        $prefix = substr($record->content_html, 0, $record->representation->article_content_bytes);
        $this->assertSame(hash('sha256', $prefix), $record->representation->article_content_sha256);
        $this->assertSame($codec->generatedReadMore($row->canonical_url), substr($record->content_html, strlen($prefix)));
        $this->assertStringNotContainsString('data-discussionbridge-generated', $prefix);
        $this->assertSame($record->source_revision, $record->representation->selection_source_revision);
        $this->assertSame($record, $codec->validateGeneratedSelection($record));
        $after = DB::table('discussionbridge_deliveries')->sole();
        $this->assertSame($row->entry_id, $after->entry_id);
        $this->assertSame($row->external_id, $after->external_id);
        $this->assertSame($row->canonical_url, $after->canonical_url);
        app(PrivateState::class)->locked(function ($store) use ($after, $record) {
            $accepted = $store->read()['to'][$after->external_id]['accepted'];
            $this->assertEquals($record, json_decode($accepted['record'], false, 512, JSON_THROW_ON_ERROR));
            $this->assertSame('Native To Forum', $accepted['forum_name']);
        });
    }


}
