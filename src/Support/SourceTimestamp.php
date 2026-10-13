<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Support;

use DateTimeImmutable;
use RuntimeException;

class SourceTimestamp
{
    public static function validate(mixed $value, string $message): string
    {
        if (! is_string($value) || strlen($value) > 64
            || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,9}))?(Z|[+-](\d{2}):(\d{2}))\z/', $value, $parts)
            || (int) $parts[1] < 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59
            || (isset($parts[9]) && ((int) $parts[9] > 23 || (int) $parts[10] > 59))) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    // PHP's DateTime truncates precision beyond microseconds. Compare whole
    // UTC seconds, then all nine declared fractional digits without rounding.
    public static function precedes(string $left, string $right): bool
    {
        $leftSeconds = (new DateTimeImmutable($left))->getTimestamp();
        $rightSeconds = (new DateTimeImmutable($right))->getTimestamp();
        if ($leftSeconds !== $rightSeconds) {
            return $leftSeconds < $rightSeconds;
        }

        preg_match('/\.(\d+)(?:Z|[+-])/', $left, $leftFraction);
        preg_match('/\.(\d+)(?:Z|[+-])/', $right, $rightFraction);

        return strcmp(str_pad($leftFraction[1] ?? '', 9, '0'), str_pad($rightFraction[1] ?? '', 9, '0')) < 0;
    }
}
