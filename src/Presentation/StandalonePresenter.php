<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Presentation;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use RuntimeException;

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
            if (! is_int($number) || $number < 2 || ! is_string($username) || trim($username) === '' || strlen($username) > 100 || ! is_string($cooked)) {
                throw new RuntimeException('Discourse topic response is invalid.');
            }
            $body = $this->sanitizer->sanitize($cooked);
            if ($body === '') {
                continue;
            }
            $name = is_string($post['name'] ?? null) && trim($post['name']) !== '' ? trim($post['name']) : trim($username);
            $byline = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $postUrl = $topicUrl.'/'.$number;
            $replies .= '<article class="discussionbridge-simple__reply">'
                .'<p class="discussionbridge-simple__byline"><strong>'.$byline.'</strong> · <a href="'.htmlspecialchars($postUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" rel="nofollow noopener noreferrer">reply '.$number.'</a></p>'
                .'<div class="discussionbridge-simple__body">'.$body.'</div>'
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
}
