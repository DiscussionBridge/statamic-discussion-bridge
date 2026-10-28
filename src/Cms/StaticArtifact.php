<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/** An immutable private capture of the actual complete native generated tree. */
class StaticArtifact
{
    public function __construct(private readonly PrivateState $store, private readonly PublicVerifier $verifier)
    {
    }

    public function directory(string $directory): string
    {
        if ($directory === '' || (! str_starts_with($directory, '/') && preg_match('/\A[A-Za-z]:[\\\\\/]/', $directory) !== 1)) {
            throw new CmsException('presentation_unsupported');
        }
        for ($path = $directory; dirname($path) !== $path; $path = dirname($path)) {
            if (is_link($path)) {
                throw new CmsException('native_ownership_conflict');
            }
        }
        $actual = realpath($directory);
        if ($actual === false || ! is_dir($actual)) {
            throw new CmsException('native_service_unavailable');
        }
        return $actual;
    }

    public function within(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $root = strtolower($root);
        }
        return $path === $root || str_starts_with($path, $root.'/');
    }

    public function inspect(string $directory): array
    {
        $root = $this->directory($directory);
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            if ($file->isLink() || ! $file->isFile() && ! $file->isDir()) {
                throw new CmsException('native_ownership_conflict');
            }
            if ($file->isFile()) {
                $path = $file->getPathname();
                $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
                $files[$relative] = ['bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $path)];
            }
        }
        ksort($files, SORT_STRING);
        return ['directory' => $root, 'files' => $files,
            'sha256' => hash('sha256', json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
    }

    public function capture(string $directory, string $transactionId): array
    {
        $source = $this->inspect($directory);
        $target = $this->store->path('static-artifact-'.$transactionId);
        if (file_exists($target)) {
            $captured = $this->inspect($target);
            if ($captured['files'] !== $source['files']) {
                throw new CmsException('native_ownership_conflict');
            }
            return $captured;
        }
        if (! mkdir($target, 0700)) {
            throw new CmsException('storage_exhausted');
        }
        foreach ($source['files'] as $relative => $fact) {
            $destination = $target.'/'.$relative;
            if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0700, true)) {
                throw new CmsException('storage_exhausted');
            }
            if (! copy($source['directory'].'/'.$relative, $destination)) {
                throw new CmsException('storage_exhausted');
            }
            @chmod($destination, 0600);
        }
        $captured = $this->inspect($target);
        if ($source['files'] !== $this->inspect($source['directory'])['files'] || $captured['files'] !== $source['files']) {
            throw new CmsException('native_ownership_conflict');
        }
        return $captured;
    }

    public function assertCapture(array $capture): void
    {
        $actual = $this->inspect($capture['directory']);
        if ($actual['files'] !== $capture['files'] || $actual['sha256'] !== $capture['sha256']) {
            throw new CmsException('native_ownership_conflict');
        }
    }

    public function checkOwnedPage(array $capture, $entry, string $mappingId, array $expected, bool $withdraw): void
    {
        $configuration = config('statamic.ssg');
        $configuration['destination'] = $capture['directory'];
        $page = new \Statamic\StaticSite\Page(app(\Illuminate\Filesystem\Filesystem::class), $configuration, $entry);
        $path = $page->path();
        if (! $this->within($path, $capture['directory']) || is_link($path)) {
            throw new CmsException('native_ownership_conflict');
        }
        if ($withdraw) {
            if (file_exists($path)) {
                throw new CmsException('public_verification_failed');
            }
            return;
        }
        $budget = array_sum(array_map('strlen', $expected)) + 2 * 1024 * 1024;
        if (! is_file($path) || filesize($path) > $budget) {
            throw new CmsException('public_verification_failed');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new CmsException('native_service_unavailable');
        }
        try {
            $this->verifier->compareFragments($stream, $mappingId, $expected);
        } finally {
            fclose($stream);
        }
    }
}
