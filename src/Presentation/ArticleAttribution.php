<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Presentation;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Transport\NativeForumClient;
use RuntimeException;
use Statamic\Contracts\Entries\Entry;
use stdClass;

class ArticleAttribution
{
    public function __construct(private readonly Configuration $configuration, private readonly NativeForumClient $forum)
    {
    }

    public function fromForum(stdClass $source, string $forumName): string
    {
        $authors = array_map(fn ($author) => (array) $author, $source->source_authors);
        if ($authors !== []) {
            try {
                $topic = $this->forum->publicTopic((int) $source->topic_id);
                $post = $topic['post_stream']['posts'][0] ?? [];
                $username = $post['username'] ?? null;
                if (($post['post_number'] ?? null) === 1 && is_string($username)
                    && $username !== '' && $username !== '.' && $username !== '..') {
                    foreach ($authors as $index => $author) {
                        if ($author['id'] === (string) ($post['user_id'] ?? '')) {
                            $authors[$index]['profile_url'] = $this->configuration->forumOrigin().'/u/'.rawurlencode($username);
                        }
                    }
                }
            } catch (RuntimeException) {
                // The R7 source name remains usable when the public forum cannot supply a profile.
            }
        }

        return '<hr><aside class="discussionbridge-publication">'.$this->byline($authors)
            .'<p>Originally published in <a href="'.e($source->topic_url).'">'.e($forumName).'</a></p></aside>';
    }

    public function header(Entry $entry): string
    {
        $title = $entry->get('title');
        if (! is_string($title) || trim($title) === '') {
            return '';
        }

        $authors = $entry->get('discussionbridge_cms_owned') !== true && $entry->get('discussionbridge_publish') === true
            ? $this->configuration->sourceAuthor($entry)['source_authors'] : [];

        return '<header class="discussionbridge-article-header"><h1>'.e($title).'</h1>'.$this->byline($authors).'</header>';
    }

    private function byline(array $authors): string
    {
        if ($authors === []) {
            return '';
        }
        $credits = array_map(static function (array $author): string {
            $name = e($author['name']);

            return isset($author['profile_url']) ? '<a href="'.e($author['profile_url']).'">'.$name.'</a>' : $name;
        }, $authors);

        return '<p class="discussionbridge-source-byline">By '.implode(', ', $credits).'</p>';
    }
}
