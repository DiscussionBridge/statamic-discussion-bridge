<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns;

use Carbon\CarbonImmutable;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\DynamicWorker;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\OwnedFragments;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\PrivateState;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationFreshness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

trait ExercisesCmsDynamicLifecycle
{
    protected function cmsLifecycleKind(): string
    {
        return 'dynamic';
    }

    public static function cmsRecoveryCases(): array
    {
        return [['normal'], ['lost-sync-response'], ['lost-verify-response'], ['native-cache-interruption']];
    }

    public function test_known_media_limitation_retains_verified_native_article_and_pinned_work_without_wire_failure(): void
    {
        $this->test_cms_native_create_wiki_update_and_withdraw_preserve_identity_and_verify_actual_native_rendered_bytes('local-media-update');
    }

    public function test_known_active_function_limitation_retains_native_article_and_exact_work_without_wire_failure(): void
    {
        $this->test_cms_native_create_wiki_update_and_withdraw_preserve_identity_and_verify_actual_native_rendered_bytes('local-active-update');
    }

    public function test_known_inert_data_loss_retains_native_article_and_exact_source_without_execution_failure(): void
    {
        $this->test_cms_native_create_wiki_update_and_withdraw_preserve_identity_and_verify_actual_native_rendered_bytes('local-data-update');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cmsRecoveryCases')]
    public function test_cms_native_create_wiki_update_and_withdraw_preserve_identity_and_verify_actual_native_rendered_bytes(string $scenario): void
    {
        $kind = $this->cmsLifecycleKind();
        $profile = $kind === 'static' ? 'statamic_ssg'
            : (Entry::getFacadeRoot() instanceof \Statamic\Eloquent\Entries\EntryRepository ? 'statamic_db' : 'statamic_flat');
        config(['discussionbridge.cms_profile' => $profile,
            'discussionbridge.cms_state_directory' => dirname(__DIR__, 2).'/.tmp/cms-lifecycle-'.bin2hex(random_bytes(8))]);
        if ($kind === 'dynamic') {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14T01:00:03.123Z'));
        }
        $serverTime = $kind === 'dynamic' ? '2026-10-14T01:00:00.000Z' : CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z');
        $leaseExpiry = CarbonImmutable::parse($serverTime)->addSeconds(300)->format('Y-m-d\TH:i:s.v\Z');
        $staticPublic = $kind === 'static' ? $this->cmsStaticPublicDirectory($scenario) : null;
        $name = 'discussionbridge-cms-native-'.bin2hex(random_bytes(8));
        $template = resource_path('views/'.$name.'.antlers.html');
        if (! is_dir(dirname($template))) {
            mkdir(dirname($template), 0777, true);
        }
        $this->assertFileDoesNotExist($template);
        file_put_contents($template, '<html><body><h1>{{ title }}</h1>{{ discussionbridge:article entry="{id}" }}'
            .'<nav>Existing native navigation</nav>{{ discussionbridge:publication entry="{id}" }}</body></html>');
        view()->getFinder()->flush();
        Collection::find('discussionbridge')->template($name)->save();
        $blueprints = dirname(__DIR__, 2).'/.tmp/cms-lifecycle-blueprints-'.bin2hex(random_bytes(8));
        mkdir($blueprints, 0777, true);
        \Statamic\Facades\Blueprint::setDirectories($blueprints);
        \Statamic\Facades\Blueprint::makeFromFields(['content' => ['type' => 'markdown'],
            'native_existing_default' => ['type' => 'text', 'default' => 'Native configured initial value']])
            ->setHandle('native_lifecycle')->setNamespace('collections.discussionbridge')->save();
        $freshness = Mockery::mock(PublicationFreshness::class);
        $interruptCache = $scenario === 'native-cache-interruption';
        $generationFailure = str_starts_with($scenario, 'generation-');
        $freshness->shouldReceive('refresh')->times($generationFailure ? 1 : ($interruptCache ? 4 : ($scenario === 'restore-unavailable' ? 2 : 3)))
            ->andReturnUsing(function () use (&$interruptCache) {
            if ($interruptCache) {
                $interruptCache = false;
                throw new \RuntimeException('Synthetic interruption after native persistence before private completion.');
            }
        });
        $this->app->instance(PublicationFreshness::class, $freshness);
        if ($generationFailure) {
            view()->composer($name, function () use ($scenario, $leaseExpiry) {
                CarbonImmutable::setTestNow(CarbonImmutable::parse($leaseExpiry)
                    ->subSeconds($scenario === 'generation-lease-expired' ? -1 : 60));
            });
        }
        $phase = 1;
        $withdraw = false;
        $mapping = null;
        $work = null;
        $workHistory = [];
        $claimed = false;
        $pages = [];
        $proofs = [];
        $emitted = [];
        $uncertainAck = true;
        $acceptedStaticMutations = [];
        $localLoss = in_array($scenario, ['local-media-update', 'local-active-update', 'local-data-update'], true);
        $bodies = [1 => str_repeat('<p>Complete first native source 😀.</p>', 9000)
            .'<details open name="source-disclosure"><summary>Native disclosure</summary><p>Disclosed source meaning.</p></details>',
            2 => '<h2>Updated wiki</h2><p>Complete new source edition.</p>'];
        if ($localLoss) {
            $bodies[3] = '<h2>Faithful next native edition</h2><p>New complete article.</p>';
            $bodies[2] = '<p>New source context.</p><video controls><source src="https://media.example/clip.mp4">'
                .'<a href="https://media.example/clip.mp4">Source-supplied download fallback</a></video>';
            if ($scenario === 'local-active-update') {
                $bodies[2] = '<p>Original functional widget context.</p><button onclick="initializeSourceWidget()">Source widget</button>';
            }
            if ($scenario === 'local-data-update') {
                $bodies[2] = '<p>Original structured data context.</p><script type="application/ld+json">{"@type":"Thing","name":"Exact source data"}</script>';
            }
        }
        $policy = ['policy_revision' => 'policy-native-1', 'enabled' => true, 'selection' => 'all_public', 'category_ids' => [],
            'include_topic_ids' => [], 'exclude_topic_ids' => [], 'include_unlisted' => false, 'publication_kind' => $kind,
            'presentation_mode' => 'interactive', 'author_ids' => [], 'taxonomy_ids' => []];
        $source = function () use (&$phase, $bodies) {
            return ['source_id' => 'discourse:topic:53', 'source_revision' => 'wiki-native-'.$phase, 'topic_id' => '53',
                'topic_url' => 'https://forum.example/t/changed-wiki-slug/53', 'title' => 'Native wiki edition '.$phase,
                'content_sha256' => hash('sha256', $bodies[$phase]), 'content_bytes' => strlen($bodies[$phase]),
                'source_created_at' => null, 'source_updated_at' => $phase === 1 ? null : '2026-10-14T00:55:00.000Z',
                'source_authors' => [['id' => 'author:first', 'name' => 'First Author'], ['id' => 'author:second', 'name' => 'Second Author']]];
        };
        $lease = function () use (&$work, $leaseExpiry, $serverTime) {
            return ['work' => $work, 'lease_token' => rtrim(strtr(base64_encode(str_repeat('l', 32)), '+/', '-_'), '='),
                'lease_generation' => $work['lease_generation'], 'expires_at' => $leaseExpiry, 'server_time' => $serverTime];
        };
        $status = function () use (&$work, &$mapping, $leaseExpiry) {
            return ['work' => $work, 'mapping' => $mapping, 'lease_expires_at' => $leaseExpiry];
        };
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($profile, &$phase, &$withdraw, &$mapping, &$work, &$workHistory, &$claimed, &$pages, &$proofs,
            &$policy, $source, $lease, $status, $bodies, $name, &$emitted, &$uncertainAck, $scenario, $kind, $staticPublic,
            &$acceptedStaticMutations, $leaseExpiry) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $data = $request->body() === '' ? [] : json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            $json = fn ($value, $code = 200) => Http::response($value, $code, ['Content-Type' => 'application/json']);
            if ($kind === 'static' && str_contains($path, '/work/') && isset($data['mutation_id'], $acceptedStaticMutations[$data['mutation_id']])) {
                $accepted = $acceptedStaticMutations[$data['mutation_id']];
                $this->assertSame($accepted['body'], $request->body(), 'Static uncertain mutation replay must stay exact.');
                return $json($accepted['response']);
            }
            if ($path === '/discussion-bridge/v4/connection.json') {
                return $json(['connection_id' => 'dbc_0123456789abcdef01234567', 'profile' => $profile,
                    'destination_origin' => 'https://statamic.example', 'scope_revision' => 'scope-native-1', 'forum_name' => 'Native <forum> & peers',
                    'directions' => ['from_discourse'], 'policy' => $policy, 'publication_lane' => 'statamic-alpha',
                    'native_capabilities' => ['native_revision' => 'native-config-1', 'supported_presentations' => ['simple', 'full', 'interactive'],
                        'publication_kind' => $kind, 'complete_content_supported' => true]]);
            }
            if ($path === '/discussion-bridge/v4/policy.json') {
                return $json($policy);
            }
            if ($path === '/discussion-bridge/v4/sources.json') {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $items = $withdraw || isset($emitted[$phase]) ? [] : [[
                    'event_type' => 'publication', 'event_id' => '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb24'.dechex(11 + $phase),
                    'event_sequence' => (string) $phase, 'source' => $source()]];
                $emitted[$phase] = true;
                return $json(['stream' => $query['stream'], 'items' => $items, 'next_cursor' => null, 'checkpoint_cursor' => 'native_checkpoint_'.$phase]);
            }
            if ($path === '/discussion-bridge/v4/mappings/prepare.json') {
                $mapping ??= ['mapping_id' => '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb250', 'resource_id' => '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb251',
                    'connection_id' => 'dbc_0123456789abcdef01234567', 'source_id' => $data['source_id'], 'external_id' => $data['external_id'],
                    'mapping_revision' => 'mapping-native-1', 'destination_native_id' => null, 'canonical_url' => $data['canonical_url'],
                    'retired_url_count' => 0, 'mapping_state' => 'pending', 'presentation_mode' => 'interactive',
                    'synchronized_source_revision' => null, 'publicly_verified_source_revision' => null, 'destination_native_revision' => null,
                    'source_created_at' => null, 'source_updated_at' => null, 'synchronized_at' => null, 'publicly_verified_at' => null];
                $mapping['desired_source_revision'] = $data['source_revision'];
                if ($work !== null) {
                    $work['state'] = 'superseded';
                    $workHistory[$work['work_id']] = $work;
                }
                $work = $this->cmsWork($mapping, $phase, false);
                $claimed = false;
                return $json($mapping);
            }
            if ($path === '/discussion-bridge/v4/mappings/'.($mapping['mapping_id'] ?? '').'.json') {
                return $json($mapping);
            }
            if ($path === '/discussion-bridge/v4/work/claim.json') {
                $this->assertSame($mapping['mapping_id'], $data['mapping_id']);
                if ($claimed) {
                    return Http::response('', 204);
                }
                $claimed = true;
                $work['state'] = 'leased';
                return $json($lease());
            }
            if (str_ends_with($path, '/renew.json')) {
                if ($scenario === 'generation-renew-failed' && CarbonImmutable::now('UTC')->greaterThanOrEqualTo(
                    CarbonImmutable::parse($leaseExpiry)->subSeconds(90))) {
                    return $json(['outcome' => 'rejected', 'code' => 'lease_conflict', 'retryable' => false,
                        'retry_after_seconds' => null, 'correlation_id' => null, 'core_fallback' => false], 409);
                }
                return $json($lease());
            }
            if (preg_match('~/work/[^/]+\.json$~', $path)) {
                $requestedWork = basename($path, '.json');
                return $json($requestedWork === $work['work_id'] ? $status()
                    : ['work' => $workHistory[$requestedWork], 'mapping' => $mapping, 'lease_expires_at' => null]);
            }
            if (str_ends_with($path, '/acknowledge.json') || $kind === 'static'
                && (str_ends_with($path, '/prepare.json') || str_ends_with($path, '/finalize.json'))) {
                $stage = $data['stage'] ?? (str_ends_with($path, '/prepare.json') ? 'synchronized' : 'verified');
                $this->assertSame($kind === 'dynamic', str_ends_with($path, '/acknowledge.json'));
                if ($stage === 'synchronized') {
                    $work['state'] = 'synchronized';
                    $mapping['destination_native_id'] = $data['native_result']['native_id'];
                    $mapping['destination_native_revision'] = $data['native_result']['native_revision'];
                    $mapping['synchronized_source_revision'] = $work['source_revision'];
                    $mapping['synchronized_at'] = $kind === 'dynamic' ? '2026-10-14T01:00:02.000Z'
                        : CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z');
                } else {
                    $proofs[] = $data['verification'];
                    $work['state'] = 'verified';
                    $mapping['publicly_verified_source_revision'] = $work['source_revision'];
                    $mapping['publicly_verified_at'] = $data['verification']['checked_at'];
                    $mapping['mapping_state'] = $withdraw ? 'withdrawn' : 'active';
                }
                if ($kind === 'static') {
                    $acceptedStaticMutations[$data['mutation_id']] = ['body' => $request->body(),
                        'response' => json_decode(json_encode($status(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)];
                }
                if ($phase === 1 && $uncertainAck && (($scenario === 'lost-sync-response' && $stage === 'synchronized')
                    || ($scenario === 'lost-verify-response' && $stage === 'verified'))) {
                    $uncertainAck = false;
                    throw new \Illuminate\Http\Client\ConnectionException('Synthetic lost accepted acknowledgement response.');
                }
                return $json($status());
            }
            if (str_ends_with($path, '/fail.json')) {
                if (str_starts_with($scenario, 'generation-')) {
                    $this->assertSame($scenario === 'generation-lease-expired' ? 'lease_expired' : 'lease_conflict', $data['code']);
                    if ($scenario === 'generation-lease-expired') {
                        return $json(['outcome' => 'rejected', 'code' => 'lease_expired', 'retryable' => false,
                            'retry_after_seconds' => null, 'correlation_id' => null, 'core_fallback' => false], 409);
                    }
                    $work['state'] = 'attention';
                    $work['last_failure_code'] = $data['code'];
                    return $json($status());
                }
                $this->fail('Native lifecycle stopped with '.$data['code']);
            }
            if ($kind === 'static' && str_ends_with($path, '/abort.json')) {
                $this->assertContains($scenario, ['public-verification-restored', 'restore-unavailable']);
                $this->assertSame($scenario === 'public-verification-restored', $data['native_restored']);
                $work['state'] = 'attention';
                if (! $data['native_restored']) {
                    $work['last_failure_code'] = 'native_restore_required';
                }
                $response = json_decode(json_encode($status(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
                $acceptedStaticMutations[$data['mutation_id']] = ['body' => $request->body(), 'response' => $response];
                return $json($response);
            }
            if (str_contains($path, '/content.json')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $offset = (int) $query['offset'];
                $body = substr($bodies[$phase], $offset, 32768);
                $s = $source();
                return $json(['source_id' => $s['source_id'], 'source_revision' => $s['source_revision'], 'content_sha256' => $s['content_sha256'],
                    'content_bytes' => $s['content_bytes'], 'offset' => $offset,
                    'next_offset' => $offset + strlen($body) === $s['content_bytes'] ? null : $offset + strlen($body), 'content_base64' => base64_encode($body)]);
            }
            if ($request->url() === $mapping['canonical_url']) {
                $this->assertFalse($request->hasHeader('X-DiscussionBridge-Secret'));
                $entry = Entry::find($mapping['destination_native_id']);
                if ($kind === 'static') {
                    $file = $staticPublic.parse_url($mapping['canonical_url'], PHP_URL_PATH).'/index.html';
                    if (! is_file($file)) {
                        return Http::response('Native static missing entry', 404);
                    }
                    $html = file_get_contents($file);
                    if ($phase === 2 && in_array($scenario, ['public-verification-restored', 'restore-unavailable'], true)
                        && str_contains($html, 'Complete new source edition.')) {
                        $html = str_replace('Complete new source edition.', 'Changed deployed body despite identical markers.', $html);
                    }
                    $pages[] = $html;
                    return Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
                }
                if (! $entry->published()) {
                    return Http::response('Native missing entry', 404);
                }
                $html = view($name, ['id' => $entry->id(), 'title' => $entry->get('title')])->render();
                $pages[] = $html;
                return Http::response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }
            $this->fail('Unexpected controlled request path: '.$path);
        });
        try {
            if ($generationFailure) {
                try {
                    $summary = app(DynamicWorker::class)->run();
                    $this->assertSame('generation-renew-failed', $scenario);
                    $this->assertSame(['processed' => 1, 'verified' => 0, 'attention' => 1, 'attempt_failures' => 1], $summary);
                } catch (\CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException $error) {
                    $this->assertSame('generation-lease-expired', $scenario);
                    $this->assertSame('lease_expired', $error->failureCode);
                }
                app(PrivateState::class)->locked(function ($store) {
                    $state = $store->read();
                    $this->assertCount(1, $state['static']);
                    $transaction = reset($state['static']);
                    $this->assertSame([], $transaction['commands'], 'Expired/failed renewal must prevent any deploy command.');
                    $this->assertArrayNotHasKey('artifact', $transaction);
                    $this->assertArrayNotHasKey('last_static_artifact', $state);
                });
                $this->assertSame([], $pages);
                $this->assertSame([], $proofs);
                return;
            }
            try {
                $first = app(DynamicWorker::class)->run();
                $this->assertContains($scenario, ['normal', 'lost-deployment-receipt', 'public-verification-restored', 'restore-unavailable', 'local-media-update', 'local-active-update', 'local-data-update']);
            } catch (\Throwable $error) {
                if (in_array($scenario, ['normal', 'local-media-update', 'local-active-update', 'local-data-update'], true)) {
                    throw $error;
                }
                $this->assertInstanceOf($scenario === 'native-cache-interruption' ? \RuntimeException::class
                    : \CodeWorksLabs\DiscussionBridgeStatamic\Cms\UncertainMutation::class, $error);
                $first = app(DynamicWorker::class)->run();
            }
            $this->assertSame($scenario === 'lost-verify-response' ? ['processed' => 0, 'verified' => 0, 'attention' => 0]
                : ['processed' => 1, 'verified' => 1, 'attention' => 0], $first);
            $nativeId = $mapping['destination_native_id'];
            $url = $mapping['canonical_url'];
            $this->assertStringContainsString('First Author, Second Author', $pages[0]);
            $this->assertStringContainsString('Native &lt;forum&gt; &amp; peers', $pages[0]);
            $this->assertStringContainsString('"topicId":"53"', $pages[0]);
            $this->assertStringContainsString('<details', $pages[0]);
            $this->assertStringContainsString('name="source-disclosure"', $pages[0]);
            $this->assertMatchesRegularExpression('/<details[^>]*\bopen(?:\s|=|>)/', $pages[0]);
            $this->assertStringContainsString('<summary>Native disclosure</summary>', $pages[0]);
            $this->assertStringContainsString('Disclosed source meaning.', $pages[0]);
            $this->assertNull(Entry::find($nativeId)->get('discussionbridge_source_created_at'));
            $this->assertNull(Entry::find($nativeId)->get('discussionbridge_source_updated_at'));
            $this->assertSame('Native configured initial value', Entry::find($nativeId)->get('native_existing_default'));
            $this->assertSame(0, DB::table('discussionbridge_deliveries')->count(), 'Imported From entry must never feed back into To.');
            $phase = 2;
            if ($localLoss) {
                $priorData = Entry::find($nativeId)->data()->all();
                $priorBinding = app(PrivateState::class)->locked(function ($store) {
                    $state = $store->read();
                    return reset($state['mappings']);
                });
                $this->assertSame(['processed' => 1, 'verified' => 0, 'attention' => 0, 'local_issues' => 1], app(DynamicWorker::class)->run());
                $this->assertSame($priorData, Entry::find($nativeId)->data()->all());
                $this->assertSame('leased', $work['state'], 'No receiver attention/failure was fabricated.');
                $this->assertCount(1, $pages);
                $this->assertCount(1, $proofs);
                app(PrivateState::class)->locked(function ($store) use ($priorBinding, $source, $bodies, $nativeId, $kind) {
                    $state = $store->read();
                    $binding = reset($state['mappings']);
                    foreach (['receipt', 'result', 'last_verified', 'native_revision', 'generation', 'native_id'] as $key) {
                        $this->assertSame($priorBinding[$key], $binding[$key]);
                    }
                    $this->assertSame($nativeId, $binding['native_id']);
                    $this->assertArrayNotHasKey('pending', $binding);
                    $workId = array_key_first($state['local_content_issues']);
                    $issue = $state['local_content_issues'][$workId];
                    $this->assertNotEmpty($issue['lost_function'] ?? $issue['lost_data']);
                    $this->assertSame('wiki-native-2', $issue['source_revision']);
                    $this->assertSame(2, $issue['lease_generation']);
                    $key = hash('sha256', $source()['source_id']."\0wiki-native-2");
                    $this->assertSame($source(), json_decode($state['sources'][$key], true, 512, JSON_THROW_ON_ERROR));
                    $this->assertSame($bodies[2], file_get_contents($store->path('content-'.$key.'.html')));
                    $this->assertSame('leased', json_decode($state['work'][$workId], true)['work']['state']);
                    foreach ($state['mutations'] as $mutation) {
                        $this->assertNotSame('V4-17', $mutation['route']);
                    }
                    if ($kind === 'static') {
                        $transaction = end($state['static']);
                        $this->assertCount(1, $state['static']);
                        $this->assertSame('verified', $transaction['state']);
                        $this->assertArrayHasKey('native_result', $transaction);
                    }
                });
                $claims = Http::recorded(fn ($request) => str_ends_with($request->url(), '/work/claim.json'))->count();
                $attempt = $work['attempt'];
                $this->assertSame(['processed' => 0, 'verified' => 0, 'attention' => 0], app(DynamicWorker::class)->run());
                $this->assertSame($claims, Http::recorded(fn ($request) => str_ends_with($request->url(), '/work/claim.json'))->count());
                $this->assertSame($attempt, $work['attempt']);
                $phase = 3;
                $this->assertSame(['processed' => 1, 'verified' => 1, 'attention' => 0], app(DynamicWorker::class)->run());
                $this->assertSame($nativeId, $mapping['destination_native_id']);
                $this->assertSame('wiki-native-3', Entry::find($nativeId)->get('discussionbridge_source_revision'));
                $withdraw = true;
                $mapping['desired_source_revision'] = null;
                $work['state'] = 'superseded';
                $workHistory[$work['work_id']] = $work;
                $work = $this->cmsWork($mapping, 4, true);
                $claimed = false;
                $this->assertSame(['processed' => 1, 'verified' => 1, 'attention' => 0], app(DynamicWorker::class)->run());
                $this->assertFalse(Entry::find($nativeId)->published());
                app(PrivateState::class)->locked(function ($store) {
                    $state = $store->read();
                    $this->assertSame([], $state['local_content_issues']);
                    $this->assertCount(1, $state['local_content_issue_history']);
                });
                return;
            }
            $second = app(DynamicWorker::class)->run();
            if ($kind === 'static' && in_array($scenario, ['public-verification-restored', 'restore-unavailable'], true)) {
                $this->assertSame(['processed' => 1, 'verified' => 0, 'attention' => 1, 'attempt_failures' => 1], $second);
                app(PrivateState::class)->locked(function ($store) use ($scenario, $nativeId, $staticPublic, $url) {
                    $state = $store->read();
                    $transaction = end($state['static']);
                    $restored = $scenario === 'public-verification-restored';
                    $this->assertSame($restored, $transaction['native_restored']);
                    $this->assertSame($restored ? 'aborted' : 'restore_required', $transaction['state']);
                    $entry = Entry::find($nativeId);
                    $this->assertSame($restored ? 'wiki-native-1' : 'wiki-native-2', $entry->get('discussionbridge_source_revision'));
                    $public = file_get_contents($staticPublic.parse_url($url, PHP_URL_PATH).'/index.html');
                    $this->assertStringContainsString($restored ? 'Complete first native source' : 'Complete new source edition.', $public);
                    if ($restored) {
                        $this->assertArrayHasKey('restoration_proof', $transaction);
                        $this->assertSame('wiki-native-1', json_decode($transaction['restoration_proof'], true)['source_revision']);
                    }
                });
                return;
            }
            $this->assertSame(['processed' => 1, 'verified' => 1, 'attention' => 0], $second);
            $this->assertSame($nativeId, $mapping['destination_native_id']);
            $this->assertSame($url, $mapping['canonical_url']);
            $this->assertStringContainsString('Complete new source edition.', $pages[1]);
            $this->assertStringNotContainsString('Complete first native source', $pages[1]);
            $this->assertSame('2026-10-14T00:55:00.000Z', Entry::find($nativeId)->get('discussionbridge_source_updated_at'));
            $withdraw = true;
            $mapping['desired_source_revision'] = null;
            $work = $this->cmsWork($mapping, 3, true);
            $claimed = false;
            $third = app(DynamicWorker::class)->run();
            $this->assertSame(['processed' => 1, 'verified' => 1, 'attention' => 0], $third);
            $this->assertFalse(Entry::find($nativeId)->published());
            $this->assertSame('absent', $proofs[2]['kind']);
            $this->assertSame(404, $proofs[2]['http_status']);
            $this->assertNotSame($proofs[0]['source_content_sha256'], $proofs[0]['native_publication_sha256']);
            $this->assertSame(OwnedFragments::PROFILE, $proofs[0]['verification_profile']);
            if ($kind === 'dynamic') {
                $this->assertSame('2026-10-14T01:00:03.123Z', $proofs[1]['checked_at']);
            } else {
                $this->assertGreaterThanOrEqual($serverTime, $proofs[1]['checked_at']);
            }
            app(PrivateState::class)->locked(function ($store) use ($nativeId, $kind) {
                $state = $store->read();
                $this->assertCount(1, $state['mappings']);
                $binding = reset($state['mappings']);
                $this->assertSame($nativeId, $binding['native_id']);
                $this->assertSame(3, $binding['generation']);
                $receipt = json_decode($binding['receipt'], true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('wiki-native-2', $receipt['source_revision'], 'Withdrawal authority is null; prior owned native revision remains in the receipt.');
                $this->assertSame(3, $receipt['last_lease_generation']);
                $this->assertArrayNotHasKey('pending', $binding);
                if ($kind === 'static') {
                    $this->assertCount(3, $state['static']);
                    foreach ($state['static'] as $transaction) {
                        $this->assertSame('verified', $transaction['state']);
                        $this->assertSame('statamic:ssg:generate', $transaction['build']['command']);
                        $this->assertSame('captured', $transaction['build']['state']);
                        $this->assertGreaterThan(0, $transaction['commands']['deploy']['pid']);
                        $this->assertContains($transaction['commands']['deploy']['exit_code'], [0, 8]);
                    }
                }
            });
        } finally {
            CarbonImmutable::setTestNow();
            unlink($template);
            view()->getFinder()->flush();
        }
    }

    private function cmsWork(array $mapping, int $phase, bool $withdraw): array
    {
        return ['work_id' => '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb25'.$phase, 'mapping_id' => $mapping['mapping_id'],
            'source_id' => $mapping['source_id'], 'source_revision' => $withdraw ? null : 'wiki-native-'.$phase,
            'policy_revision' => 'policy-native-1', 'action' => $withdraw ? 'withdraw' : 'publish_revision', 'state' => 'pending',
            'attempt_cycle' => '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb260', 'attempt' => 1, 'lease_generation' => $phase,
            'retry_at' => null, 'last_failure_code' => null];
    }
}
