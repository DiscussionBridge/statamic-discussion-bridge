<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

class CmsToFlatTest extends NativeFlatTestCase
{
    use \CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns\ExercisesCmsToDelivery;
    use \CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns\ManagesPresentationFiles;
    use \CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns\ExercisesCmsSourceSocket;

    public function test_current_to_native_failed_file_delete_does_not_treat_forgotten_stache_item_as_absence(): void
    {
        $entry = $this->acceptedToEntry();
        $row = \Illuminate\Support\Facades\DB::table('discussionbridge_deliveries')->sole();
        $path = $entry->path();
        $native = \Statamic\Facades\File::getFacadeRoot();
        $proxy = \Mockery::mock($native);
        $proxy->shouldReceive('delete')->once()->andReturn(false);
        \Statamic\Facades\File::swap($proxy);
        try {
            // Official native Entry returns true despite failed underlying delete.
            $this->assertTrue($entry->delete());
        } finally {
            \Statamic\Facades\File::swap($native);
        }
        $this->assertFileExists($path);
        $this->assertArrayNotHasKey('closure', $this->toState()['to'][$row->external_id]);
        $this->assertSame($row->status, \Illuminate\Support\Facades\DB::table('discussionbridge_deliveries')->sole()->status);
        $this->assertCount(0, $this->toWithdrawRequests);
    }
}
