<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\PrivateState;
use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\DeliveryWorker;
use CodeWorksLabs\DiscussionBridgeStatamic\Tests\Fixtures\SourceMoveTlsServer;
use Illuminate\Support\Facades\DB;

/** Native rendered bytes over a separate TLS process, not a separately
 * installed public native application or production streaming qualification. */
trait ExercisesCmsSourceSocket
{
    private ?SourceMoveTlsServer $sourceSocket = null;
    private bool $sourceSocketSawWriter = false;

    private function sourceMoveSocketResponse($request, string $html, int $status)
    {
        if ($this->sourceSocket === null) {
            return \Illuminate\Support\Facades\Http::response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        try {
            app(PrivateState::class)->locked(fn () => null);
            $this->fail('Public request was not inside the source writer.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Nested CMS writer lock', $error->getMessage());
            $this->sourceSocketSawWriter = true;
        }
        $this->sourceSocket->publish($request->url(), $html, $status);
        return \GuzzleHttp\Promise\Create::promiseFor($this->sourceSocket->request($request->url(), $request->headers()));
    }

    public function test_current_to_source_move_actual_independent_verified_tls_request_while_native_writer_held(): void
    {
        $entry = $this->sourceMoveEntry();
        $this->sourceSocket = new SourceMoveTlsServer;
        try {
            $entry->slug('cms-native-socket')->save();
            $this->assertSame(1, app(DeliveryWorker::class)->work(1)['delivered']);
            $this->assertTrue($this->sourceSocketSawWriter);
            $requests = $this->sourceSocket->requests();
            $this->assertCount(1, $requests);
            $this->assertSame('/discussionbridge/cms-native-socket', $requests[0]['target']);
            $this->assertSame('statamic.example', $requests[0]['host']);
            $this->assertSame([], $requests[0]['credential_names']);
            $this->assertTrue($requests[0]['remote_loopback']);
            $this->assertSame(hash('sha256', $this->sourceMoveObserved[0]['body']), $requests[0]['body_sha256']);
            $this->assertSame(200, $requests[0]['status']);
            $this->assertSame('https://statamic.example/discussionbridge/cms-native-socket', DB::table('discussionbridge_deliveries')->sole()->canonical_url);
            // The same actual server must fail native certificate/hostname
            // verification at a different canonical hostname.
            try {
                $this->sourceSocket->request('https://wrong-host.example/discussionbridge/cms-native-socket');
                $this->fail('Wrong TLS hostname was accepted.');
            } catch (\GuzzleHttp\Exception\RequestException $error) {
                $this->assertSame(60, $error->getHandlerContext()['errno']);
            }
        } finally {
            $this->sourceSocket->close();
        }
    }
}
