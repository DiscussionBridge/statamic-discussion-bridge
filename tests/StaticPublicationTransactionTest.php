<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationSynchronizer;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\StaticPublicationTransaction;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeRequestException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Mockery;
use RuntimeException;

class StaticPublicationTransactionTest extends TestCase
{
    public function test_prepare_and_finalize_acknowledge_only_after_exact_public_revision_is_visible(): void
    {
        $resourceId = '11111111-1111-4111-8111-111111111111';
        $revision = str_repeat('a', 64);
        $prepared = $this->preparedItem($resourceId, $revision);
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('claimPublicationWork')->once()->with(3600)->andReturn([
            'publication_work' => ['action' => 'publish', 'topic_id' => 53],
        ]);
        $client->shouldReceive('claimPublicationWork')->once()->with(3600)->andReturn(['publication_work' => null]);
        $client->shouldReceive('clearPublicationLease')->twice();
        $synchronizer = Mockery::mock(PublicationSynchronizer::class);
        $synchronizer->shouldReceive('prepareClaimedStatic')->once()->andReturnUsing(
            function (array $work, callable $beforeMutation) use ($prepared): array {
                $applying = $prepared;
                $applying['phase'] = 'applying';
                $beforeMutation($applying);

                return $prepared;
            },
        );
        $synchronizer->shouldReceive('acknowledgePreparedStatic')->once()->with(Mockery::on(
            fn (array $item) => $item['resource_id'] === $resourceId && $item['publication_revision'] === $revision,
        ))->andReturn(['resource_id' => $resourceId]);
        $html = '<html><span hidden data-discussionbridge-resource-id="'.$resourceId.'" data-discussionbridge-publication-revision="'.$revision.'"></span></html>';
        $transaction = $this->transaction($synchronizer, $client, [new Response(200, ['Content-Type' => 'text/html'], $html)]);

        $result = $transaction->prepare(2);
        $this->assertSame(1, $result['prepared']);
        $this->assertFileExists(config('discussionbridge.ssg_transaction_file'));

        $finalized = $transaction->finalize();
        $this->assertSame(1, $finalized['acknowledged']);
        $this->assertSame($result['transaction_id'], $finalized['transaction_id']);
        $this->assertFileDoesNotExist(config('discussionbridge.ssg_transaction_file'));
    }

    public function test_public_revision_mismatch_fails_closed_and_preserves_the_journal(): void
    {
        [$transaction, $client, $synchronizer] = $this->preparedTransaction([
            new Response(200, ['Content-Type' => 'text/html'], '<html>stale deployment</html>'),
        ]);
        $synchronizer->shouldNotReceive('acknowledgePreparedStatic');
        $transaction->prepare(1);

        try {
            $transaction->finalize();
            $this->fail('Expected public revision verification to fail.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('does not match', $error->getMessage());
        }

        $this->assertFileExists(config('discussionbridge.ssg_transaction_file'));
        $journal = json_decode(file_get_contents(config('discussionbridge.ssg_transaction_file')), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('finalizing', $journal['phase']);
        $this->assertSame('prepared', $journal['items'][0]['phase']);
    }

    public function test_unpublish_is_acknowledged_only_after_the_public_url_is_gone(): void
    {
        $resourceId = '11111111-1111-4111-8111-111111111111';
        $prepared = $this->preparedItem($resourceId, str_repeat('a', 64));
        $prepared['action'] = 'unpublish';
        $prepared['outcome'] = 'unpublished';
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('claimPublicationWork')->once()->with(3600)->andReturn([
            'publication_work' => ['action' => 'unpublish', 'topic_id' => 53, 'resource_id' => $resourceId],
        ]);
        $client->shouldReceive('clearPublicationLease')->twice();
        $synchronizer = Mockery::mock(PublicationSynchronizer::class);
        $synchronizer->shouldReceive('prepareClaimedStaticUnpublish')->once()->andReturnUsing(
            function (array $work, callable $beforeMutation) use ($prepared): array {
                $applying = $prepared;
                $applying['phase'] = 'applying';
                $beforeMutation($applying);

                return $prepared;
            },
        );
        $synchronizer->shouldReceive('acknowledgePreparedStatic')->once()->with(Mockery::on(
            fn (array $item) => $item['action'] === 'unpublish' && $item['resource_id'] === $resourceId,
        ))->andReturn(['resource_id' => $resourceId]);
        $transaction = $this->transaction($synchronizer, $client, [new Response(410, ['Content-Type' => 'text/html'])]);

        $transaction->prepare(1);
        $finalized = $transaction->finalize();

        $this->assertSame(1, $finalized['acknowledged']);
        $this->assertFileDoesNotExist(config('discussionbridge.ssg_transaction_file'));
    }

    public function test_abort_restores_prepared_state_and_reports_receiver_attention(): void
    {
        [$transaction, $client, $synchronizer, $prepared] = $this->preparedTransaction([]);
        $synchronizer->shouldReceive('restorePreparedStatic')->once()->with(Mockery::on(
            fn (array $item) => $item['resource_id'] === $prepared['resource_id'],
        ))->andReturnTrue();
        $client->shouldReceive('resumePublicationLease')->once()->with($prepared['lease_token']);
        $client->shouldReceive('failPublicationWork')->once()->with(
            'statamic_ssg_aborted',
            Mockery::on(fn (string $detail) => str_contains($detail, 'aborted')),
        );
        $client->shouldReceive('clearPublicationLease')->once();
        $result = $transaction->prepare(1);

        $aborted = $transaction->abort();

        $this->assertSame(1, $aborted['aborted']);
        $this->assertSame($result['transaction_id'], $aborted['transaction_id']);
        $this->assertFileDoesNotExist(config('discussionbridge.ssg_transaction_file'));
    }

    public function test_claim_failure_without_a_lease_removes_the_empty_journal(): void
    {
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('claimPublicationWork')->once()->with(3600)->andThrow(
            new RuntimeException('receiver unavailable'),
        );
        $client->shouldReceive('publicationLeaseToken')->once()->andReturnNull();
        $client->shouldReceive('clearPublicationLease')->once();
        $synchronizer = Mockery::mock(PublicationSynchronizer::class);
        $transaction = $this->transaction($synchronizer, $client, []);

        try {
            $transaction->prepare(1);
            $this->fail('Expected the receiver failure to propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('receiver unavailable', $error->getMessage());
        }

        $this->assertFileDoesNotExist(config('discussionbridge.ssg_transaction_file'));
    }

    public function test_unleased_rate_limit_finishes_as_an_empty_transaction(): void
    {
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('claimPublicationWork')->once()->with(3600)->andThrow(
            new BridgeRequestException(429, 'rate_limited'),
        );
        $client->shouldReceive('publicationLeaseToken')->once()->andReturnNull();
        $synchronizer = Mockery::mock(PublicationSynchronizer::class);
        $transaction = $this->transaction($synchronizer, $client, []);

        $result = $transaction->prepare(8);

        $this->assertSame(['prepared' => 0, 'transaction_id' => null], $result);
        $this->assertFileDoesNotExist(config('discussionbridge.ssg_transaction_file'));
    }

    /** @param list<Response> $responses
     *  @return array{StaticPublicationTransaction, BridgeClient&\Mockery\MockInterface, PublicationSynchronizer&\Mockery\MockInterface, array<string, mixed>}
     */
    private function preparedTransaction(array $responses): array
    {
        $prepared = $this->preparedItem('11111111-1111-4111-8111-111111111111', str_repeat('a', 64));
        $client = Mockery::mock(BridgeClient::class);
        $client->shouldReceive('claimPublicationWork')->once()->with(3600)->andReturn([
            'publication_work' => ['action' => 'publish', 'topic_id' => 53],
        ]);
        $client->shouldReceive('clearPublicationLease')->once();
        $synchronizer = Mockery::mock(PublicationSynchronizer::class);
        $synchronizer->shouldReceive('prepareClaimedStatic')->once()->andReturnUsing(
            function (array $work, callable $beforeMutation) use ($prepared): array {
                $applying = $prepared;
                $applying['phase'] = 'applying';
                $beforeMutation($applying);

                return $prepared;
            },
        );

        return [$this->transaction($synchronizer, $client, $responses), $client, $synchronizer, $prepared];
    }

    /** @param list<Response> $responses */
    private function transaction(PublicationSynchronizer $synchronizer, BridgeClient $client, array $responses): StaticPublicationTransaction
    {
        return new StaticPublicationTransaction(
            $synchronizer,
            $client,
            app(Configuration::class),
            new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
        );
    }

    /** @return array<string, mixed> */
    private function preparedItem(string $resourceId, string $revision): array
    {
        return [
            'action' => 'publish',
            'topic_id' => 53,
            'lease_token' => str_repeat('c', 64),
            'resource_id' => $resourceId,
            'external_id' => 'statamic:topic:53',
            'canonical_url' => 'https://statamic.example/forum-topic-53/',
            'source_revision' => 'post:149:version:2',
            'publication_revision' => $revision,
            'mapping_revision' => str_repeat('b', 64),
            'destination' => ['state' => 'ready'],
            'outcome' => 'created',
            'phase' => 'prepared',
            'publication' => ['resource_id' => $resourceId],
            'snapshot' => ['exists' => false],
        ];
    }
}
