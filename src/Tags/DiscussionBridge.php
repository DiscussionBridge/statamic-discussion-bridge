<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tags;

use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\RecordPresenter;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\DeliveryPresenter;
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
        $entryId = $this->params->get('entry') ?? $this->context->get('id');
        if (is_object($entryId) && method_exists($entryId, '__toString')) {
            $entryId = (string) $entryId;
        }
        if (! is_string($entryId) || trim($entryId) === '') {
            return '';
        }

        try {
            return app(DeliveryPresenter::class)->render(trim($entryId));
        } catch (Throwable $error) {
            report($error);

            return '<p class="discussionbridge-unavailable">Discussion unavailable.</p>';
        }
    }
}
