<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns\ExercisesCmsNativeFence;
use Statamic\Eloquent\Entries\UuidEntryModel;

class CmsNativeDatabaseFenceTest extends NativeDatabaseTestCase
{
    use ExercisesCmsNativeFence;

    private function insideNativeCommit(callable $probe, callable $save): void
    {
        $dispatcher = UuidEntryModel::getEventDispatcher();
        UuidEntryModel::setEventDispatcher(clone $dispatcher);
        UuidEntryModel::saving(fn () => $probe());
        try {
            $save();
        } finally {
            UuidEntryModel::setEventDispatcher($dispatcher);
        }
    }
}
