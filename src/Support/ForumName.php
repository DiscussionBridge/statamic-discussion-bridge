<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Support;

use RuntimeException;

class ForumName
{
    public static function validate(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 200
            || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/\p{Cc}/u', $value) !== 0
            || preg_match('/[^\p{Z}\x{FEFF}]/u', $value) !== 1) {
            throw new RuntimeException('DiscussionBridge forum_configuration_invalid: forum name is unavailable or invalid.');
        }

        return $value;
    }
}
