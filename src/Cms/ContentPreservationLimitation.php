<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use RuntimeException;

/** A durable local rendering limitation, never a receiver Failure/ErrorCode. */
class ContentPreservationLimitation extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $lostFunction = null,
        public readonly ?string $lostData = null)
    {
        parent::__construct('DiscussionBridge local content preservation limitation: '.$reason
            .(($lostFunction ?? $lostData) === null ? '' : ' — '.($lostFunction ?? $lostData)));
    }
}
