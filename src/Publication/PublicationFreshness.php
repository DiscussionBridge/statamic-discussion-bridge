<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Publication;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use Illuminate\Support\Facades\Cache;
use Statamic\Contracts\Entries\Entry;
use Statamic\StaticCaching\Invalidator;

class PublicationFreshness
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly Invalidator $invalidator,
    ) {
    }

    public function refresh(Entry $entry, string $resourceId): void
    {
        $this->invalidator->refresh($entry);
        Cache::forget($this->recordCacheKey($resourceId));
    }

    public function recordCacheKey(string $resourceId): string
    {
        return 'discussionbridge:record:'.hash(
            'sha256',
            $this->configuration->siteOrigin().':'.strtolower($resourceId),
        );
    }
}
