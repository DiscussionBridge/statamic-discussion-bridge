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

        return DB::table('discussionbridge_deliveries')->insertOrIgnore([
            'entry_id' => (string) $entry->id(),
            'collection_handle' => $collection,
            'site_handle' => $site,
            'external_id' => EntryIdentity::externalId($this->configuration->siteOrigin(), $site, $collection, (string) $entry->id()),
            'canonical_url' => $canonicalUrl,
            'status' => 'pending',
            'correlation_id' => (string) Str::uuid(),
            'attempts' => 0,
            'next_attempt_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }
}
