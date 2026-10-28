<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns;

use Carbon\CarbonImmutable;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\PrivateState;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourceArticleProfile;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourceSnapshot;
use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\DeliveryWorker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Statamic\Facades\Entry;

/** Native source operations and Antlers; receiver is a controlled typed peer. */
trait ExercisesCmsToSourceMoves
{
    private bool $sourceMovesActive = false;
    private array $sourceMoveRequests = [];
    private array $sourceMoveResults = [];
    private array $sourceMoveHistory = [];
    private ?array $sourceMovePrepared = null;
    private ?string $sourceMoveLose = null;
    private ?string $sourceMovePublicFault = null;
    private ?string $sourceMovePublicDirectory = null;
    private array $sourceMoveObserved = [];
    private bool $sourceMoveReject = false;

    private function sourceMoveEntry(string $scenario = 'normal', ?string $source = null)
    {
        $entry = $this->toEntry(false);
        if ($source !== null) {
            $this->app->instance(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourcePreparation::class,
                new \CodeWorksLabs\DiscussionBridgeStatamic\Tests\Fixtures\KnownSafeToSourcePreparation(
                    app(\CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration::class), [$source]));
            $entry->set('content', $source)->save();
        }
        $this->travelBack(); // Local process receipts and actual proof use the host clock.
        $this->isolatePresentationFiles();
        $this->beforeApplicationDestroyed(fn () => $this->restorePresentationFiles());
        $template = 'discussionbridge-source-move-'.bin2hex(random_bytes(6));
        $layout = $template.'-layout';
        $this->writePresentationFile(resource_path('views/'.$template.'.antlers.html'), '{{ discussionbridge:article }}');
        $this->writePresentationFile(resource_path('views/'.$layout.'.antlers.html'), '<!doctype html><html><body><header>Native chrome</header>{{ template_content }}</body></html>');
        $entry->collection()->template($template)->layout($layout)->save();
        config(['discussionbridge.cms_source_templates' => [$template]]);
        $this->sourceMovesActive = true;
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        if ($this->toProfile() === 'statamic_ssg') {
            $this->app->bind(\Statamic\StaticSite\Tasks::class, \Statamic\StaticSite\ConsecutiveTasks::class);
            $public = dirname(__DIR__, 2).'/.tmp/cms-static-public-'.bin2hex(random_bytes(8));
            mkdir($public, 0700, true);
            $hook = ['argv' => [PHP_BINARY, '-n', dirname(__DIR__).'/fixtures/cms-static-hook.php', $public, $scenario],
                'cwd' => dirname(__DIR__, 2), 'timeout_seconds' => 10];
            config(['discussionbridge.cms_source_static_public_directory' => $public,
                'discussionbridge.cms_static_hooks' => ['deploy' => $hook, 'reconcile' => $hook, 'restore' => $hook]]);
            $this->sourceMovePublicDirectory = $public;
            $this->assertSame(0, $this->artisan('statamic:ssg:generate', ['--workers' => 1, '--no-interaction' => true])->run());
            $source = config('statamic.ssg.destination');
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
                $this->assertFalse($file->isLink());
                $target = $public.substr($file->getPathname(), strlen($source));
                $file->isDir() ? mkdir($target, 0700, true) : copy($file->getPathname(), $target);
            }
        }
        return Entry::find($entry->id());
    }

    private function sourceMoveHttp($request)
    {
        if (! $this->sourceMovesActive) {
            return null;
        }
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (parse_url($request->url(), PHP_URL_HOST) === 'statamic.example') {
            $this->assertSame('GET', $request->method());
            foreach (['Authorization', 'Cookie', 'X-DiscussionBridge-Connection', 'X-DiscussionBridge-Secret'] as $name) {
                $this->assertFalse($request->hasHeader($name), $name);
            }
            $row = DB::table('discussionbridge_deliveries')->sole();
            $entry = app(ToSourceSnapshot::class)->read($row)['entry'];
            if ($this->sourceMovePublicDirectory !== null) {
                $configuration = config('statamic.ssg');
                $configuration['destination'] = $this->sourceMovePublicDirectory;
                $page = new \Statamic\StaticSite\Page(app(\Illuminate\Filesystem\Filesystem::class), $configuration, $entry);
                // Real deployed paths only. No root-index fallback for absent pages.
                $relative = ltrim($path, '/');
                $native = $page->path();
                $file = $this->sourceMovePublicDirectory.'/'.$relative.(str_ends_with($relative, '.html') ? '' : '/index.html');
                if ($path === parse_url($entry->absoluteUrl(), PHP_URL_PATH)) {
                    $this->assertSame(str_replace('\\', '/', $native), str_replace('\\', '/', $file));
                }
                $html = is_file($file) ? file_get_contents($file) : '';
            } else {
                $html = \Statamic\View\View::make($entry->template())->layout($entry->layout())->cascadeContent($entry)->render();
            }
            $this->sourceMoveObserved[] = ['url' => $request->url(), 'body' => $html];
            $html = match ($request->url() === ($this->sourceMovePrepared['new_url'] ?? null) ? $this->sourceMovePublicFault : null) {
                'body' => str_replace('First complete é source ending.', 'Different source.', $html),
                'duplicate' => $html.$html,
                'nonce' => str_replace('data-proof-nonce="', 'data-proof-nonce="wrong', $html),
                default => $html,
            };
            $status = $html === '' ? 404 : ($this->sourceMovePublicFault === 'redirect'
                && $request->url() === ($this->sourceMovePrepared['new_url'] ?? null) ? 302 : 200);
            if (method_exists($this, 'sourceMoveSocketResponse')) {
                return $this->sourceMoveSocketResponse($request, $html, $status);
            }
            return Http::response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        if ($request->method() === 'GET' && str_ends_with($path, '/url-history.json')) {
            return Http::response(['items' => $this->sourceMoveHistory, 'next_cursor' => null], 200, ['Content-Type' => 'application/json']);
        }
        if ($request->method() !== 'POST' || ! str_contains($path, '/source-url-moves')) {
            return null;
        }
        $input = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
        $operation = str_ends_with($path, '/finalize.json') ? 'finalize' : (str_ends_with($path, '/abort.json') ? 'abort' : 'prepare');
        $this->sourceMoveRequests[$operation][] = $request->body();
        $id = $input['mutation_id'];
        if ($operation === 'finalize' && $this->sourceMoveReject) {
            return Http::response(['outcome' => 'rejected', 'code' => 'content_preservation_unavailable',
                'retryable' => false, 'retry_after_seconds' => null, 'correlation_id' => null, 'core_fallback' => false],
                422, ['Content-Type' => 'application/json']);
        }
        if (! isset($this->sourceMoveResults[$id])) {
            $this->assertSame($this->toReceiverRecord['source_revision'], $input['expected_source_revision']);
            if ($operation === 'prepare') {
                $this->assertSame($this->toReceiverRecord['canonical_url'], $input['old_url']);
                $this->assertSame($this->toReceiverRecord['external_id'], $input['external_id']);
                $result = ['move_id' => (string) Str::uuid(), 'resource_id' => $this->toReceiverRecord['resource_id'],
                    'expected_source_revision' => $input['expected_source_revision'], 'old_url' => $input['old_url'], 'new_url' => $input['new_url'],
                    'proof_nonce' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), 'state' => 'prepared',
                    'prepared_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z')];
                $this->sourceMovePrepared = $result;
            } elseif ($operation === 'abort') {
                $this->assertFalse($input['native_change_applied']);
                $this->assertSame($this->sourceMovePrepared['old_url'], $input['old_url']);
                $result = [...$this->sourceMovePrepared, 'state' => 'aborted'];
            } else {
                $this->assertSame($this->sourceMovePrepared['proof_nonce'], $input['proof_nonce']);
                $this->assertSame($this->sourceMovePrepared['new_url'], $input['verification']['url']);
                $this->assertSame($input['new_source_revision'], $input['verification']['source_revision']);
                $this->assertNotSame($input['expected_source_revision'], $input['new_source_revision']);
                $this->toReceiverRecord['canonical_url'] = $this->sourceMovePrepared['new_url'];
                $this->toReceiverRecord['source_revision'] = $input['new_source_revision'];
                $this->toReceiverRecord['source_updated_at'] = $input['source_updated_at'];
                $this->toReceiverRecord['synchronized_at'] = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z');
                $result = $this->toReceiverRecord;
                $this->sourceMoveHistory[] = ['url' => $this->sourceMovePrepared['old_url'],
                    'retired_at' => $result['synchronized_at'], 'move_id' => $this->sourceMovePrepared['move_id']];
                $this->toReceiverWrites++;
            }
            $this->sourceMoveResults[$id] = $result;
        }
        if ($this->sourceMoveLose === $operation) {
            $this->sourceMoveLose = null;
            throw new ConnectionException('Controlled source-move response loss: '.$operation);
        }
        return Http::response($this->sourceMoveResults[$id], $operation === 'prepare' ? 201 : 200, ['Content-Type' => 'application/json']);
    }

    public static function sourceMoveNativeCalls(): array
    {
        return [['ordinary'], ['quiet'], ['repository']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sourceMoveNativeCalls')]
    public function test_current_to_source_move_uses_same_native_entry_full_antlers_article_and_retains29_edition(string $operation): void
    {
        $entry = $this->sourceMoveEntry();
        $before = DB::table('discussionbridge_deliveries')->sole();
        $accepted = $this->toState()['to'][$before->external_id]['accepted'];
        $entry->slug('cms-native-moved');
        match ($operation) {
            'ordinary' => $this->assertTrue($entry->save()),
            'quiet' => $this->assertTrue($entry->saveQuietly()),
            'repository' => Entry::getFacadeRoot()->save($entry),
        };
        $move = $this->toState()['to'][$before->external_id]['source_move'];
        $this->assertSame('native_applied', $move['stage']);
        $this->assertSame($accepted, $move['accepted_before']);
        $this->assertSame('https://statamic.example/discussionbridge/cms-native-moved', $move['new_url']);
        $this->assertSame($move['new_url'], $move['proposed_source']['record']['canonical_url']);
        $this->assertSame($before->canonical_url, $move['proposed_source']['native_source']['article_url']);
        $summary = app(DeliveryWorker::class)->work(1);
        $this->assertSame(1, $summary['delivered'], json_encode([$summary, DB::table('discussionbridge_deliveries')->sole()->last_error, $this->sourceMoveObserved]));
        $after = DB::table('discussionbridge_deliveries')->sole();
        foreach (['entry_id', 'external_id', 'resource_id', 'topic_id', 'topic_url'] as $field) {
            $this->assertSame($before->$field, $after->$field, $field);
        }
        $slot = $this->toState()['to'][$before->external_id];
        $this->assertArrayNotHasKey('source_move', $slot);
        $this->assertCount(1, $slot['source_move_history']);
        $this->assertSame($accepted, $slot['accepted_history'][$accepted['mutation_key']]);
        $this->assertNotSame($before->cms_source_revision, $after->cms_source_revision);
        $this->assertSame($move['new_source_revision'], $after->cms_source_revision);
        $priorRecord = json_decode($accepted['record'], true);
        $record = json_decode($slot['accepted']['record'], true);
        foreach (['content_html', 'source_content_sha256', 'source_content_bytes', 'title', 'source_authors', 'source_created_at'] as $field) {
            $this->assertSame($priorRecord[$field], $record[$field], $field);
        }
        $proof = json_decode($this->sourceMoveRequests['finalize'][0], true)['verification'];
        $this->assertSame(ToSourceArticleProfile::PROFILE, $proof['verification_profile']);
        $this->assertSame(hash('sha256', app(ToSourceArticleProfile::class)->expectedFrame($move['projection'])), $proof['native_publication_sha256']);
        $this->assertStringContainsString(str_replace("\r\n", "\n", $move['source']['full_html']), str_replace("\r\n", "\n", $this->sourceMoveObserved[0]['body']));
        $this->assertStringContainsString($this->sourceMovePrepared['proof_nonce'], $this->sourceMoveObserved[0]['body']);
        $this->assertSame(1, count($this->toRequests));
        $this->assertSame(0, app(DeliveryWorker::class)->work(1)['processed']);
        Entry::find($entry->id())->save();
        app(DeliveryWorker::class)->work(1);
        $this->assertSame($after->cms_source_revision, DB::table('discussionbridge_deliveries')->sole()->cms_source_revision);
        $this->assertCount(1, $this->toRequests, 'Unchanged native source must keep accepted29 edition without new02.');
    }

    public function test_current_to_cancelled_source_move_aborts_only_confirmed_unchanged_native_and_releases_writer(): void
    {
        $entry = $this->sourceMoveEntry();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $accepted = $this->toState()['to'][$row->external_id]['accepted'];
        Event::listen(\Statamic\Events\EntrySaving::class, fn () => false);
        $this->assertFalse($entry->slug('cms-native-cancelled')->save());
        $slot = $this->toState()['to'][$row->external_id];
        $this->assertArrayNotHasKey('source_move', $slot);
        $this->assertSame($accepted, $slot['accepted']);
        $history = array_values($slot['source_move_history'])[0];
        $this->assertSame('aborted', $history['stage']);
        $this->assertSame('cancelled', $history['native_operation_completed']);
        $this->assertSame($this->sourceMovePrepared['move_id'], $history['abort_receipt']['move_id']);
        $this->assertSame($this->sourceMovePrepared['proof_nonce'], $history['abort_receipt']['issued_public_nonce']);
        $this->assertSame($row->cms_source_revision, $history['abort_receipt']['current_source_revision']);
        $this->assertSame(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourceOwnership::sha256($history['abort_receipt']),
            json_decode($this->sourceMoveRequests['abort'][0], true)['native_ownership_receipt_sha256']);
        $this->assertSame($row->canonical_url, app(ToSourceSnapshot::class)->read($row)['snapshot']['canonical_url']);
        $this->assertFalse(json_decode($this->sourceMoveRequests['abort'][0], true)['native_change_applied']);
        $this->assertSame(0, app(DeliveryWorker::class)->work(1)['processed']);
        $this->assertCount(1, $this->toRequests);
    }

    public static function sourceMoveResponseLosses(): array
    {
        return [['prepare'], ['finalize'], ['abort']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sourceMoveResponseLosses')]
    public function test_current_to_source_move_restarts_exact_mutation_without_repeating_native_operation(string $operation): void
    {
        $entry = $this->sourceMoveEntry();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $this->sourceMoveLose = $operation;
        if ($operation === 'abort') {
            Event::listen(\Statamic\Events\EntrySaving::class, fn () => false);
        }
        try {
            $entry->slug('cms-native-lost')->save();
            $this->assertSame('finalize', $operation);
        } catch (\CodeWorksLabs\DiscussionBridgeStatamic\Cms\UncertainMutation) {
            $this->assertNotSame('finalize', $operation);
        }
        if ($operation === 'finalize') {
            $this->assertSame(1, app(DeliveryWorker::class)->work(1)['failed']);
            $this->assertSame(2, $this->toReceiverWrites);
        }
        $snapshot = app(ToSourceSnapshot::class)->read($row)['snapshot'];
        $this->travel(2)->minutes();
        app(DeliveryWorker::class)->work(1);
        $this->assertSame($this->sourceMoveRequests[$operation][0], $this->sourceMoveRequests[$operation][1]);
        $slot = $this->toState()['to'][$row->external_id];
        $this->assertArrayNotHasKey('source_move', $slot);
        $this->assertSame($snapshot, app(ToSourceSnapshot::class)->read($row)['snapshot']);
        $this->assertSame($operation === 'finalize' ? 'finalized' : 'aborted', array_values($slot['source_move_history'])[0]['stage']);
        $this->assertSame($operation === 'finalize' ? 2 : 1, $this->toReceiverWrites);
        $this->assertCount(1, $this->toRequests);
    }

    public static function sourceMoveBadPages(): array
    {
        return [['body'], ['nonce'], ['duplicate'], ['redirect']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sourceMoveBadPages')]
    public function test_current_to_source_move_rejects_wrong_full_public_article_and_never_claims_unapplied(string $fault): void
    {
        $entry = $this->sourceMoveEntry();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $accepted = $this->toState()['to'][$row->external_id]['accepted'];
        $entry->slug('cms-native-invalid-proof')->save();
        $this->sourceMovePublicFault = $fault;
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['reconciliation_required']);
        $state = $this->toState();
        $move = $state['to'][$row->external_id]['source_move'];
        $this->assertSame('native_applied', $move['stage']);
        $this->assertSame($accepted, $state['to'][$row->external_id]['accepted']);
        $this->assertArrayNotHasKey('finalize', $this->sourceMoveRequests);
        $this->assertArrayNotHasKey('abort', $this->sourceMoveRequests);
        $this->assertSame($move['new_url'], app(ToSourceSnapshot::class)->read($row)['snapshot']['canonical_url']);
        if ($this->toProfile() === 'statamic_ssg') {
            $transaction = $state['source_static'][$move['capture_id']];
            $this->assertTrue($transaction['public_artifact_restored']);
            $this->assertTrue($transaction['native_change_applied']);
            $this->assertSame('restored', $transaction['restoration']['outcome']);
            $this->assertSame($row->canonical_url, $transaction['public_restore_verification']['url']);
            $this->assertArrayNotHasKey('lease', $transaction);
            $this->assertArrayNotHasKey('static', $state, 'Source move must not masquerade as From Work.');
        }
    }

    public function test_current_to_source_move_durable29_rejection_precedes_terminal_sql_and_blocks_stale_claim_replay(): void
    {
        $entry = $this->sourceMoveEntry();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $entry->slug('cms-native-rejected')->save();
        $this->sourceMoveReject = true;
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['reconciliation_required']);
        $before = $this->toState();
        $move = $before['to'][$row->external_id]['source_move'];
        $this->assertSame(422, $before['mutations'][$move['finalize_key']]['rejection']['http_status']);
        DB::table('discussionbridge_deliveries')->where('id', $row->id)->update(['status' => 'processing',
            'lock_token' => (string) Str::uuid(), 'locked_at' => now()->subMinutes(11)]);
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['reconciliation_required']);
        $this->assertCount(1, $this->sourceMoveRequests['finalize']);
        $after = $this->toState();
        $this->assertSame($before['mutations'][$move['finalize_key']], $after['mutations'][$move['finalize_key']]);
        $this->assertSame($before['to'][$row->external_id]['accepted'], $after['to'][$row->external_id]['accepted']);
        $this->assertSame('verified', $after['to'][$row->external_id]['source_move']['stage']);
        $this->assertArrayNotHasKey('abort', $this->sourceMoveRequests);
    }

    public function test_current_to_source_move_stale_native_editor_cannot_replace_applied_source(): void
    {
        $entry = $this->sourceMoveEntry();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $stale = app(ToSourceSnapshot::class)->read($row)['entry'];
        $entry->slug('cms-native-fenced')->save();
        $before = app(ToSourceSnapshot::class)->read($row)['snapshot'];
        try {
            $stale->set('content', 'Stale editor overwrites source.')->saveQuietly();
            $this->fail('Stale native editor bypassed current source fence.');
        } catch (CmsException $error) {
            $this->assertSame('native_ownership_conflict', $error->failureCode);
        }
        $this->assertSame($before, app(ToSourceSnapshot::class)->read($row)['snapshot']);
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
    }

    public function test_current_to_source_move_generated_excerpt_changes_only_owned_link_and_proves_entire_article(): void
    {
        $raw = "[original section](#kept) and [source view](?view=1).\n\n".str_repeat('Complete é source ', 16000).'FINAL-END';
        $entry = $this->sourceMoveEntry('normal', $raw);
        $row = DB::table('discussionbridge_deliveries')->sole();
        $prior = $this->toState()['to'][$row->external_id]['accepted'];
        $old = json_decode($prior['record']);
        $this->assertSame('generated_excerpt', $old->representation->kind);
        $entry->slug('cms-native-long-moved')->save();
        $move = $this->toState()['to'][$row->external_id]['source_move'];
        $this->assertGreaterThan(262144, strlen($move['source']['full_html']));
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        $accepted = $this->toState()['to'][$row->external_id]['accepted'];
        $new = json_decode($accepted['record']);
        $this->assertSame($old->representation->article_content_bytes, $new->representation->article_content_bytes);
        $this->assertSame($old->representation->article_content_sha256, $new->representation->article_content_sha256);
        $this->assertSame(substr($old->content_html, 0, $old->representation->article_content_bytes), substr($new->content_html, 0, $new->representation->article_content_bytes));
        $this->assertSame($move['new_url'], $new->representation->read_more_url);
        $this->assertSame($old->source_content_sha256, $new->source_content_sha256);
        $this->assertSame($old->source_content_bytes, $new->source_content_bytes);
        $this->assertSame($raw, Entry::find($entry->id())->get('content'));
        $this->assertSame($prior, $this->toState()['to'][$row->external_id]['accepted_history'][$prior['mutation_key']]);
        $body = $this->sourceMoveObserved[0]['body'];
        $this->assertStringContainsString('href="'.$row->canonical_url.'#kept"', $body);
        $this->assertStringContainsString('href="'.$row->canonical_url.'?view=1"', $body);
        $this->assertStringNotContainsString('href="'.$move['new_url'].'#kept"', $body);
        $this->assertSame(16000, substr_count($body, 'Complete é source '));
        $this->assertStringContainsString('FINAL-END', $body);
        $this->assertCount(1, $this->toRequests);
    }

    public function test_current_to_source_move_recovers_private_acceptance_before_sql_without_another_native_or_receiver_write(): void
    {
        $entry = $this->sourceMoveEntry();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $entry->slug('cms-native-private-accepted')->save();
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
        $finished = $this->toState();
        $slot = $finished['to'][$row->external_id];
        $final = array_values($slot['source_move_history'])[0];
        $before = app(ToSourceSnapshot::class)->read($row)['snapshot'];
        app(PrivateState::class)->locked(function ($store) use ($finished, $final, $row) {
            $finished['to'][$row->external_id]['source_move'] = $final;
            $store->save($finished); // Crash window: exact private acceptance exists.
            DB::table('discussionbridge_deliveries')->where('id', $row->id)->update([
                'canonical_url' => $row->canonical_url, 'cms_source_revision' => $row->cms_source_revision,
                'cms_native_revision' => $row->cms_native_revision, 'desired_revision' => $row->desired_revision,
                'status' => 'processing', 'lock_token' => (string) Str::uuid(), 'locked_at' => now()->subMinutes(11)]);
        });
        app(DeliveryWorker::class)->work(1);
        $after = $this->toState()['to'][$row->external_id];
        $this->assertArrayNotHasKey('source_move', $after);
        $this->assertSame($slot['accepted'], $after['accepted']);
        $this->assertSame($slot['accepted_history'], $after['accepted_history']);
        $this->assertSame($slot['source_move_history'], $after['source_move_history']);
        $this->assertSame($before, app(ToSourceSnapshot::class)->read($row)['snapshot']);
        $this->assertSame($final['new_source_revision'], DB::table('discussionbridge_deliveries')->sole()->cms_source_revision);
        $this->assertCount(1, $this->sourceMoveRequests['finalize']);
        $this->assertCount(1, $this->toRequests);
    }

    public function test_current_to_source_move_throwing_post_save_keeps_actual_applied_outcome_and_releases_writer(): void
    {
        $entry = $this->sourceMoveEntry();
        $row = DB::table('discussionbridge_deliveries')->sole();
        Event::listen(\Statamic\Events\EntrySaved::class,
            fn () => throw new \RuntimeException('Controlled native source post-save failure.'));
        try {
            $entry->slug('cms-native-post-save')->save();
            $this->fail('Actual post-save exception was hidden.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Controlled native source post-save failure.', $error->getMessage());
        }
        $move = $this->toState()['to'][$row->external_id]['source_move'];
        $this->assertSame('threw', $move['native_operation_completed']);
        $this->assertSame('native_applied', $move['stage']);
        $this->assertSame($move['new_url'], app(ToSourceSnapshot::class)->read($row)['snapshot']['canonical_url']);
        $this->assertArrayNotHasKey('abort', $this->sourceMoveRequests);
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
    }

    public function test_current_to_source_move_native_template_must_preserve_full_article_even_with_matching_nonce_and_markers(): void
    {
        $entry = $this->sourceMoveEntry();
        $this->writePresentationFile(resource_path('views/'.$entry->layout().'.antlers.html'),
            '<!doctype html><html><body><header>Native chrome</header>{{ if slug == "cms-native-template-loss" }}<!--native-loss-fixture-->{{ template_content | replace:First:Different }}{{ else }}{{ template_content }}{{ /if }}</body></html>');
        view()->getFinder()->flush();
        $row = DB::table('discussionbridge_deliveries')->sole();
        $accepted = $this->toState()['to'][$row->external_id]['accepted'];
        $entry->slug('cms-native-template-loss')->save();
        $move = $this->toState()['to'][$row->external_id]['source_move'];
        $actual = app(ToSourceSnapshot::class)->read($row);
        $page = app(ToSourceArticleProfile::class)->renderPage($actual['entry']);
        $this->assertStringContainsString('<!--native-loss-fixture-->', $page);
        $this->assertStringContainsString('Different complete é source ending.', $page);
        $this->assertStringContainsString($move['projection']['proof_nonce'], $page);
        foreach (app(ToSourceArticleProfile::class)->markers($move['projection']) as $marker) {
            $this->assertStringContainsString($marker, $page);
        }
        $this->assertSame(1, app(DeliveryWorker::class)->work(1)['reconciliation_required']);
        $this->assertSame('native_source_article_preservation_unavailable', DB::table('discussionbridge_deliveries')->sole()->last_error);
        $this->assertSame($accepted, $this->toState()['to'][$row->external_id]['accepted']);
        $this->assertArrayNotHasKey('finalize', $this->sourceMoveRequests);
        $this->assertArrayNotHasKey('abort', $this->sourceMoveRequests);
        $this->assertSame($move['new_url'], app(ToSourceSnapshot::class)->read($row)['snapshot']['canonical_url']);
    }
}
