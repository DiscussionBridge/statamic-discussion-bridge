<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Support;

use RuntimeException;

class Configuration
{
    public function enabled(): bool
    {
        return config('discussionbridge.enabled') === true;
    }

    public function forumOrigin(): string
    {
        return $this->origin('discussionbridge.forum_url');
    }

    public function siteOrigin(): string
    {
        return $this->origin('discussionbridge.site_origin');
    }

    public function connectionId(): string
    {
        $value = config('discussionbridge.connection_id');
        if (! is_string($value) || ! preg_match('/\Adbc_[a-f0-9]{24}\z/', $value)) {
            throw new RuntimeException('DiscussionBridge connection ID is invalid.');
        }

        return $value;
    }

    public function secret(): string
    {
        $path = config('discussionbridge.secret_file');
        $absolute = is_string($path) && (str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1);
        if (! $absolute) {
            throw new RuntimeException('DiscussionBridge secret file path is invalid.');
        }

        $secret = @file_get_contents($path);
        if (! is_string($secret) || ($secret = trim($secret)) === '' || strlen($secret) > 4096) {
            throw new RuntimeException('DiscussionBridge secret file is unreadable or invalid.');
        }

        return $secret;
    }

    public function lane(): ?string
    {
        $lane = config('discussionbridge.lane');
        if ($lane === null || $lane === '') {
            return null;
        }
        if (! is_string($lane) || strlen($lane) > 64) {
            throw new RuntimeException('DiscussionBridge lane is invalid.');
        }

        return $lane;
    }

    public function collectionAllowed(string $handle): bool
    {
        return in_array($handle, config('discussionbridge.collections', []), true);
    }

    /** @return array{source_authors: list<array{id: string, name: string, profile_url?: string}>, primary_source_author_id: string} */
    public function sourceAuthor(): array
    {
        $name = config('discussionbridge.source_author_name');
        if (! is_string($name) || ($name = trim(strip_tags($name))) === '' || strlen($name) > 200) {
            throw new RuntimeException('DiscussionBridge source author name is invalid.');
        }
        $id = 'statamic-profile:'.hash('sha256', $this->siteOrigin());
        $author = ['id' => $id, 'name' => $name];
        $profile = config('discussionbridge.source_author_profile_url');
        if (is_string($profile) && $profile !== '') {
            $author['profile_url'] = $this->sameSiteUrl($profile);
        }

        return ['source_authors' => [$author], 'primary_source_author_id' => $id];
    }

    private function origin(string $key): string
    {
        $value = config($key);
        if (! is_string($value) || strlen($value) > 2048) {
            throw new RuntimeException("{$key} is invalid.");
        }

        $parts = parse_url($value);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            throw new RuntimeException("{$key} must be an HTTPS origin.");
        }
        if (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/') {
            throw new RuntimeException("{$key} must not include a path.");
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return 'https://'.strtolower($parts['host']).$port;
    }

    private function sameSiteUrl(string $value): string
    {
        if (strlen($value) > 2048) {
            throw new RuntimeException('DiscussionBridge source author profile URL is invalid.');
        }
        $parts = parse_url($value);
        $origin = parse_url($this->siteOrigin());
        if (! is_array($parts) || ! is_array($origin)
            || ($parts['scheme'] ?? null) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($origin['host'] ?? ''))
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            throw new RuntimeException('DiscussionBridge source author profile URL is invalid.');
        }

        return $value;
    }
}
