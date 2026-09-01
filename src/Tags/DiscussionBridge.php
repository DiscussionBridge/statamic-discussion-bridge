<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tags;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\PublishedContent;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\PageNavigation;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\RecordPresenter;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\DeliveryPresenter;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\StandalonePresenter;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\PublicationPresenter;
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

    public function publication(): string
    {
        $entryId = $this->entryId();
        if ($entryId === null) {
            return '';
        }

        try {
            return app(PublicationPresenter::class)->render($entryId);
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

    public function simple(): string
    {
        $topic = $this->params->get('topic');
        if (! is_string($topic) && ! is_int($topic)) {
            return '';
        }
        $topic = filter_var($topic, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($topic)) {
            return '';
        }

        try {
            return app(StandalonePresenter::class)->simple($topic);
        } catch (Throwable $error) {
            report($error);

            return '<p class="discussionbridge-unavailable">Comments unavailable.</p>';
        }
    }

    public function full(): string
    {
        $topic = $this->params->get('topic');
        if (is_string($topic) || is_int($topic)) {
            $topic = filter_var($topic, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (is_int($topic)) {
                try {
                    return app(StandalonePresenter::class)->fullTopic($topic);
                } catch (Throwable $error) {
                    report($error);

                    return '<p class="discussionbridge-unavailable">Comments unavailable.</p>';
                }
            }
        }

        $canonical = $this->params->get('canonical');
        if (! is_string($canonical) || trim($canonical) === '') {
            return '';
        }

        try {
            return app(StandalonePresenter::class)->full($canonical);
        } catch (Throwable $error) {
            report($error);

            return '<p class="discussionbridge-unavailable">Comments unavailable.</p>';
        }
    }

    public function interactive(): string
    {
        $topic = $this->params->get('topic');
        if (! is_string($topic) && ! is_int($topic)) {
            return '';
        }
        $topic = filter_var($topic, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($topic)) {
            return '';
        }

        try {
            return app(StandalonePresenter::class)->interactiveTopic($topic);
        } catch (Throwable $error) {
            report($error);

            return '<p class="discussionbridge-unavailable">Discussion unavailable.</p>';
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
