<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tags;

use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\RecordPresenter;
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
}
