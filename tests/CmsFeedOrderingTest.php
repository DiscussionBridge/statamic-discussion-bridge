<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\PrivateState;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\SourceContinuation;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Reduced framework integration: real client/codec/continuation and private-file
 * lock/save/reload, isolated Laravel config and HTTP fake. No Statamic app,
 * native repository, publication, database, server or external network boots.
 */
final class CmsFeedOrderingTest extends TestCase
{
    private string $owned;
    private CmsClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owned = sys_get_temp_dir().DIRECTORY_SEPARATOR.'db-cms-feed-ordering-'.bin2hex(random_bytes(12));
        mkdir($this->owned.'/public', 0700, true);
        file_put_contents($this->owned.'/synthetic-secret', str_repeat('fixture-', 8));
        $app = new Application($this->owned);
        Application::setInstance($app);
        $app->instance('config', new Repository(['discussionbridge' => [
            'enabled' => true, 'forum_url' => 'https://forum.example',
            'connection_id' => 'dbc_0123456789abcdef01234567',
            'secret_file' => $this->owned.'/synthetic-secret',
            'connect_timeout_seconds' => 1, 'response_timeout_seconds' => 1,
        ]]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->instance(Factory::class, new Factory());
        Http::preventStrayRequests();
        $this->client = new CmsClient(new Configuration());
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Application::setInstance(null);
        // Delete only the unique directory created by this test, never fixtures.
        $resolved = realpath($this->owned);
        self::assertNotFalse($resolved);
        self::assertSame(realpath(sys_get_temp_dir()), dirname($resolved));
        self::assertStringStartsWith('db-cms-feed-ordering-', basename($resolved));
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved,
            \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($resolved);
        parent::tearDown();
    }

    private function store(): PrivateState { return new PrivateState($this->owned.'/state'); }

    private function event(string $sequence, int $id, string $sourceId = 'source:a'): stdClass
    {
        $fixtures = json_decode(file_get_contents(dirname(__DIR__, 2).'/adapter-contract/fixtures/positive-examples.json'), false, 512, JSON_THROW_ON_ERROR);
        $source = null;
        foreach ($fixtures->fixtures as $fixture) if ($fixture->id === 'initial_feed') $source = $fixture->value->items[0]->source;
        self::assertInstanceOf(stdClass::class, $source);
        $source->source_id = $sourceId;
        return (object) ['event_type' => 'publication', 'event_id' => sprintf('11111111-1111-4111-8111-%012d', $id),
            'event_sequence' => $sequence, 'source' => $source];
    }

    private function page(array $events, string $stream = 'changes', ?string $next = null, ?string $checkpoint = 'checkpoint_done'): stdClass
    {
        return (object) ['stream' => $stream, 'items' => $events, 'next_cursor' => $next, 'checkpoint_cursor' => $checkpoint];
    }

    private function responses(array $pages): void
    {
        Http::fake(function ($request) use (&$pages) {
            self::assertStringContainsString('/discussion-bridge/v4/sources.json?', $request->url());
            self::assertNotEmpty($pages, 'Unexpected additional feed request');
            return Http::response(json_encode(array_shift($pages), JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']);
        });
    }

    private function start(PrivateState $store, string $stream = 'changes'): array
    {
        $state = $store->read();
        $state['feed'] = ['stream' => $stream, 'cursor' => $stream === 'changes' ? 'checkpoint_before' : null];
        $store->save($state);
        return $state;
    }

    private function rejects(callable $call, string $code = 'identity_conflict'): void
    {
        try { $call(); self::fail('An inconsistent feed was accepted'); }
        catch (CmsException $error) { self::assertSame($code, $error->failureCode); }
    }

    public static function increasing(): array
    {
        return ['decimal-length' => [['9', '10']], 'adjacent-above-2p53' => [['9007199254740992', '9007199254740993']],
            'adjacent-int64-limit' => [['9223372036854775806', '9223372036854775807']]];
    }

    #[DataProvider('increasing')]
    public function test_increasing_changes_within_page_are_staged_with_checkpoint(array $sequences): void
    {
        $this->responses([$this->page([$this->event($sequences[0], 1), $this->event($sequences[1], 2)])]);
        $this->store()->locked(function ($store) use ($sequences) {
            $state = $this->start($store);
            self::assertSame(2, (new SourceContinuation($this->client, $store))->stage($state, 1));
            $durable = $store->read();
            self::assertSame($sequences[1], $durable['last_change_sequence']);
            self::assertCount(2, $durable['events']);
            self::assertSame('checkpoint_done', $durable['feed']['cursor']);
        });
    }

    #[DataProvider('increasing')]
    public function test_increasing_changes_across_page_and_store_reload(array $sequences): void
    {
        $this->responses([$this->page([$this->event($sequences[0], 1)], next: 'page_two', checkpoint: 'checkpoint_one'),
            $this->page([$this->event($sequences[1], 2)])]);
        $this->store()->locked(function ($store) {
            $state = $this->start($store);
            (new SourceContinuation($this->client, $store))->stage($state, 1);
            self::assertSame('page_two', $store->read()['feed']['cursor']);
        });
        $this->store()->locked(function ($store) use ($sequences) {
            $state = $store->read();
            (new SourceContinuation($this->client, $store))->stage($state, 1);
            self::assertSame($sequences[1], $store->read()['last_change_sequence']);
            self::assertCount(2, $store->read()['events']);
        });
    }

    public static function notIncreasing(): array
    {
        return ['equal' => ['10', '10'], 'descending' => ['10', '9'],
            'equal-above-2p53' => ['9007199254740993', '9007199254740993'],
            'descending-adjacent-above-2p53' => ['9007199254740993', '9007199254740992'],
            'descending-int64-limit' => ['9223372036854775807', '9223372036854775806']];
    }

    #[DataProvider('notIncreasing')]
    public function test_invalid_second_event_cannot_commit_a_partial_page(string $first, string $second): void
    {
        $this->responses([$this->page([$this->event($first, 1), $this->event($second, 2)])]);
        $this->store()->locked(function ($store) {
            $state = $this->start($store); $before = $store->read();
            $this->rejects(fn() => (new SourceContinuation($this->client, $store))->stage($state, 1));
            self::assertSame($before, $store->read(), 'Failed page altered durable cursor/events/sequence');
        });
    }

    #[DataProvider('notIncreasing')]
    public function test_nonincreasing_page_after_reload_preserves_previous_checkpoint(string $first, string $second): void
    {
        $this->responses([$this->page([$this->event($first, 1)], next: 'page_two', checkpoint: 'checkpoint_one'),
            $this->page([$this->event($second, 2)])]);
        $this->store()->locked(function ($store) {
            $state = $this->start($store); (new SourceContinuation($this->client, $store))->stage($state, 1);
        });
        $this->store()->locked(function ($store) {
            $state = $store->read(); $before = $state;
            $this->rejects(fn() => (new SourceContinuation($this->client, $store))->stage($state, 1));
            self::assertSame($before, $store->read());
        });
    }

    public function test_initial_source_order_can_have_descending_sequences_across_pages(): void
    {
        $this->responses([$this->page([$this->event('100', 1, 'source:a')], 'initial', 'initial_two', null),
            $this->page([$this->event('3', 2, 'source:b')], 'initial')]);
        $this->store()->locked(function ($store) {
            $state = $this->start($store, 'initial');
            self::assertSame(2, (new SourceContinuation($this->client, $store))->stage($state, 2));
            self::assertArrayNotHasKey('last_change_sequence', $store->read());
            self::assertSame(['stream' => 'changes', 'cursor' => 'checkpoint_done'], $store->read()['feed']);
        });
    }

    public function test_exact_initial_event_replay_is_deduplicated_but_advanced_changes_replay_is_rejected(): void
    {
        $event = $this->event('10', 1);
        $this->responses([$this->page([$event], 'initial'), $this->page([$event]), $this->page([$event])]);
        $this->store()->locked(function ($store) {
            $state = $this->start($store, 'initial'); $feed = new SourceContinuation($this->client, $store);
            self::assertSame(1, $feed->stage($state, 1));
            self::assertSame(0, $feed->stage($state, 1));
            $before = $store->read();
            $this->rejects(fn() => $feed->stage($state, 1));
            self::assertSame($before, $store->read());
        });
    }

    public function test_changed_replay_with_higher_sequence_keeps_saved_event_and_checkpoint(): void
    {
        $old = $this->event('10', 1); $changed = $this->event('11', 1); $changed->source->title .= ' changed';
        $this->responses([$this->page([$old]), $this->page([$changed])]);
        $this->store()->locked(function ($store) {
            $state = $this->start($store); $feed = new SourceContinuation($this->client, $store); $feed->stage($state, 1);
            $before = $store->read(); $this->rejects(fn() => $feed->stage($state, 1));
            self::assertSame($before, $store->read());
        });
    }

    public function test_failed_page_then_restart_retries_original_cursor_without_skipping_valid_events(): void
    {
        $this->responses([$this->page([$this->event('10', 1), $this->event('9', 2)]),
            $this->page([$this->event('9', 2), $this->event('10', 1)])]);
        $this->store()->locked(function ($store) {
            $state = $this->start($store); $this->rejects(fn() => (new SourceContinuation($this->client, $store))->stage($state, 1));
            self::assertSame('checkpoint_before', $store->read()['feed']['cursor']);
        });
        $this->store()->locked(function ($store) {
            $state = $store->read(); self::assertSame(2, (new SourceContinuation($this->client, $store))->stage($state, 1));
            self::assertCount(2, $store->read()['events']);
        });
        $urls = array_map(fn($pair) => $pair[0]->url(), Http::recorded()->all());
        self::assertSame($urls[0], $urls[1], 'Restart skipped the failed page cursor');
    }
}
