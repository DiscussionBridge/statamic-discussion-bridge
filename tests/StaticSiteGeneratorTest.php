<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use CodeWorksLabs\DiscussionBridgeStatamic\StaticSite\PleaseCommandRunner;
use CodeWorksLabs\DiscussionBridgeStatamic\StaticSite\StaticSiteGenerator;
use Mockery;

class StaticSiteGeneratorTest extends TestCase
{
    public function test_it_runs_the_fail_closed_preparation_before_static_generation(): void
    {
        $commands = Mockery::mock(PleaseCommandRunner::class);
        $commands->shouldReceive('run')->once()->ordered()->with('discussionbridge:ssg-prepare');
        $commands->shouldReceive('run')->once()->ordered()->with('ssg:generate');

        $generator = Mockery::mock(StaticSiteGenerator::class, [$commands])
            ->makePartial();
        $generator->shouldReceive('available')->once()->andReturnTrue();

        $this->assertSame([
            'prepared' => true,
            'generated' => true,
        ], $generator->generate());
    }
}
