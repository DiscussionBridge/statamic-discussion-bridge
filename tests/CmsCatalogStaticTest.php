<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

class CmsCatalogStaticTest extends NativeStaticTestCase
{
    use \CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns\ExercisesCmsNativeMetadata;
    use \CodeWorksLabs\DiscussionBridgeStatamic\Tests\Concerns\ExercisesCmsCatalogProducer;

    protected function cmsCatalogProfile(): string
    {
        return 'statamic_ssg';
    }
}
