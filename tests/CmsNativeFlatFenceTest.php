<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns\ExercisesCmsNativeFence;
use Mockery;
use Statamic\Facades\File;

class CmsNativeFlatFenceTest extends NativeFlatTestCase
{
    use ExercisesCmsNativeFence;

    private function insideNativeCommit(callable $probe, callable $save): void
    {
        $native = File::getFacadeRoot();
        $proxy = Mockery::mock($native);
        $proxy->shouldReceive('put')->once()->andReturnUsing(function (...$arguments) use ($native, $probe) {
            $probe();
            return $native->put(...$arguments);
        });
        File::swap($proxy);
        try {
            $save();
        } finally {
            File::swap($native);
        }
    }
}
