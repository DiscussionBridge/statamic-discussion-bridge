<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Presentation;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

class StandalonePresenter
{
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
        $posts = $topic['post_stream']['posts'] ?? null;
        if (! is_array($posts) || count($posts) > 51) {
            throw new RuntimeException('Discourse topic response is invalid.');
        }

        $slug = is_string($topic['slug'] ?? null) && preg_match('/\A[a-z0-9-]+\z/', $topic['slug'])
            ? $topic['slug']
            : 'topic';
        $topicUrl = $this->configuration->forumOrigin().'/t/'.$slug.'/'.$topicId;
        $replies = '';
        foreach ($posts as $post) {
            if (! is_array($post) || ($post['post_number'] ?? null) === 1) {
                continue;
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
            $replies .= '<article class="discussionbridge-simple__reply">'
                .$avatar
                .'<div class="discussionbridge-simple__content">'
                .'<header class="discussionbridge-simple__meta"><strong>'.$byline.'</strong><a href="'.$postUrlHtml.'" rel="nofollow noopener noreferrer"><time datetime="'.htmlspecialchars($created->format(DATE_ATOM), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$created->format('M j, Y').'</time></a></header>'
                .'<div class="discussionbridge-simple__body">'.$body.'</div></div>'
                .'</article>';
        }

        if ($replies === '') {
            $replies = '<p class="discussionbridge-simple__empty">No replies yet.</p>';
        }

        return $this->chrome->styles()
            .'<section class="discussionbridge-simple">'
            .'<div class="discussionbridge-simple__header"><h2>Comments</h2><a href="'.htmlspecialchars($topicUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" rel="nofollow noopener noreferrer">Open discussion</a></div>'
            .$replies.$this->chrome->credit().'</section>';
    }

    public function full(string $canonicalUrl): string
    {
        return $this->chrome->standardEmbed(
            $this->configuration->canonicalPageUrl($canonicalUrl),
            $this->configuration->forumOrigin(),
        );
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
}
