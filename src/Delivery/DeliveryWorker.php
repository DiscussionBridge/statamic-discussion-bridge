<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Delivery;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeRequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Statamic\Facades\Entry;
use Throwable;

class DeliveryWorker
{
    public function __construct(
        private readonly BridgeClient $client,
        private readonly Configuration $configuration,
    ) {
    }

    public function work(int $limit): array
    {
        $limit = max(1, min($limit, 100));
        $this->recoverStaleClaims();
        $ids = DB::table('discussionbridge_deliveries')
            ->whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', 10)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $result = ['processed' => 0, 'delivered' => 0, 'failed' => 0, 'reconciliation_required' => 0, 'cancelled' => 0];
        foreach ($ids as $id) {
            $status = $this->process((int) $id);
            if ($status !== null) {
                $result['processed']++;
                $result[$status]++;
            }
        }

        return $result;
    }

    private function process(int $id): ?string
    {
        $token = (string) Str::uuid();
        $claimed = DB::table('discussionbridge_deliveries')
            ->where('id', $id)
            ->whereIn('status', ['pending', 'failed'])
            ->update(['status' => 'processing', 'lock_token' => $token, 'locked_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return null;
        }

        $row = DB::table('discussionbridge_deliveries')->where('id', $id)->where('lock_token', $token)->first();
        try {
            $entry = $row ? Entry::find($row->entry_id) : null;
            if (! $entry || $entry->published() !== true || $entry->status() !== 'published' || $entry->get('discussionbridge_publish') !== true) {
                return $this->finish($id, $token, 'cancelled', ['last_error' => 'entry_not_eligible']);
            }

            $title = $entry->get('title');
            $contentHtml = PublishedContent::fromEntry($entry);
            $canonicalUrl = $entry->absoluteUrl();
            if (! is_string($title) || trim($title) === '' || strlen($title) > 1024) {
                throw new RuntimeException('Statamic entry title is invalid.');
            }
            if (! is_string($canonicalUrl) || strlen($canonicalUrl) > 2048 || ! str_starts_with($canonicalUrl, $this->configuration->siteOrigin().'/')) {
                throw new RuntimeException('Statamic canonical URL is invalid.');
            }

            $payload = [
                'direction' => 'to_discourse',
                'external_id' => $row->external_id,
                'canonical_url' => $canonicalUrl,
                'title' => $title,
                'content_html' => $contentHtml,
                'published' => true,
                'visibility' => 'unlisted',
                'adapter_id' => (string) config('discussionbridge.adapter_id'),
                'adapter_version' => (string) config('discussionbridge.adapter_version'),
                'correlation_id' => $row->correlation_id,
                ...$this->configuration->sourceAuthor(),
                ...($this->configuration->lane() ? ['lane' => $this->configuration->lane()] : []),
            ];

            $response = $this->client->resolve($payload);
            $this->validateResolveResponse($response);
            if ($row->resource_id !== null && ($response['resource_id'] !== $row->resource_id
                || $response['topic_id'] !== (int) $row->topic_id
                || $response['topic_url'] !== $row->topic_url)) {
                return $this->finish($id, $token, 'reconciliation_required', [
                    'attempts' => ((int) $row->attempts) + 1,
                    'last_error' => 'returned_identity_mismatch',
                    'next_attempt_at' => null,
                ]);
            }

            return $this->finish($id, $token, 'delivered', [
                'canonical_url' => $canonicalUrl,
                'resource_id' => strtolower($response['resource_id']),
                'topic_id' => $response['topic_id'],
                'topic_url' => $response['topic_url'],
                'outcome' => $response['outcome'],
                'attempts' => ((int) $row->attempts) + 1,
                'last_error' => null,
                'next_attempt_at' => null,
            ]);
        } catch (BridgeRequestException $error) {
            if ($error->status === 409) {
                return $this->finish($id, $token, 'reconciliation_required', [
                    'attempts' => ((int) ($row->attempts ?? 0)) + 1,
                    'last_error' => $error->reason,
                    'next_attempt_at' => null,
                ]);
            }

            return $this->fail($id, $token, $error->reason);
        } catch (Throwable $error) {
            if (app()->environment('testing')) {
                throw $error;
            }
            report($error);

            return $this->fail($id, $token, 'delivery_failed');
        }
    }

    private function validateResolveResponse(array $response): void
    {
        if (! in_array($response['outcome'] ?? null, ['created', 'resolved'], true)
            || ($response['direction'] ?? null) !== 'to_discourse'
            || ($response['core_fallback'] ?? null) !== false
            || ! is_string($response['resource_id'] ?? null)
            || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $response['resource_id'])
            || ! is_int($response['topic_id'] ?? null)
            || $response['topic_id'] < 1
            || ! is_string($response['topic_url'] ?? null)
            || ! str_starts_with($response['topic_url'], $this->configuration->forumOrigin().'/')) {
            throw new RuntimeException('DiscussionBridge resolve response is invalid.');
        }
    }

    private function fail(int $id, string $token, string $reason): string
    {
        $row = DB::table('discussionbridge_deliveries')->where('id', $id)->first();
        $attempts = min(10, ((int) ($row->attempts ?? 0)) + 1);
        $seconds = min(3600, 60 * (2 ** min($attempts - 1, 6)));

        return $this->finish($id, $token, 'failed', [
            'attempts' => $attempts,
            'last_error' => substr($reason, 0, 500),
            'next_attempt_at' => now()->addSeconds($seconds),
        ]);
    }

    private function finish(int $id, string $token, string $status, array $values): string
    {
        DB::table('discussionbridge_deliveries')
            ->where('id', $id)
            ->where('lock_token', $token)
            ->update([...$values, 'status' => $status, 'lock_token' => null, 'locked_at' => null, 'updated_at' => now()]);

        return $status;
    }

    private function recoverStaleClaims(): void
    {
        DB::table('discussionbridge_deliveries')
            ->where('status', 'processing')
            ->where('locked_at', '<', now()->subMinutes(10))
            ->update(['status' => 'failed', 'lock_token' => null, 'locked_at' => null, 'last_error' => 'stale_worker_claim', 'next_attempt_at' => now(), 'updated_at' => now()]);
    }
}
