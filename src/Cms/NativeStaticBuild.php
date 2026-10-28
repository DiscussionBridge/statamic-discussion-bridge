<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

/** Invoke the existing full native generator; exit0 alone is insufficient proof. */
class NativeStaticBuild
{
    public function __construct(private readonly PrivateState $store, private readonly StaticArtifact $artifacts)
    {
    }

    public function configured(): string
    {
        if (\CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets::selected()) {
            \CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets::verifyRich();
        }
        if (! class_exists(\Statamic\StaticSite\Generator::class)
            || ! app(\Statamic\StaticSite\Tasks::class) instanceof \Statamic\StaticSite\ConsecutiveTasks) {
            throw new CmsException('presentation_unsupported');
        }
        $native = (new \ReflectionProperty(\Statamic\StaticSite\Generator::class, 'config'))
            ->getValue(app(\Statamic\StaticSite\Generator::class));
        if ($native !== config('statamic.ssg') || $native['failures'] !== 'warnings'
            || $native['base_url'] !== config('discussionbridge.site_origin') || $native['symlinks'] !== []
            || (new \ReflectionProperty(\Statamic\StaticSite\Generator::class, 'after'))
                ->getValue(app(\Statamic\StaticSite\Generator::class)) !== null) {
            throw new CmsException('presentation_unsupported');
        }
        $destination = $this->artifacts->directory($native['destination']);
        $private = dirname($this->store->path('state.json'));
        foreach ([$private, realpath(public_path()), realpath(base_path('content')), realpath(resource_path())] as $protected) {
            if (is_string($protected) && ($this->artifacts->within($destination, $protected) || $this->artifacts->within($protected, $destination))) {
                throw new CmsException('native_ownership_conflict');
            }
        }
        foreach ($native['copy'] as $source => $target) {
            $actual = realpath($source);
            if ($actual === false || is_link($source) || ! is_string($target) || $target === ''
                || str_starts_with($target, '/') || str_contains($target, '\\') || str_contains($target, ':')
                || in_array('..', explode('/', $target), true)
                || $this->artifacts->within($actual, $private) || $this->artifacts->within($private, $actual)) {
                throw new CmsException('native_ownership_conflict');
            }
            // Inspect trees before native copy, which does not filter private or
            // linked children and does not check every filesystem return value.
            if (is_dir($actual)) {
                $this->artifacts->inspect($actual);
            }
        }
        return $destination;
    }

    public function generate(array &$state, string $transactionId, callable $heartbeat): array
    {
        return $this->generateFor($transactionId, $state['static'][$transactionId],
            function () use (&$state) { $this->store->save($state); }, $heartbeat);
    }

    /** Native command core; caller supplies its own durable transaction owner. */
    public function generateFor(string $transactionId, array &$transaction, callable $persist, callable $heartbeat): array
    {
        $destination = $this->configured();
        $copies = [];
        foreach (config('statamic.ssg.copy') as $source => $target) {
            $target = implode('/', array_filter(explode('/', $target), fn ($part) => $part !== '' && $part !== '.'));
            if (is_file($source)) {
                $copies[$target] = ['bytes' => filesize($source), 'sha256' => hash_file('sha256', $source)];
            } else {
                foreach ($this->artifacts->inspect($source)['files'] as $relative => $fact) {
                    $copies[trim($target.'/'.$relative, '/')] = $fact;
                }
            }
        }
        $heartbeat();
        $transaction['build'] = ['command_id' => (string) Str::uuid(),
            'command' => 'statamic:ssg:generate', 'pid' => getmypid(),
            'started_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z'), 'state' => 'running'];
        $persist();
        $finished = false;
        $generator = app(\Statamic\StaticSite\Generator::class);
        $generator->after(function () use (&$finished) { $finished = true; });
        try {
            $status = app(Kernel::class)->call('statamic:ssg:generate', ['--workers' => 1, '--no-interaction' => true],
                new NativeProgressOutput(\Closure::fromCallable($heartbeat)));
        } finally {
            $generator->after(null);
        }
        $heartbeat();
        if ($status !== 0 || ! $finished) {
            throw new CmsException('native_service_unavailable');
        }
        $capture = $this->artifacts->capture($destination, $transactionId);
        foreach ($copies as $relative => $fact) {
            if (($capture['files'][$relative] ?? null) !== $fact) {
                throw new CmsException('public_verification_failed');
            }
        }
        if (\CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets::selected()) {
            foreach (\CodeWorksLabs\DiscussionBridgeStatamic\Support\PresentationAssets::richPaths() as $source => $public) {
                $key = 'discussionbridge/'.basename($public);
                if (($capture['files'][$key]['sha256'] ?? null) !== hash_file('sha256', $source)) {
                    throw new CmsException('public_verification_failed');
                }
            }
        }
        $transaction['build']['state'] = 'captured';
        $transaction['build']['completed_at'] = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z');
        $transaction['artifact'] = $capture;
        $persist();
        return $capture;
    }
}
