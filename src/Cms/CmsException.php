<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use RuntimeException;

class CmsException extends RuntimeException
{
    public function __construct(public readonly string $failureCode, public readonly ?int $httpStatus = null)
    {
        // Never include receiver bodies, secrets, lease tokens or request URLs.
        parent::__construct('DiscussionBridge CMS: '.$failureCode);
    }
}
