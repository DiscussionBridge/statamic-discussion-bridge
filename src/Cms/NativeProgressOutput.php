<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use Closure;
use Symfony\Component\Console\Output\Output;

/** Supported synchronous native console output; no separate publisher or timer. */
class NativeProgressOutput extends Output
{
    public function __construct(private readonly Closure $heartbeat)
    {
        parent::__construct(self::VERBOSITY_NORMAL, false);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        ($this->heartbeat)();
    }
}
