<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Presentation;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\OwnedFragments;
use Statamic\Facades\Entry;
use RuntimeException;

class PublicationPresenter
{
    public function render(string $entryId): string
    {
        $entry = Entry::find($entryId);
        if (! $entry || $entry->get('discussionbridge_cms_owned') !== true) {
            throw new RuntimeException('DiscussionBridge native publication is unavailable.');
        }
        return $entry->published() && $entry->get('discussionbridge_source_status') !== 'withdrawn'
            ? app(OwnedFragments::class)->discussion($entry) : '';
    }
}
