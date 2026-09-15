<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use Statamic\Facades\Utility;

class ControlPanelUtilityTest extends TestCase
{
    public function test_it_registers_the_permissioned_discussionbridge_utility(): void
    {
        Utility::boot();

        $utility = Utility::find('discussionbridge');

        $this->assertNotNull($utility);
        $this->assertSame('DiscussionBridge', $utility->title());
        $this->assertSame('discussionbridge::utility', $utility->view());
        $this->assertNotNull($utility->routes());
    }
}
