<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Closure;
use RuntimeException;

/** One native writer and crash-persistent replay/fence state; not receiver authority. */
class PrivateState
{
    private $lock = null;
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $directory ??= config('discussionbridge.cms_state_directory') ?? storage_path('app/private/discussionbridge/cms-v4');
        if (! is_string($directory) || $directory === '' || is_link($directory)
            || (! str_starts_with($directory, '/') && preg_match('/\A[A-Za-z]:[\\\\\/]/', $directory) !== 1)) {
            throw new CmsException('native_service_unavailable');
        }
        for ($path = $directory; dirname($path) !== $path; $path = dirname($path)) {
            if (is_link($path)) {
                throw new CmsException('native_ownership_conflict');
            }
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new CmsException('storage_exhausted');
        }
        $actual = realpath($directory);
        $public = realpath(public_path());
        if ($actual === false || ($public !== false && $this->within($actual, $public))) {
            throw new CmsException('native_ownership_conflict');
        }
        // Reject linked ancestors as well as the final directory.
        for ($path = $actual; dirname($path) !== $path; $path = dirname($path)) {
            if (is_link($path)) {
                throw new CmsException('native_ownership_conflict');
            }
        }
        $this->directory = $actual;
    }

    public function locked(Closure $operation): mixed
    {
        if ($this->lock !== null) {
            throw new RuntimeException('Nested CMS writer lock is not allowed.');
        }
        $path = $this->path('writer.lock');
        if (is_link($path)) {
            throw new CmsException('native_ownership_conflict');
        }
        $lock = fopen($path, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new CmsException('work_busy');
        }
        @chmod($path, 0600);
        $this->lock = $lock;
        try {
            return $operation($this);
        } finally {
            $this->lock = null;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function read(): array
    {
        $this->assertLocked();
        $path = $this->path('state.json');
        if (! file_exists($path)) {
            return ['version' => 1, 'binding' => null, 'feed' => ['stream' => 'initial', 'cursor' => null],
                'events' => [], 'sources' => [], 'mappings' => [], 'mutations' => [], 'work' => []];
        }
        if (is_link($path)) {
            throw new CmsException('native_ownership_conflict');
        }
        $bytes = file_get_contents($path);
        try {
            $state = is_string($bytes) ? json_decode($bytes, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            throw new CmsException('native_restore_required');
        }
        if (! is_array($state) || ($state['version'] ?? null) !== 1
            || ! isset($state['feed'], $state['events'], $state['sources'], $state['mappings'], $state['mutations'], $state['work'])) {
            throw new CmsException('native_restore_required');
        }
        return $state;
    }

    /** Read an atomically replaced local reservation; never grant receiver authority. */
    public function reservation(string $url): ?array
    {
        $path = $this->path('state.json');
        if (! is_file($path)) {
            return null;
        }
        if (is_link($path)) {
            throw new CmsException('native_ownership_conflict');
        }
        try {
            $state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            foreach ($state['mappings'] ?? [] as $binding) {
                $mapping = json_decode($binding['mapping'], true, 512, JSON_THROW_ON_ERROR);
                if ($mapping['canonical_url'] === $url) {
                    return ['native_id' => $binding['native_id'] ?? null, 'mapping_id' => $mapping['mapping_id']];
                }
            }
        } catch (\JsonException) {
            throw new CmsException('native_restore_required');
        }
        return null;
    }

    public function save(array $state): void
    {
        $this->assertLocked();
        $this->write('state.json', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function write(string $name, string $bytes): void
    {
        $this->assertLocked();
        $target = $this->path($name);
        if (is_link($target)) {
            throw new CmsException('native_ownership_conflict');
        }
        $temporary = $this->path('write-'.bin2hex(random_bytes(16)).'.tmp');
        $stream = fopen($temporary, 'xb');
        if ($stream === false) {
            throw new CmsException('storage_exhausted');
        }
        @chmod($temporary, 0600);
        try {
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($stream, substr($bytes, $offset, 65536));
                if ($written === false || $written === 0) {
                    throw new CmsException('storage_exhausted');
                }
                $offset += $written;
            }
            if (! fflush($stream) || (function_exists('fsync') && ! fsync($stream))) {
                throw new CmsException('storage_exhausted');
            }
            fclose($stream);
            $stream = null;
            // Windows can briefly deny replacement while a native reader or
            // scanner has the old file open. Never delete the durable old file.
            $replaced = false;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                if (@rename($temporary, $target)) {
                    $replaced = true;
                    break;
                }
                usleep(20000);
            }
            if (! $replaced) {
                throw new CmsException('storage_exhausted');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function path(string $name): string
    {
        if (preg_match('/\A[a-zA-Z0-9_.-]+\z/', $name) !== 1 || $name === '.' || $name === '..') {
            throw new RuntimeException('Invalid private CMS state filename.');
        }
        return $this->directory.DIRECTORY_SEPARATOR.$name;
    }

    private function assertLocked(): void
    {
        if (! is_resource($this->lock)) {
            throw new RuntimeException('CMS private state requires its native writer lock.');
        }
    }

    private function within(string $path, string $parent): bool
    {
        $path = strtolower(str_replace('\\', '/', $path));
        $parent = rtrim(strtolower(str_replace('\\', '/', $parent)), '/');
        return $path === $parent || str_starts_with($path, $parent.'/');
    }
}
