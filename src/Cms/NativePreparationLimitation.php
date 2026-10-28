<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

/** Local Bridge preparation inability; never a source-eligibility/wire error. */
class NativePreparationLimitation extends CmsException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('native_source_html_unqualified');
    }
}
