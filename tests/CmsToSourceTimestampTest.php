<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsClient;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\CmsException;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\ToSourceTimestamp;

class CmsToSourceTimestampTest extends TestCase
{
    public function test_native_instants_convert_exactly_to_declared_utc_milliseconds_and_unknown_remains_null(): void
    {
        $this->assertNull(ToSourceTimestamp::instant(null));
        foreach ([
            '2026-09-01T08:00:00+02:00' => '2026-09-01T06:00:00.000Z',
            '2026-09-01T08:00:00.123000000+02:00' => '2026-09-01T06:00:00.123Z',
            '2026-09-01T08:00:00.1Z' => '2026-09-01T08:00:00.100Z',
        ] as $native => $expected) {
            $actual = ToSourceTimestamp::instant($native);
            $this->assertSame($expected, $actual);
            $this->assertSame($actual, app(CmsClient::class)->codec()->validate('Instant', $actual));
        }
    }

    public function test_native_submillisecond_precision_requires_attention_instead_of_silent_rounding(): void
    {
        $this->expectException(CmsException::class);
        $this->expectExceptionMessage('source_timestamp_precision_unsupported');
        ToSourceTimestamp::instant('2026-09-01T08:00:00.123000001Z');
    }
}
