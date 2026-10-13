<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Delivery;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\EntryIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeliveryEnqueuer
{
    public function __construct(private readonly Configuration $configuration)
    {
    }

    public function enqueue(object $entry): bool
    {
        $collection = (string) $entry->collectionHandle();
        if (! $this->configuration->enabled() || ! $this->configuration->collectionAllowed($collection)) {
            return false;
        }
        if ($entry->published() !== true || $entry->status() !== 'published' || $entry->get('discussionbridge_publish') !== true) {
            return false;
        }

        $canonicalUrl = $entry->absoluteUrl();
        $site = (string) $entry->site()->handle();
        if (! is_string($canonicalUrl) || strlen($canonicalUrl) > 2048 || ! str_starts_with($canonicalUrl, $this->configuration->siteOrigin().'/')) {
            return false;
        }
        $publication = SourcePublication::fromEntry($entry, $this->configuration);
        $revision = $publication['record']['source_publication']['revision'];
        return DB::transaction(function () use ($entry, $site, $collection, $canonicalUrl, $revision): bool {
            $existing = DB::table('discussionbridge_deliveries')->where('site_handle', $site)->where('collection_handle', $collection)->where('entry_id', (string) $entry->id())->lockForUpdate()->first();
            if ($existing) {
                if ($existing->canonical_url !== $canonicalUrl) {
                    DB::table('discussionbridge_deliveries')->where('id', $existing->id)->update([
                        'last_error' => 'canonical_url_changed',
                        ...($existing->status === 'processing' ? [] : ['status' => 'reconciliation_required']),
                        'updated_at' => now(),
                    ]);
                    return false;
                }
                if ($existing->desired_revision === $revision) {
                    return false;
                }
                $values = ['desired_revision' => $revision, 'updated_at' => now()];
                if ($existing->status !== 'processing' && $existing->status !== 'reconciliation_required') {
                    $values += ['status' => $existing->source_revision === $revision ? 'delivered' : 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null];
                }
                DB::table('discussionbridge_deliveries')->where('id', $existing->id)->update($values);

                return $existing->status !== 'processing' && $existing->status !== 'reconciliation_required' && $existing->source_revision !== $revision;
            }

            return DB::table('discussionbridge_deliveries')->insertOrIgnore([
                'entry_id' => (string) $entry->id(),
                'collection_handle' => $collection,
                'site_handle' => $site,
                'external_id' => EntryIdentity::externalId($this->configuration->siteOrigin(), $site, $collection, (string) $entry->id()),
                'canonical_url' => $canonicalUrl,
                'desired_revision' => $revision,
                'status' => 'pending',
                'correlation_id' => (string) Str::uuid(),
                'attempts' => 0,
                'next_attempt_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]) === 1;
        });
    }
}
