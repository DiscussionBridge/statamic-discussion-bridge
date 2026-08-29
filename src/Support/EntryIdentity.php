<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Support;

use InvalidArgumentException;

class EntryIdentity
{
    public static function externalId(string $siteOrigin, string $site, string $collection, string $entryId): string
    {
        foreach ([$site, $collection, $entryId] as $part) {
            if ($part === '' || strlen($part) > 100 || ! preg_match('/\A[A-Za-z0-9._:-]+\z/', $part)) {
                throw new InvalidArgumentException('Statamic entry identity is invalid.');
            }
        }

        $id = sprintf('statamic-entry:%s:%s:%s:%s', hash('sha256', $siteOrigin), $site, $collection, $entryId);
        if (strlen($id) > 255) {
            throw new InvalidArgumentException('Statamic entry identity is too long.');
        }

        return $id;
    }
}
