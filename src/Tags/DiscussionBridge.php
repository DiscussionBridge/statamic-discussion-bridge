<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tags;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\PublishedContent;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\PageNavigation;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\DeliveryPresenter;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\StandalonePresenter;
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\PublicationPresenter;
use Statamic\Facades\Entry;
use Statamic\Tags\Tags;
use Throwable;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets;
use CodeWorksLabs\DiscussionBridgeStatamic\Support\ForumName;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\NativeForumClient;
use RuntimeException;

class DiscussionBridge extends Tags
{
    protected static $handle = 'discussionbridge';

    public function record(): string
    {
        $resource = $this->params->get('resource');
        if (! is_string($resource)) {
            return '';
        }
        try {
            $entries = Entry::query()->where('discussionbridge_resource_id', $resource)->limit(2)->get();
            if ($entries->count() !== 1 || $entries->first()->get('discussionbridge_cms_owned') !== true) {
                throw new RuntimeException('Current native resource binding is unavailable or ambiguous.');
            }
            $entry = $entries->first();
            return $entry->published() && $entry->get('discussionbridge_source_status') !== 'withdrawn'
                ? app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\OwnedFragments::class)->article($entry)
                    .app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\OwnedFragments::class)->discussion($entry) : '';
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
            if (! $entry) {
                return '';
            }
            if ($entry->get('discussionbridge_cms_owned') === true) {
                return $entry->published() && $entry->get('discussionbridge_source_status') !== 'withdrawn'
                    ? app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\OwnedFragments::class)->article($entry) : '';
            }
            $sourceFrame = $entry->get('discussionbridge_publish') === true
                ? app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourceArticleProfile::class)->renderIfCurrent($entry) : null;
            if ($sourceFrame !== null) {
                return $sourceFrame;
            }
            $content = PublishedContent::portableFromEntry($entry);
            return \CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourceArticleProfile::plainArticle($content);
        } catch (Throwable $error) {
            report($error);

            return '<p class="discussionbridge-unavailable">Article unavailable.</p>';
        }
    }

    public function simple(): string
    {
        if (($cms = $this->ownedDiscussion('simple')) !== null) {
            return $cms;
        }
        $topic = $this->params->get('topic');
        if (! is_string($topic) && ! is_int($topic)) {
            return '';
        }
        $topic = filter_var($topic, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($topic)) {
            return '';
        }

        try {
            return app(StandalonePresenter::class)->simple($topic, $this->nativeForumName($topic));
        } catch (Throwable $error) {
            report($error);

            return '<p class="discussionbridge-unavailable">Comments unavailable.</p>';
        }
    }

    public function full(): string
    {
        if (($cms = $this->ownedDiscussion('full')) !== null) {
            return $cms;
        }
        $topic = $this->params->get('topic');
        if (is_string($topic) || is_int($topic)) {
            $topic = filter_var($topic, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (is_int($topic)) {
                try {
                    return app(StandalonePresenter::class)->fullTopic($topic, $this->nativeForumName($topic));
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
        if (($cms = $this->ownedDiscussion('interactive')) !== null) {
            return $cms;
        }
        $topic = $this->params->get('topic');
        if (! is_string($topic) && ! is_int($topic)) {
            return '';
        }
        $topic = filter_var($topic, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($topic)) {
            return '';
        }

        try {
            return app(StandalonePresenter::class)->interactiveTopic($topic, $this->nativeForumName($topic));
        } catch (Throwable $error) {
            report($error);

            return '<p class="discussionbridge-unavailable">Discussion unavailable.</p>';
        }
    }

    private function ownedDiscussion(string $mode): ?string
    {
        $entryId = $this->entryId();
        $entry = $entryId === null ? null : Entry::find($entryId);
        if (! $entry || $entry->get('discussionbridge_cms_owned') !== true) {
            return null;
        }
        if ($entry->get('discussionbridge_mode') !== $mode
            || (string) $this->params->get('topic') !== $entry->get('discussionbridge_topic_id')) {
            return '<p class="discussionbridge-unavailable">Selected discussion integration requires attention.</p>';
        }
        return $entry->published() && $entry->get('discussionbridge_source_status') !== 'withdrawn'
            ? app(\CodeWorksLabs\DiscussionBridgeStatamic\Cms\OwnedFragments::class)->discussion($entry) : '';
    }

    private function nativeForumName(int $topicId): ?string
    {
        $entryId = $this->entryId();
        $entry = $entryId === null ? null : Entry::find($entryId);
        if (! $entry || $entry->get('discussionbridge_native_publication') !== true
            || (int) $entry->get('discussionbridge_topic_id') !== $topicId) {
            return null;
        }

        return ForumName::validate($entry->get('discussionbridge_forum_name') ?? app(NativeForumClient::class)->publicForumTitle());
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
