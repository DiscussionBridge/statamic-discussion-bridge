<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns;

use Carbon\CarbonImmutable;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CatalogProducer;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\NativePolicy;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\PrivateState;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

trait ExercisesCmsCatalogProducer
{
    protected function cmsCatalogProfile(): string
    {
        return Entry::getFacadeRoot() instanceof \Statamic\Eloquent\Entries\EntryRepository ? 'statamic_db' : 'statamic_flat';
    }

    public static function catalogCases(): array
    {
        return [['authors', 'lost-page'], ['authors', 'lost-finalize'], ['authors', 'expired-read-cursor'],
            ['authors', 'empty'], ['taxonomy', 'normal']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('catalogCases')]
    public function test_actual_native_catalog_upload_replays_exact_pages_and_verifies_complete_pinned_snapshot(string $kind, string $scenario): void
    {
        $this->metadataEntry();
        $profile = $this->cmsCatalogProfile();
        $publicationKind = $profile === 'statamic_ssg' ? 'static' : 'dynamic';
        config(['discussionbridge.cms_profile' => $profile,
            'discussionbridge.cms_state_directory' => dirname(__DIR__, 2).'/.tmp/cms-catalog-'.bin2hex(random_bytes(8))]);
        if ($kind === 'authors') {
            if ($scenario === 'empty') {
                foreach (User::all() as $user) {
                    $user->delete();
                }
            } else {
                for ($index = 0; $index < 205; $index++) {
                    User::make()->id('native-user:'.str_pad((string) $index, 4, '0', STR_PAD_LEFT))
                        ->email('catalog'.$index.'@example.test')->data(['name' => 'Native name '.$index])->save();
                }
            }
        }
        $snapshot = app(NativePolicy::class)->catalog($kind);
        $expected = array_map(fn ($item) => (array) $item, $snapshot['items']);
        $policy = ['policy_revision' => 'catalog-policy-1', 'enabled' => false, 'selection' => 'all_public', 'category_ids' => [],
            'include_topic_ids' => [], 'exclude_topic_ids' => [], 'include_unlisted' => false, 'publication_kind' => $publicationKind,
            'presentation_mode' => 'simple', 'author_ids' => [], 'taxonomy_ids' => []];
        $oldActive = ['catalog_revision' => 'previous-catalog', 'native_revision' => 'previous-native', 'total_items' => 0, 'items' => []];
        app(PrivateState::class)->locked(function ($store) use ($kind, $oldActive) {
            $state = $store->read();
            $state['catalog_active'][$kind] = $oldActive;
            $store->save($state);
        });
        $upload = null;
        $received = [];
        $accepted = [];
        $pageCounts = [];
        $lost = true;
        $cursorExpired = true;
        $repinned = false;
        $catalog = function (int $offset) use ($kind, $snapshot, $expected, &$repinned) {
            return ['kind' => $kind, 'catalog_revision' => 'catalog-native-1', 'native_revision' => $snapshot['native_revision'],
                'total_items' => count($expected), 'items' => array_slice($expected, $offset, 100),
                'next_cursor' => $offset + 100 >= count($expected) ? null : ($repinned ? 'repinned_' : 'pinned_').($offset + 100)];
        };
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($profile, $policy, $kind, $snapshot, $scenario, $expected, $catalog,
            &$upload, &$received, &$accepted, &$pageCounts, &$lost, &$cursorExpired, &$repinned, $publicationKind) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $data = $request->body() === '' ? [] : json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            $json = fn ($value, $status = 200) => Http::response($value, $status, ['Content-Type' => 'application/json']);
            if ($path === '/discussion-bridge/v4/connection.json') {
                return $json(['connection_id' => 'dbc_0123456789abcdef01234567', 'profile' => $profile,
                    'destination_origin' => 'https://statamic.example', 'scope_revision' => 'catalog-scope-1', 'forum_name' => 'Native forum',
                    'directions' => ['from_discourse'], 'policy' => $policy, 'publication_lane' => 'statamic-alpha',
                    'native_capabilities' => ['native_revision' => 'catalog-native-config-1', 'supported_presentations' => ['simple'],
                        'publication_kind' => $publicationKind, 'complete_content_supported' => true]]);
            }
            if (isset($data['mutation_id'], $accepted[$data['mutation_id']])) {
                $prior = $accepted[$data['mutation_id']];
                $this->assertSame($prior['body'], $request->body());
                return $json($prior['response'], $prior['status']);
            }
            if ($path === '/discussion-bridge/v4/catalog/uploads.json') {
                $this->assertSame($kind, $data['kind']);
                $this->assertSame($snapshot['native_revision'], $data['native_revision']);
                $this->assertSame(count($expected), $data['total_items']);
                $upload = ['upload_id' => '75bc93ce-5f76-4cb8-a2b6-3c4bb33fb270', 'kind' => $kind,
                    'native_revision' => $data['native_revision'], 'total_items' => $data['total_items'], 'received_items' => 0,
                    'next_page' => 0, 'expires_at' => CarbonImmutable::now('UTC')->addDay()->format('Y-m-d\TH:i:s.v\Z')];
                $accepted[$data['mutation_id']] = ['body' => $request->body(), 'response' => $upload, 'status' => 201];
                return $json($upload, 201);
            }
            if (str_ends_with($path, '/pages.json')) {
                $this->assertSame($upload['next_page'], $data['page']);
                $this->assertGreaterThan(0, count($data['items']));
                $this->assertLessThanOrEqual(100, count($data['items']));
                $this->assertLessThanOrEqual(65536, strlen($request->body()));
                $pageCounts[] = count($data['items']);
                $received = array_merge($received, $data['items']);
                $upload['received_items'] = count($received);
                $upload['next_page']++;
                $accepted[$data['mutation_id']] = ['body' => $request->body(), 'response' => $upload, 'status' => 200];
                if ($scenario === 'lost-page' && $lost) {
                    $lost = false;
                    throw new \Illuminate\Http\Client\ConnectionException('Lost accepted catalog page response.');
                }
                return $json($upload);
            }
            if (str_ends_with($path, '/finalize.json')) {
                $this->assertSame($expected, $received);
                $this->assertSame(count($expected), $data['expected_total_items']);
                $response = $catalog(0);
                $accepted[$data['mutation_id']] = ['body' => $request->body(), 'response' => $response, 'status' => 200];
                if ($scenario === 'lost-finalize' && $lost) {
                    $lost = false;
                    throw new \Illuminate\Http\Client\ConnectionException('Lost accepted catalog finalize response.');
                }
                return $json($response);
            }
            if ($path === '/discussion-bridge/v4/catalog.json') {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $this->assertSame($kind, $query['kind']);
                if ($scenario === 'expired-read-cursor' && $cursorExpired) {
                    $cursorExpired = false;
                    return $json(['outcome' => 'rejected', 'code' => 'cursor_expired', 'retryable' => false,
                        'retry_after_seconds' => null, 'correlation_id' => null, 'core_fallback' => false], 410);
                }
                if (! isset($query['cursor'])) {
                    $repinned = true;
                    return $json($catalog(0));
                }
                $offset = (int) substr($query['cursor'], strrpos($query['cursor'], '_') + 1);
                return $json($catalog($offset));
            }
            $this->fail('Catalog must not change Policy, create work, call a remote provider, or use another route: '.$path);
        });
        $producer = app(CatalogProducer::class);
        if (in_array($scenario, ['lost-page', 'lost-finalize'], true)) {
            try {
                $producer->refresh($kind);
                $this->fail('Lost accepted mutation response was not surfaced.');
            } catch (CmsException $error) {
                $this->assertSame('transport_timeout', $error->failureCode);
            }
            app(PrivateState::class)->locked(function ($store) use ($kind, $oldActive) {
                $this->assertSame($oldActive, $store->read()['catalog_active'][$kind]);
                $this->assertArrayHasKey($kind, $store->read()['catalog_pending']);
            });
        }
        $result = $producer->refresh($kind);
        $this->assertSame(count($expected), $result['total_items']);
        $this->assertSame($scenario === 'empty' ? [] : ($kind === 'authors' ? [100, 100, 7] : [2]), $pageCounts);
        app(PrivateState::class)->locked(function ($store) use ($kind, $expected) {
            $state = $store->read();
            $this->assertSame($expected, $state['catalog_active'][$kind]['items']);
            $this->assertArrayNotHasKey($kind, $state['catalog_pending']);
            $this->assertSame([], $state['work']);
            $this->assertSame([], $state['mappings']);
        });
    }
}
