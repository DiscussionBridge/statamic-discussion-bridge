<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tags;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\PublishedContent;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\PageNavigation;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\RecordPresenter;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\DeliveryPresenter;
use Statamic\Facades\Entry;
use Statamic\Tags\Tags;
use Throwable;

class DiscussionBridge extends Tags
{
    protected static $handle = 'discussionbridge';

    public function record(): string
    {
        $resourceId = $this->params->get('resource');
        if (! is_string($resourceId) || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $resourceId)) {
            return '';
        }

        try {
            return app(RecordPresenter::class)->render(strtolower($resourceId));
        } catch (Throwable $error) {
            report($error);

            return '<p class="discussionbridge-unavailable">Discussion unavailable.</p>';
        }
    }

    public function discussion(): string
    {
        $entryId = $this->entryId();
        if ($entryId === null) {
            return '';
        }

        try {
            return app(DeliveryPresenter::class)->render($entryId);
        } catch (Throwable $error) {
            report($error);

            return '<p class="discussionbridge-unavailable">Discussion unavailable.</p>';
        }
    }

    public function article(): string
    {
        $entryId = $this->entryId();
        if ($entryId === null) {
            return '';
        }

        try {
            $entry = Entry::find($entryId);

            return $entry
                ? '<article class="discussionbridge-article">'.app(PageNavigation::class)->render(PublishedContent::fromEntry($entry)).'</article>'
                : '';
        } catch (Throwable $error) {
            report($error);

            return '<p class="discussionbridge-unavailable">Article unavailable.</p>';
        }
    }

    private function entryId(): ?string
    {
        $entryId = $this->params->get('entry') ?? $this->context->get('id');
        if (is_object($entryId) && method_exists($entryId, '__toString')) {
            $entryId = (string) $entryId;
        }

        return is_string($entryId) && trim($entryId) !== '' ? trim($entryId) : null;
    }
}
