<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests\Fixtures;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourcePreparation;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;

/** Isolated test corpus only; no production HTML predicate or configuration. */
class KnownSafeToSourcePreparation extends ToSourcePreparation
{
    public function __construct(Configuration $configuration, private readonly array $knownSources)
    {
        parent::__construct($configuration);
    }

    protected function qualify($entry, array $publication): void
    {
        if (! in_array($entry->get('content'), $this->knownSources, true)) {
            throw new CmsException('native_source_html_unqualified');
        }
    }
}
