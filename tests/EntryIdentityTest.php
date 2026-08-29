<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Support\EntryIdentity;
use InvalidArgumentException;

class EntryIdentityTest extends TestCase
{
    public function test_identity_is_stable_and_origin_namespaced(): void
    {
        $one = EntryIdentity::externalId('https://flat.example', 'default', 'pages', 'entry-1');
        $two = EntryIdentity::externalId('https://flat.example', 'default', 'pages', 'entry-1');
        $other = EntryIdentity::externalId('https://db.example', 'default', 'pages', 'entry-1');

        $this->assertSame($one, $two);
        $this->assertNotSame($one, $other);
        $this->assertLessThanOrEqual(255, strlen($one));
    }

    public function test_identity_rejects_unsafe_parts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EntryIdentity::externalId('https://flat.example', '../default', 'pages', 'entry-1');
    }
}
