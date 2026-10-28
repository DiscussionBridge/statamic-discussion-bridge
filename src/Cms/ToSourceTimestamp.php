<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\SourceTimestamp;
use DateTimeImmutable;
use DateTimeZone;

/** Exact persisted native instants; no inferred or rounded timestamps. */
class ToSourceTimestamp
{
    public static function instant(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        SourceTimestamp::validate($value, 'Statamic native source date is invalid.');
        preg_match('/\.(\d+)(?:Z|[+-])/', $value, $fraction);
        if (trim(substr($fraction[1] ?? '', 3), '0') !== '') {
            // A millisecond field cannot carry this actual native precision.
            throw new CmsException('source_timestamp_precision_unsupported');
        }
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
