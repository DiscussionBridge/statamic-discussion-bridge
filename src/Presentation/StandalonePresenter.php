<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Presentation;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

class StandalonePresenter
{
    private const INITIAL_REPLIES = 5;

    private const MAX_REPLIES = 50;

    public function __construct(
        private readonly BridgeClient $client,
        private readonly Configuration $configuration,
        private readonly HtmlSanitizer $sanitizer,
        private readonly PresentationChrome $chrome,
    ) {
    }

    public function simple(int $topicId): string
    {
        $topic = $this->client->publicTopic($topicId);
        $postStream = $topic['post_stream'] ?? null;
        $posts = is_array($postStream) ? ($postStream['posts'] ?? null) : null;
        $stream = is_array($postStream) ? ($postStream['stream'] ?? null) : null;
        if (! is_array($posts) || ! is_array($stream)) {
            throw new RuntimeException('Discourse topic response is invalid.');
        }
        $targetIds = [];
        foreach (array_slice($stream, 1, self::MAX_REPLIES) as $postId) {
            if (! is_int($postId) || $postId < 1) {
                throw new RuntimeException('Discourse topic response is invalid.');
            }
            $targetIds[] = $postId;
        }

        $postsById = [];
        foreach ($posts as $post) {
            if (is_array($post) && is_int($post['id'] ?? null) && ($post['id'] ?? 0) > 0) {
                $postsById[$post['id']] = $post;
            }
        }
        $missingIds = array_values(array_diff($targetIds, array_keys($postsById)));
        foreach (array_chunk($missingIds, 20) as $batch) {
            $additional = $this->client->publicTopicPosts($topicId, $batch);
            $additionalPosts = $additional['post_stream']['posts'] ?? null;
            if (! is_array($additionalPosts)) {
                throw new RuntimeException('Discourse topic response is invalid.');
            }
            foreach ($additionalPosts as $post) {
                if (! is_array($post) || ! is_int($post['id'] ?? null) || ($post['id'] ?? 0) < 1) {
                    throw new RuntimeException('Discourse topic response is invalid.');
                }
                $postsById[$post['id']] = $post;
            }
        }

        $slug = is_string($topic['slug'] ?? null) && preg_match('/\A[a-z0-9-]+\z/', $topic['slug'])
            ? $topic['slug']
            : 'topic';
        $topicUrl = $this->configuration->forumOrigin().'/t/'.$slug.'/'.$topicId;
        $renderedReplies = [];
        foreach ($targetIds as $postId) {
            $post = $postsById[$postId] ?? null;
            if (! is_array($post)) {
                throw new RuntimeException('Discourse topic response is invalid.');
            }
            $number = $post['post_number'] ?? null;
            $username = $post['username'] ?? null;
            $cooked = $post['cooked'] ?? null;
            $createdAt = $post['created_at'] ?? null;
            if (! is_int($number) || $number < 2 || ! is_string($username) || trim($username) === '' || strlen($username) > 100 || ! is_string($cooked) || ! is_string($createdAt)) {
                throw new RuntimeException('Discourse topic response is invalid.');
            }
            try {
                $created = new DateTimeImmutable($createdAt);
            } catch (Throwable) {
                throw new RuntimeException('Discourse topic response is invalid.');
            }
            $body = $this->sanitizer->sanitize($cooked);
            if ($body === '') {
                continue;
            }
            $name = is_string($post['name'] ?? null) && trim($post['name']) !== '' ? trim($post['name']) : trim($username);
            $byline = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $postUrl = $topicUrl.'/'.$number;
            $postUrlHtml = htmlspecialchars($postUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $avatar = $this->avatar($post, $username);
            $renderedReplies[] = '<article class="discussionbridge-simple__reply">'
                .$avatar
                .'<div class="discussionbridge-simple__content">'
                .'<header class="discussionbridge-simple__meta"><strong>'.$byline.'</strong><a href="'.$postUrlHtml.'" rel="nofollow noopener noreferrer"><time datetime="'.htmlspecialchars($created->format(DATE_ATOM), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$created->format('M j, Y').'</time></a></header>'
                .'<div class="discussionbridge-simple__body">'.$body.'</div></div>'
                .'</article>';
        }

        if ($renderedReplies === []) {
            $replies = '<p class="discussionbridge-simple__empty">No comments yet.</p>';
        } else {
            $replies = implode('', array_slice($renderedReplies, 0, self::INITIAL_REPLIES));
            $remaining = array_slice($renderedReplies, self::INITIAL_REPLIES);
            if ($remaining !== []) {
                $count = count($remaining);
                $replies .= '<details class="discussionbridge-simple__more"><summary><span class="discussionbridge-simple__more-closed">Show '.$count.' more '.($count === 1 ? 'comment' : 'comments').'</span><span class="discussionbridge-simple__more-open">Show fewer comments</span></summary>'.implode('', $remaining).'</details>';
            }
            if (count($stream) - 1 > self::MAX_REPLIES) {
                $replies .= '<p class="discussionbridge-simple__limit">Showing the first '.self::MAX_REPLIES.' replies. <a href="'.htmlspecialchars($topicUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" rel="nofollow noopener noreferrer">View the complete discussion on The Bridge</a>.</p>';
            }
        }

        $forumOrigin = $this->configuration->forumOrigin();
        try {
            $poweredBy = $this->client->publicPoweredByDiscourse();
        } catch (Throwable) {
            $poweredBy = false;
        }

        return $this->chrome->styles()
            .'<section class="discussionbridge-simple">'
            .'<div data-discussionbridge-simple-live data-discussionbridge-simple-state="snapshot" data-discourse-origin="'.htmlspecialchars($forumOrigin, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" data-topic-id="'.$topicId.'" data-topic-url="'.htmlspecialchars($topicUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'
            .'<div class="discussionbridge-simple__header"><h2>Comments</h2><a href="'.htmlspecialchars($topicUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" rel="nofollow noopener noreferrer">Open discussion</a></div>'
            .$replies
            .'<p data-discussionbridge-simple-status hidden>Showing the saved comment snapshot. Open the discussion for current replies.</p>'
            .$this->chrome->discourseCredit($poweredBy)
            .'</div>'.$this->chrome->credit().'</section>'
            .'<script>'.$this->simpleLoader().'</script>';
    }

    public function full(string $canonicalUrl): string
    {
        return $this->chrome->standardEmbed(
            $this->configuration->canonicalPageUrl($canonicalUrl),
            $this->configuration->forumOrigin(),
        );
    }

    public function fullTopic(int $topicId): string
    {
        if ($topicId <= 0) {
            throw new RuntimeException('The Discourse topic identity is invalid.');
        }

        return $this->chrome->standardTopicEmbed($topicId, $this->configuration->forumOrigin());
    }

    public function interactiveTopic(int $topicId): string
    {
        if ($topicId <= 0) {
            throw new RuntimeException('The Discourse topic identity is invalid.');
        }

        $forumOrigin = $this->configuration->forumOrigin();

        return $this->chrome->discussion($topicId, $forumOrigin.'/t/'.$topicId, $forumOrigin, false);
    }

    /** @param array<string, mixed> $post */
    private function avatar(array $post, string $username): string
    {
        $template = $post['avatar_template'] ?? null;
        if (is_string($template)
            && str_starts_with($template, '/')
            && ! str_starts_with($template, '//')
            && ! preg_match('/[\x00-\x1F\x7F]/', $template)
            && strlen($template) <= 500) {
            $url = $this->configuration->forumOrigin().str_replace('{size}', '48', $template);

            return '<span class="discussionbridge-simple__avatar" aria-hidden="true"><img src="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" alt="" width="48" height="48" loading="lazy"></span>';
        }

        $initial = strtoupper(substr(trim($username), 0, 1));

        return '<span class="discussionbridge-simple__avatar discussionbridge-simple__avatar--fallback" aria-hidden="true">'.htmlspecialchars($initial, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';
    }

    private function simpleLoader(): string
    {
        $path = dirname(__DIR__, 2).'/resources/dist/discussionbridge-simple.js';
        $script = file_get_contents($path);
        if (! is_string($script) || $script === '' || str_contains(strtolower($script), '</script')) {
            throw new RuntimeException('DiscussionBridge Simple browser asset is unavailable.');
        }

        return $script;
    }
}
