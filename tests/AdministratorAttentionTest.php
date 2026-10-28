<?php
namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use Closure;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\{CmsClient,CmsException,DynamicWorker,NativePolicy,NativeWriter,OwnedFragments,PrivateState,PublicVerifier,StaticCoordinator};
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\{ConnectionException,Factory};
use Illuminate\Support\Facades\{Facade,Http};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Actual run/claim/fail receipt path, no native writer/source/DB; typed HTTP is fake. */
class AdministratorAttentionTest extends TestCase
{
    public static function outcomes(): array { return [['retry_wait', false, 0, 1], ['attention', false, 1, 1], ['attention', true, 1, 0], ['attention', true, 1, 0, 1], ['attention', false, 1, 1, 0, 'receipt'], ['attention', false, 0, 1, 0, 'missing']]; }

    #[DataProvider('outcomes')]
    public function test_summary_distinguishes_actual_receiver_attention_and_failed_attempts(string $outcome, bool $already, int $attention, int $failures, int $generationDelta = 0, string $staticAbort = 'none'): void
    {
        $container = new Container; Container::setInstance($container); Facade::setFacadeApplication($container);
        $container->instance('config', new Repository(['discussionbridge' => ['enabled' => true, 'site_origin' => 'https://publisher.example',
            'forum_url' => 'https://forum.example', 'connection_id' => 'dbc_111111111111111111111111', 'cms_profile' => 'statamic_flat']]));
        $container->instance(\Statamic\Contracts\Entries\EntryRepository::class, new stdClass);
        $configuration = new class extends Configuration { public function secret(): string { return str_repeat('a', 43); } };
        $client = new CmsClient($configuration); $codec = $client->codec();
        $fixtures = [];
        foreach (json_decode(ltrim(file_get_contents(__DIR__.'/fixtures/retry-author-fixtures.json'), "\xef\xbb\xbf"))->fixtures as $item) $fixtures[$item->id] = $item->value;
        $connection = $fixtures['connection']; $connection->profile = 'statamic_flat'; $connection->destination_origin = $configuration->siteOrigin();
        $policy = clone $connection->policy; $policy->enabled = false; // No source feed/native work in this reduced receipt test.
        $connection->policy = $policy;
        if ($staticAbort !== 'none') {
            config(['discussionbridge.cms_profile' => 'statamic_ssg']); $connection->profile = 'statamic_ssg';
            $policy->publication_kind = 'static'; $connection->native_capabilities->publication_kind = 'static';
        }
        $lease = $fixtures['lease']; $mapping = $fixtures['mapping'];
        $lease->server_time = gmdate('Y-m-d\TH:i:s').'.000Z'; $lease->expires_at = gmdate('Y-m-d\TH:i:s', time() + 300).'.000Z';
        $lease->work->attempt = $outcome === 'attention' ? 3 : 1;
        $mapping->mapping_id = $lease->work->mapping_id; $mapping->source_id = $lease->work->source_id;
        $status = $fixtures['work_status']; $status->mapping = $mapping; $status->work = clone $lease->work;
        $status->work->state = $already ? 'attention' : 'leased'; $status->lease_expires_at = $already ? null : $lease->expires_at;
        $status->work->lease_generation += $generationDelta;
        $failure = clone $status; $failure->work = clone $lease->work; $failure->work->state = $outcome;
        $failure->work->last_failure_code = 'transport_timeout'; $failure->work->retry_at = $outcome === 'retry_wait' ? gmdate('Y-m-d\TH:i:s', time() + 30).'.000Z' : null;
        $failure->lease_expires_at = null;
        $store = new AttentionMemoryState(['binding' => null, 'events' => [], 'mutations' => [], 'claim_poll' => 0,
            'mappings' => [$mapping->mapping_id => ['mapping' => $codec->encodeJson($mapping)]]]);
        if ($staticAbort !== 'none') {
            $state = $store->read(); $transaction = '11111111-1111-4111-8111-000000000077';
            $state['mappings'][$mapping->mapping_id]['static_transaction'] = $transaction;
            if ($staticAbort === 'receipt') $state['mutations']['static-abort:'.$transaction.':'.$lease->lease_generation] = [
                'route' => 'V4-20', 'path' => ['work_id' => $lease->work->work_id], 'result' => $codec->encodeJson($failure), 'http_status' => 200];
            $store->save($state);
        }
        $http = new Factory; $http->preventStrayRequests(); Http::swap($http); $statusCalls = 0;
        $http->fake(function ($request) use ($http, $codec, $connection, $policy, $mapping, $lease, $status, $failure, &$statusCalls) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $route = fn ($id) => str_replace(['{mapping_id}', '{work_id}'], [$mapping->mapping_id, $lease->work->work_id], $codec->route($id)['path']);
            if ($path === $route('V4-01')) $value = $connection;
            elseif ($path === $route('V4-08')) $value = $policy;
            elseif ($path === $route('V4-11')) $value = $mapping;
            elseif ($path === $route('V4-13')) $value = $lease;
            elseif ($path === $route('V4-14')) { if (++$statusCalls > 1) throw new ConnectionException('Synthetic status read failure'); $value = $status; }
            elseif ($path === $route('V4-17')) $value = $failure;
            else throw new \RuntimeException('Unexpected fake route '.$path);
            return $http->response(json_encode($value), 200, ['Content-Type' => 'application/json']);
        });
        $policyMapper = new class extends NativePolicy { public function __construct() {} public function authorize(stdClass $connection, stdClass $policy): void {} };
        $writer = new class extends NativeWriter { public function __construct() {} public function reconcile(array &$state, string $mappingId): void {} };
        $static = new class($staticAbort !== 'none') extends StaticCoordinator {
            public function __construct(private bool $fixtureAborted) {}
            public function aborted(array $state, string $mappingId): bool { return $this->fixtureAborted; }
        };
        try {
            $worker = new DynamicWorker($client, $store, $policyMapper, $writer,
                (new \ReflectionClass(OwnedFragments::class))->newInstanceWithoutConstructor(),
                (new \ReflectionClass(PublicVerifier::class))->newInstanceWithoutConstructor(),
                $static);
            $summary = $worker->run(1);
            $this->assertSame($attention, $summary['attention']);
            $this->assertSame($failures, $summary['attempt_failures'] ?? 0);
            $this->assertSame($outcome === 'retry_wait' ? 1 : 0, $summary['retry_wait'] ?? 0);
            if ($staticAbort !== 'none') $http->assertNotSent(fn ($request) => str_contains($request->url(), '/fail'));
        } finally { Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Container::setInstance(null); }
    }
}
class AttentionMemoryState extends PrivateState
{
    public function __construct(private array $fixture) {}
    public function locked(Closure $operation): mixed { return $operation(); }
    public function read(): array { return $this->fixture; }
    public function save(array $state): void { $this->fixture = $state; }
}
