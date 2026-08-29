<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Transport;

use RuntimeException;

class BridgeRequestException extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $reason)
    {
        parent::__construct('DiscussionBridge rejected the request.');
    }
}
