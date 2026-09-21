<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Publication;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use GuzzleHttp\Client;
use RuntimeException;
use Throwable;

class StaticPublicationTransaction
{
    private const SCHEMA_VERSION = 1;
    private const MAXIMUM_JOURNAL_BYTES = 4 * 1024 * 1024;
    private const MAXIMUM_PUBLIC_BYTES = 512 * 1024;

    public function __construct(
        private readonly PublicationSynchronizer $synchronizer,
        private readonly BridgeClient $client,
        private readonly Configuration $configuration,
        private readonly Client $http,
    ) {
    }

    /** @return array{prepared:int,transaction_id:?string} */
    public function prepare(int $maximum = 20): array
    {
        if ($maximum < 1 || $maximum > 20) {
            throw new RuntimeException('DiscussionBridge SSG publication-work limit is invalid.');
        }

        return $this->withLock(function () use ($maximum): array {
            if (is_file($this->path())) {
                throw new RuntimeException('A DiscussionBridge SSG publication transaction already requires finalize or abort.');
            }
            $journal = [
                'schema_version' => self::SCHEMA_VERSION,
                'transaction_id' => bin2hex(random_bytes(16)),
                'phase' => 'preparing',
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
                'items' => [],
            ];
            $this->writeJournal($journal);

            try {
                for ($index = 0; $index < $maximum; $index++) {
                    $response = $this->client->claimPublicationWork(3600);
                    $work = $response['publication_work'] ?? null;
                    if ($work === null) {
                        break;
                    }
                    if (! is_array($work)) {
                        throw new RuntimeException('DiscussionBridge SSG publication work is invalid.');
                    }
                    $slot = count($journal['items']);
                    $beforeMutation = function (array $prepared) use (&$journal, $slot): void {
                        $journal['items'][$slot] = $prepared;
                        $this->writeJournal($journal);
                    };
                    $prepared = ($work['action'] ?? null) === 'publish'
                        ? $this->synchronizer->prepareClaimedStatic($work, $beforeMutation)
                        : $this->synchronizer->prepareClaimedStaticUnpublish($work, $beforeMutation);
                    $journal['items'][$slot] = $prepared;
                    $this->writeJournal($journal);
                    $this->client->clearPublicationLease();
                }
                if ($journal['items'] === []) {
                    $this->removeJournal();

                    return ['prepared' => 0, 'transaction_id' => null];
                }
                $journal['phase'] = 'prepared';
                $this->writeJournal($journal);

                return ['prepared' => count($journal['items']), 'transaction_id' => $journal['transaction_id']];
            } catch (Throwable $error) {
                $journal = $this->readJournal();
                if ($journal['items'] === []) {
                    try {
                        if ($this->client->publicationLeaseToken() !== null) {
                            $this->client->failPublicationWork('statamic_ssg_prepare_failed', $this->safeDetail($error));
                        }
                    } finally {
                        $this->removeJournal();
                        $this->client->clearPublicationLease();
                    }
                } elseif ($journal['items'] !== []) {
                    $this->abortJournal($journal, 'statamic_ssg_prepare_failed', $this->safeDetail($error));
                }
                throw $error;
            }
        });
    }

    /** @return array{acknowledged:int,transaction_id:string} */
    public function finalize(): array
    {
        return $this->withLock(function (): array {
            $journal = $this->readJournal();
            if (! in_array($journal['phase'], ['prepared', 'finalizing'], true)) {
                throw new RuntimeException('DiscussionBridge SSG publication transaction is not ready to finalize.');
            }
            $journal['phase'] = 'finalizing';
            $this->writeJournal($journal);
            $acknowledged = 0;

            foreach ($journal['items'] as $index => $item) {
                if (($item['phase'] ?? null) === 'acknowledged') {
                    $acknowledged++;
                    continue;
                }
                if (($item['phase'] ?? null) !== 'prepared') {
                    throw new RuntimeException('DiscussionBridge SSG publication transaction contains an incomplete item.');
                }
                $this->verifyPublicState($item);
                $this->synchronizer->acknowledgePreparedStatic($item);
                $this->client->clearPublicationLease();
                $journal['items'][$index]['phase'] = 'acknowledged';
                $journal['items'][$index]['acknowledged_at'] = gmdate('c');
                $this->writeJournal($journal);
                $acknowledged++;
            }

            $transactionId = $journal['transaction_id'];
            $this->removeJournal();

            return ['acknowledged' => $acknowledged, 'transaction_id' => $transactionId];
        });
    }

    /** @return array{aborted:int,transaction_id:string} */
    public function abort(): array
    {
        return $this->withLock(function (): array {
            $journal = $this->readJournal();
            foreach ($journal['items'] as $item) {
                if (($item['phase'] ?? null) === 'acknowledged') {
                    throw new RuntimeException('A partially acknowledged SSG transaction must be finalized; it cannot be aborted.');
                }
            }
            $transactionId = $journal['transaction_id'];
            $count = count($journal['items']);
            $this->abortJournal($journal, 'statamic_ssg_aborted', 'The protected SSG publication transaction was aborted before acknowledgement.');

            return ['aborted' => $count, 'transaction_id' => $transactionId];
        });
    }

    /** @param array<string, mixed> $journal */
    private function abortJournal(array $journal, string $errorCode, string $detail): void
    {
        $journal['phase'] = 'aborting';
        $this->writeJournal($journal);
        for ($index = count($journal['items']) - 1; $index >= 0; $index--) {
            $item = $journal['items'][$index];
            if (($item['phase'] ?? null) === 'aborted') {
                continue;
            }
            if (! $this->synchronizer->restorePreparedStatic($item)) {
                throw new RuntimeException('DiscussionBridge could not restore a prepared SSG publication.');
            }
            $this->client->resumePublicationLease((string) ($item['lease_token'] ?? ''));
            $this->client->failPublicationWork($errorCode, $detail);
            $this->client->clearPublicationLease();
            $journal['items'][$index]['phase'] = 'aborted';
            $journal['items'][$index]['aborted_at'] = gmdate('c');
            $this->writeJournal($journal);
        }
        $this->removeJournal();
    }

    /** @param array<string, mixed> $item */
    private function verifyPublicState(array $item): void
    {
        $url = $this->configuration->canonicalPageUrl((string) ($item['canonical_url'] ?? ''));
        try {
            $response = $this->http->request('GET', $url, [
                'allow_redirects' => false,
                'connect_timeout' => 5,
                'timeout' => 15,
                'http_errors' => false,
                'stream' => true,
                'headers' => ['Accept' => 'text/html'],
            ]);
        } catch (Throwable) {
            throw new RuntimeException('DiscussionBridge SSG public verification transport failed.');
        }

        if (($item['action'] ?? null) === 'unpublish') {
            if (! in_array($response->getStatusCode(), [404, 410], true)) {
                throw new RuntimeException('DiscussionBridge SSG withdrawal is still publicly reachable.');
            }

            return;
        }
        if ($response->getStatusCode() !== 200
            || ! str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/html')) {
            throw new RuntimeException('DiscussionBridge SSG publication is not publicly reachable as HTML.');
        }
        $declared = $response->getHeaderLine('Content-Length');
        if ($declared !== '' && (! ctype_digit($declared) || (int) $declared > self::MAXIMUM_PUBLIC_BYTES)) {
            throw new RuntimeException('DiscussionBridge SSG public response is too large.');
        }
        $stream = $response->getBody();
        $html = '';
        while (! $stream->eof()) {
            $html .= $stream->read(min(8192, self::MAXIMUM_PUBLIC_BYTES + 1 - strlen($html)));
            if (strlen($html) > self::MAXIMUM_PUBLIC_BYTES) {
                throw new RuntimeException('DiscussionBridge SSG public response is too large.');
            }
        }
        $resourceMarker = 'data-discussionbridge-resource-id="'.(string) ($item['resource_id'] ?? '').'"';
        $revisionMarker = 'data-discussionbridge-publication-revision="'.(string) ($item['publication_revision'] ?? '').'"';
        if (! str_contains($html, $resourceMarker) || ! str_contains($html, $revisionMarker)) {
            throw new RuntimeException('DiscussionBridge SSG public response does not match the prepared publication revision.');
        }
    }

    /** @return array<string, mixed> */
    private function readJournal(): array
    {
        $bytes = @file_get_contents($this->path());
        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAXIMUM_JOURNAL_BYTES) {
            throw new RuntimeException('DiscussionBridge SSG publication journal is missing or invalid.');
        }
        try {
            $journal = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('DiscussionBridge SSG publication journal is invalid.');
        }
        if (! is_array($journal)
            || ($journal['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ! is_string($journal['transaction_id'] ?? null)
            || ! preg_match('/\A[a-f0-9]{32}\z/', $journal['transaction_id'])
            || ! is_string($journal['phase'] ?? null)
            || ! is_array($journal['items'] ?? null)
            || count($journal['items']) > 20) {
            throw new RuntimeException('DiscussionBridge SSG publication journal is invalid.');
        }

        return $journal;
    }

    /** @param array<string, mixed> $journal */
    private function writeJournal(array &$journal): void
    {
        $journal['updated_at'] = gmdate('c');
        $bytes = json_encode($journal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($bytes) > self::MAXIMUM_JOURNAL_BYTES) {
            throw new RuntimeException('DiscussionBridge SSG publication journal exceeds its protected bound.');
        }
        $path = $this->path();
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('DiscussionBridge SSG publication journal directory cannot be created.');
        }
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'x+b');
        if (! is_resource($handle)) {
            throw new RuntimeException('DiscussionBridge SSG publication journal cannot be created.');
        }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                throw new RuntimeException('DiscussionBridge SSG publication journal cannot be persisted.');
            }
            if (function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException('DiscussionBridge SSG publication journal cannot be synchronized.');
            }
        } finally {
            fclose($handle);
        }
        @chmod($temporary, 0600);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('DiscussionBridge SSG publication journal cannot be installed atomically.');
        }
    }

    private function removeJournal(): void
    {
        if (is_file($this->path()) && ! @unlink($this->path())) {
            throw new RuntimeException('DiscussionBridge SSG publication journal cannot be removed.');
        }
    }

    private function path(): string
    {
        return $this->configuration->ssgTransactionFile();
    }

    private function safeDetail(Throwable $error): string
    {
        return substr((string) preg_replace('/[\x00-\x1f\x7f]/', ' ', $error->getMessage()), 0, 1000);
    }

    /** @template T
     *  @param callable(): T $callback
     *  @return T
     */
    private function withLock(callable $callback): mixed
    {
        $directory = dirname($this->path());
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('DiscussionBridge SSG publication journal directory cannot be created.');
        }
        $handle = @fopen($this->path().'.lock', 'c+b');
        if (! is_resource($handle) || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Another DiscussionBridge SSG publication transaction is active.');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
