<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;

use CodeWorksLabs\DiscussionBridgeStatamic\Transport\BridgeClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Version;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class InstallDiscussionBridge extends Command
{
    protected $signature = 'discussionbridge:install
        {--forum-url= : DiscussionBridge forum HTTPS origin}
        {--site-origin= : This Statamic site HTTPS origin}
        {--connection-id= : Content Connection ID}
        {--secret-file= : Absolute protected secret-file path}
        {--lane= : Optional connection lane}
        {--collections=pages : Comma-separated Statamic collection handles}
        {--native-author-id= : Statamic user ID used as the default native publication author}
        {--source-author-name= : Source author reported to DiscussionBridge}
        {--source-author-profile-url= : Optional same-site author profile URL}';

    protected $description = 'Configure, migrate, and verify DiscussionBridge for this Statamic installation';

    public function handle(BridgeClient $client): int
    {
        try {
            $forumUrl = $this->origin($this->value('forum-url', 'DiscussionBridge forum URL'));
            $siteOrigin = $this->origin($this->value('site-origin', 'This Statamic site URL', (string) config('app.url')));
            $connectionId = $this->value('connection-id', 'Content Connection ID');
            if (preg_match('/\Adbc_[a-f0-9]{24}\z/', $connectionId) !== 1) {
                throw new RuntimeException('Content Connection ID must use the dbc_ identifier shown by The Bridge.');
            }

            $secret = trim((string) $this->secret('Content Connection secret'));
            if (strlen($secret) < 32 || strlen($secret) > 256 || preg_match('/[\x00-\x1f\x7f]/', $secret) === 1) {
                throw new RuntimeException('Content Connection secret is invalid.');
            }

            $secretFile = $this->option('secret-file');
            $secretFile = is_string($secretFile) && $secretFile !== ''
                ? $secretFile
                : storage_path('app/discussionbridge/connection-secret');

            $lane = trim((string) ($this->option('lane') ?: $this->ask('Connection lane (optional)', '')));
            if ($lane !== '' && preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $lane) !== 1) {
                throw new RuntimeException('Connection lane is invalid.');
            }
            $collections = $this->collections($this->value('collections', 'Published collections', 'pages'));
            $nativeAuthorId = $this->value('native-author-id', 'Native publication service author ID');
            if (strlen($nativeAuthorId) > 255 || preg_match('/[\x00-\x1f\x7f]/', $nativeAuthorId)) {
                throw new RuntimeException('Native publication service author ID is invalid.');
            }
            $authorName = trim(strip_tags($this->value('source-author-name', 'Source author name', (string) config('app.name', 'Statamic'))));
            if ($authorName === '' || strlen($authorName) > 200) {
                throw new RuntimeException('Source author name is invalid.');
            }
            $authorProfile = trim((string) ($this->option('source-author-profile-url') ?: $this->ask('Source author profile URL (optional)', $siteOrigin)));
            if ($authorProfile !== '') {
                $this->sameSiteUrl($authorProfile, $siteOrigin);
            }

            $this->writeSecret($secretFile, $secret);
            unset($secret);

            $values = [
                'DISCUSSIONBRIDGE_ENABLED' => 'true',
                'DISCUSSIONBRIDGE_FORUM_URL' => $forumUrl,
                'DISCUSSIONBRIDGE_SITE_ORIGIN' => $siteOrigin,
                'DISCUSSIONBRIDGE_CONNECTION_ID' => $connectionId,
                'DISCUSSIONBRIDGE_SECRET_FILE' => $secretFile,
                'DISCUSSIONBRIDGE_LANE' => $lane,
                'DISCUSSIONBRIDGE_COLLECTIONS' => implode(',', $collections),
                'DISCUSSIONBRIDGE_NATIVE_AUTHOR_ID' => $nativeAuthorId,
                'DISCUSSIONBRIDGE_SOURCE_AUTHOR_NAME' => $authorName,
                'DISCUSSIONBRIDGE_SOURCE_AUTHOR_PROFILE_URL' => $authorProfile,
            ];
            $backup = $this->updateEnvironment(base_path('.env'), $values);

            if ($this->call('config:clear') !== self::SUCCESS
                || $this->call('migrate', ['--force' => true]) !== self::SUCCESS
                || $this->call('vendor:publish', ['--tag' => 'statamic-discussion-bridge', '--force' => true]) !== self::SUCCESS) {
                throw new RuntimeException('Statamic configuration, migration, or Control Panel asset publication failed.');
            }
            config([
                'discussionbridge.enabled' => true,
                'discussionbridge.forum_url' => $forumUrl,
                'discussionbridge.site_origin' => $siteOrigin,
                'discussionbridge.connection_id' => $connectionId,
                'discussionbridge.secret_file' => $secretFile,
                'discussionbridge.lane' => $lane,
                'discussionbridge.collections' => $collections,
                'discussionbridge.native_author_id' => $nativeAuthorId,
                'discussionbridge.source_author_name' => $authorName,
                'discussionbridge.source_author_profile_url' => $authorProfile,
                'discussionbridge.adapter_id' => 'statamic-discussion-bridge',
                'discussionbridge.adapter_version' => Version::VALUE,
            ]);

            if ($this->call('discussionbridge:refresh-platform-catalog') !== self::SUCCESS) {
                throw new RuntimeException('The Statamic platform catalog could not be registered with The Bridge.');
            }
            $response = $client->records();
            if (! is_array($response['bridge_records'] ?? null) || ! is_array($response['pagination'] ?? null)) {
                throw new RuntimeException('The Bridge returned an invalid verification response.');
            }

            $this->newLine();
            $this->info('DiscussionBridge installation verified.');
            $this->line('Adapter: statamic-discussion-bridge');
            $this->line('Adapter version: '.Version::VALUE);
            $this->line('Connection: '.$connectionId);
            $this->line('Environment backup: '.$backup);

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error(substr($error->getMessage(), 0, 300));

            return self::FAILURE;
        }
    }

    private function value(string $option, string $question, ?string $default = null): string
    {
        $value = $this->option($option);
        if (! is_string($value) || trim($value) === '') {
            $value = $this->ask($question, $default);
        }

        return trim((string) $value);
    }

    private function origin(string $value): string
    {
        if ($value === '' || strlen($value) > 2048) {
            throw new RuntimeException('DiscussionBridge origins are invalid.');
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('DiscussionBridge origins must be HTTPS origins without paths.');
        }

        return 'https://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** @return list<string> */
    private function collections(string $value): array
    {
        $collections = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)))));
        if ($collections === [] || count($collections) > 20) {
            throw new RuntimeException('At least one bounded Statamic collection is required.');
        }
        foreach ($collections as $collection) {
            if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $collection) !== 1) {
                throw new RuntimeException('A Statamic collection handle is invalid.');
            }
        }

        return $collections;
    }

    private function sameSiteUrl(string $value, string $siteOrigin): void
    {
        $url = parse_url($value);
        $site = parse_url($siteOrigin);
        if (! is_array($url) || ! is_array($site) || ($url['scheme'] ?? null) !== 'https'
            || strtolower((string) ($url['host'] ?? '')) !== strtolower((string) ($site['host'] ?? ''))
            || ($url['port'] ?? null) !== ($site['port'] ?? null)
            || isset($url['user'], $url['pass'], $url['query'], $url['fragment'])) {
            throw new RuntimeException('Source author profile URL must belong to this Statamic site.');
        }
    }

    private function writeSecret(string $path, string $secret): void
    {
        $absolute = str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
        if (! $absolute || is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new RuntimeException('Secret-file path must identify a regular absolute file.');
        }
        $directory = dirname($path);
        if ((! is_dir($directory) && ! mkdir($directory, 0700, true)) || is_link($directory) || ! is_writable($directory)) {
            throw new RuntimeException('Secret-file directory cannot be created securely.');
        }
        @chmod($directory, 0700);
        $temporary = tempnam($directory, '.discussionbridge-');
        if (! is_string($temporary)) {
            throw new RuntimeException('Temporary secret file cannot be created.');
        }
        try {
            if (! chmod($temporary, 0600) || file_put_contents($temporary, $secret.PHP_EOL, LOCK_EX) === false || ! rename($temporary, $path)) {
                throw new RuntimeException('Connection secret cannot be written atomically.');
            }
            @chmod($path, 0600);
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @param array<string, string> $values */
    private function updateEnvironment(string $path, array $values): string
    {
        $contents = @file_get_contents($path);
        if (! is_string($contents) || is_link($path)) {
            throw new RuntimeException('Statamic .env file is unavailable or unsafe.');
        }
        foreach (array_keys($values) as $key) {
            $contents = preg_replace('/^'.preg_quote($key, '/').'=.*(?:\R|$)/m', '', $contents);
        }
        $block = [
            '# DiscussionBridge managed by php please discussionbridge:install',
            ...array_map(fn ($key, $value) => $key.'='.$this->environmentValue($value), array_keys($values), $values),
        ];
        $updated = rtrim($contents).PHP_EOL.PHP_EOL.implode(PHP_EOL, $block).PHP_EOL;
        $backup = $path.'.discussionbridge-backup-'.gmdate('Ymd\THis\Z');
        if (! copy($path, $backup)) {
            throw new RuntimeException('Statamic .env backup cannot be created.');
        }
        @chmod($backup, fileperms($path) & 0777);
        $temporary = tempnam(dirname($path), '.discussionbridge-env-');
        if (! is_string($temporary)) {
            throw new RuntimeException('Temporary Statamic environment file cannot be created.');
        }
        try {
            if (file_put_contents($temporary, $updated, LOCK_EX) === false
                || ! chmod($temporary, fileperms($path) & 0777)
                || ! rename($temporary, $path)) {
                throw new RuntimeException('Statamic environment cannot be updated atomically.');
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }

        return $backup;
    }

    private function environmentValue(string $value): string
    {
        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
