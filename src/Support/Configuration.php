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
}
