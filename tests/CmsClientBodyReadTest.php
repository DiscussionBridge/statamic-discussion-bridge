<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use DiscussionBridge\CmsV4\ValidationException;
use GuzzleHttp\Psr7\Stream;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** Offline transport regressions; no Statamic Application, Kernel, DB or sockets. */
class CmsClientBodyReadTest extends TestCase
{
    private Container $previousContainer;
    private mixed $previousFacade;
    private Container $container;
    private const BODY = '{"items":[],"next_cursor":null}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->container = new Container;
        $this->container->instance('config', new Repository(['discussionbridge' => [
            'connect_timeout_seconds' => 2, 'response_timeout_seconds' => 1,
        ]]));
        Container::setInstance($this->container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->container);
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function client(BodyReadScriptStream $stream, string $mediaType = 'application/json'): CmsClient
    {
        Http::fake(fn () => Http::response($stream, 200, ['Content-Type' => $mediaType]));
        $configuration = $this->createStub(Configuration::class);
        $configuration->method('enabled')->willReturn(true);
        $configuration->method('forumOrigin')->willReturn('https://forum.example');
        $configuration->method('connectionId')->willReturn('dbc_0123456789abcdef01234567');
        $configuration->method('secret')->willReturn(str_repeat('s', 40));
        return new CmsClient($configuration);
    }

    private function assertFailure(CmsClient $client, BodyReadScriptStream $stream, string $code): CmsException
    {
        try {
            $client->request('V4-03');
            $this->fail('An incomplete or oversized body was accepted.');
        } catch (CmsException $error) {
            $this->assertSame($code, $error->failureCode);
            $this->assertSame(1, $stream->closeCalls);
            Http::assertSentCount(1);
            return $error;
        }
    }

    public function test_transient_empty_reads_wait_for_positive_progress_and_real_eof(): void
    {
        $stream = new BodyReadScriptStream([
            ['bytes' => ''], ['bytes' => substr(self::BODY, 0, 12)], ['bytes' => ''],
            ['bytes' => substr(self::BODY, 12), 'eof' => true],
        ]);
        $value = $this->client($stream)->request('V4-03');
        $this->assertSame([], $value->items);
        $this->assertNull($value->next_cursor);
        $this->assertSame(4, $stream->readCalls);
        $this->assertTrue($stream->ended);
        $this->assertSame(1, $stream->closeCalls);
        Http::assertSentCount(1);
    }

    public function test_parseable_body_does_not_complete_until_real_eof(): void
    {
        $stream = new BodyReadScriptStream([
            ['bytes' => self::BODY], ['bytes' => ''], ['bytes' => ''], ['bytes' => '', 'eof' => true],
        ]);
        $this->assertSame([], $this->client($stream)->request('V4-03')->items);
        $this->assertSame(4, $stream->readCalls);
        $this->assertTrue($stream->ended);
        $this->assertSame(1, $stream->closeCalls);
    }

    public function test_positive_bytes_reset_no_progress_allowance_without_an_overall_body_deadline(): void
    {
        $stream = new BodyReadScriptStream([
            ['bytes' => ''], ['bytes' => substr(self::BODY, 0, 12), 'delay_us' => 600000],
            ['bytes' => ''], ['bytes' => substr(self::BODY, 12), 'delay_us' => 600000, 'eof' => true],
        ]);
        $began = hrtime(true);
        try {
            $value = $this->client($stream)->request('V4-03');
            $this->assertGreaterThanOrEqual(1.1, (hrtime(true) - $began) / 1e9);
            $this->assertSame([], $value->items);
            $this->assertSame(4, $stream->readCalls);
            $this->assertSame(1, $stream->closeCalls);
        } finally {
            // Observation only: emit after the request/assertions, never per read.
            fwrite(STDERR, "\nBODY_READ_TIMING ".json_encode([
                'test_begin_ns' => $began, 'test_end_ns' => hrtime(true),
                'response_timeout_seconds' => 1, 'scripted_delay_us' => 600000,
                'spans' => $stream->timingSpans, 'dropped_spans' => $stream->droppedTimingSpans,
            ], JSON_UNESCAPED_SLASHES)."\n");
        }
    }

    public function test_repeated_empty_non_eof_reads_expire_with_a_real_bound_and_backoff(): void
    {
        $stream = new BodyReadScriptStream([['bytes' => self::BODY]]);
        $client = $this->client($stream);
        $began = hrtime(true);
        $this->assertFailure($client, $stream, 'transport_timeout');
        $elapsed = (hrtime(true) - $began) / 1e9;
        $this->assertGreaterThanOrEqual(0.95, $elapsed);
        $this->assertLessThan(3.0, $elapsed);
        $this->assertGreaterThan(5, $stream->readCalls);
        $this->assertLessThan(250, $stream->readCalls, 'Immediate empty reads must yield, not spin.');
        $this->assertFalse($stream->ended);
    }

    public function test_late_blocking_progress_is_rejected_before_reset_even_when_it_returns_eof(): void
    {
        $stream = new BodyReadScriptStream([
            ['bytes' => ''], ['bytes' => self::BODY, 'delay_us' => 1100000, 'eof' => true],
        ]);
        $client = $this->client($stream);
        $began = hrtime(true);
        $this->assertFailure($client, $stream, 'transport_timeout');
        $this->assertGreaterThanOrEqual(1.05, (hrtime(true) - $began) / 1e9);
        $this->assertSame(2, $stream->readCalls);
        $this->assertTrue($stream->ended, 'A late EOF must not erase the expired no-progress interval.');
    }

    public function test_native_timeout_is_not_treated_as_transient_empty_progress(): void
    {
        $stream = new BodyReadScriptStream([['bytes' => '', 'timed_out' => true]]);
        $this->assertFailure($this->client($stream), $stream, 'transport_timeout');
        $this->assertSame(1, $stream->readCalls);
        $this->assertTrue($stream->nativeTimedOut);
    }

    public function test_late_eof_at_loop_start_cannot_accept_after_no_progress_expiry(): void
    {
        $stream = new BodyReadScriptStream([['bytes' => '']], [
            ['value' => false], ['value' => false], ['value' => true, 'delay_us' => 1100000],
        ]);
        $this->assertFailure($this->client($stream), $stream, 'transport_timeout');
        $this->assertSame(1, $stream->readCalls);
        $this->assertSame(3, $stream->eofCalls);
        $this->assertTrue($stream->ended);
    }

    public function test_late_eof_after_another_empty_read_cannot_erase_active_expiry(): void
    {
        $stream = new BodyReadScriptStream([['bytes' => ''], ['bytes' => '']], [
            ['value' => false], ['value' => false], ['value' => false],
            ['value' => true, 'delay_us' => 1100000],
        ]);
        $this->assertFailure($this->client($stream), $stream, 'transport_timeout');
        $this->assertSame(2, $stream->readCalls);
        $this->assertSame(4, $stream->eofCalls);
        $this->assertTrue($stream->ended);
    }

    public function test_native_timeout_reported_by_eof_liveness_fails_before_another_read(): void
    {
        $stream = new BodyReadScriptStream([['bytes' => self::BODY, 'eof' => true]], [
            ['value' => false, 'timed_out' => true],
        ]);
        $this->assertFailure($this->client($stream), $stream, 'transport_timeout');
        $this->assertSame(0, $stream->readCalls);
        $this->assertSame(1, $stream->eofCalls);
    }

    public function test_valid_json_prefix_followed_by_a_suffix_is_not_accepted(): void
    {
        $stream = new BodyReadScriptStream([
            ['bytes' => self::BODY], ['bytes' => ''], ['bytes' => 'x', 'eof' => true],
        ]);
        try {
            $this->client($stream)->request('V4-03');
            $this->fail('A parseable prefix hid later bytes.');
        } catch (ValidationException) {
            $this->assertSame(3, $stream->readCalls);
            $this->assertSame(1, $stream->closeCalls);
        }
    }

    public function test_truncated_json_at_real_eof_still_fails_typed_decoding(): void
    {
        $stream = new BodyReadScriptStream([['bytes' => '{"items":[],"next_cursor":', 'eof' => true]]);
        try {
            $this->client($stream)->request('V4-03');
            $this->fail('A truncated body was accepted.');
        } catch (ValidationException) {
            $this->assertTrue($stream->ended);
            $this->assertSame(1, $stream->closeCalls);
        }
    }

    public function test_metadata_sentinel_still_rejects_oversize_before_decoding(): void
    {
        $stream = new BodyReadScriptStream([['bytes' => str_repeat('x', 262145), 'eof' => true]]);
        $this->assertFailure($this->client($stream), $stream, 'metadata_unavailable');
        $this->assertSame(262145, $stream->bytesReturned);
        $this->assertSame(1, $stream->requestedLengths[array_key_last($stream->requestedLengths)]);
    }

    public function test_native_read_exception_is_rethrown_unchanged_and_stream_is_closed(): void
    {
        $native = new RuntimeException('Controlled offline stream failure.');
        $stream = new BodyReadScriptStream([['throw' => $native]]);
        try {
            $this->client($stream)->request('V4-03');
            $this->fail('Native exception disappeared.');
        } catch (RuntimeException $actual) {
            $this->assertSame($native, $actual);
            $this->assertSame(1, $stream->closeCalls);
        }
    }

    public function test_media_type_refusal_closes_without_reading(): void
    {
        $stream = new BodyReadScriptStream([['bytes' => self::BODY, 'eof' => true]]);
        $this->assertFailure($this->client($stream, 'text/html'), $stream, 'unsupported_media_type');
        $this->assertSame(0, $stream->readCalls);
    }

    public function test_nonpositive_socket_setting_does_not_invent_an_unbounded_empty_wait(): void
    {
        $this->container->make('config')->set('discussionbridge.response_timeout_seconds', 0);
        $stream = new BodyReadScriptStream([['bytes' => '']]);
        $this->assertFailure($this->client($stream), $stream, 'transport_timeout');
        $this->assertSame(1, $stream->readCalls);
    }
}

/** Scripted memory-only native-read outcomes, not a socket/native qualification claim. */
class BodyReadScriptStream extends Stream
{
    public int $readCalls = 0;
    public int $eofCalls = 0;
    public int $closeCalls = 0;
    public int $bytesReturned = 0;
    public array $requestedLengths = [];
    public bool $ended = false;
    public bool $nativeTimedOut = false;
    public array $timingSpans = [];
    public int $droppedTimingSpans = 0;

    public function __construct(private array $steps, private array $eofSteps = [])
    {
        parent::__construct(fopen('php://temp', 'w+b'));
    }

    public function read($length): string
    {
        $began = hrtime(true);
        $this->readCalls++;
        $this->requestedLengths[] = $length;
        $step = array_shift($this->steps) ?? ['bytes' => ''];
        $delay = $step['delay_us'] ?? 0;
        $sleepBegan = $sleepEnded = $returnedLength = null;
        try {
            if (isset($step['delay_us'])) {
                $sleepBegan = hrtime(true);
                usleep($step['delay_us']);
                $sleepEnded = hrtime(true);
            }
            if (isset($step['throw'])) { throw $step['throw']; }
            $this->nativeTimedOut = $step['timed_out'] ?? false;
            $bytes = $step['bytes'] ?? '';
            $piece = substr($bytes, 0, $length);
            if (strlen($bytes) > $length) {
                $step['bytes'] = substr($bytes, $length);
                unset($step['delay_us']);
                array_unshift($this->steps, $step);
            } else { $this->ended = $step['eof'] ?? false; }
            $this->bytesReturned += strlen($piece);
            $returnedLength = strlen($piece);
            return $piece;
        } finally {
            $ended = hrtime(true);
            $this->recordTiming(['operation' => 'read', 'call' => $this->readCalls,
                'begin_ns' => $began, 'end_ns' => $ended, 'elapsed_ns' => $ended - $began,
                'requested_length' => $length, 'returned_length' => $returnedLength,
                'requested_delay_us' => $delay, 'sleep_begin_ns' => $sleepBegan, 'sleep_end_ns' => $sleepEnded]);
        }
    }

    public function eof(): bool
    {
        $began = hrtime(true);
        $this->eofCalls++;
        $delay = 0;
        $sleepBegan = $sleepEnded = $returned = null;
        try {
            if ($this->eofSteps !== []) {
                $step = array_shift($this->eofSteps);
                $delay = $step['delay_us'] ?? 0;
                if (isset($step['delay_us'])) {
                    $sleepBegan = hrtime(true);
                    usleep($step['delay_us']);
                    $sleepEnded = hrtime(true);
                }
                if (array_key_exists('timed_out', $step)) { $this->nativeTimedOut = $step['timed_out']; }
                $this->ended = $step['value'];
            }
            $returned = $this->ended;
            return $returned;
        } finally {
            $ended = hrtime(true);
            $this->recordTiming(['operation' => 'eof', 'call' => $this->eofCalls,
                'begin_ns' => $began, 'end_ns' => $ended, 'elapsed_ns' => $ended - $began,
                'returned_eof' => $returned, 'requested_delay_us' => $delay,
                'sleep_begin_ns' => $sleepBegan, 'sleep_end_ns' => $sleepEnded]);
        }
    }

    private function recordTiming(array $span): void
    {
        if (count($this->timingSpans) < 64) { $this->timingSpans[] = $span; }
        else { $this->droppedTimingSpans++; }
    }
    public function close(): void { $this->closeCalls++; parent::close(); }
    public function getMetadata($key = null)
    {
        $metadata = ['timed_out' => $this->nativeTimedOut, 'eof' => $this->ended];
        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }
}
