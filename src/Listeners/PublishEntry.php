<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Listeners;

use CodeWorksLabs\DiscussionBridgeStatamic\Delivery\DeliveryEnqueuer;
use Statamic\Events\EntrySaved;
use Throwable;

class PublishEntry
{
    public function __construct(private readonly DeliveryEnqueuer $enqueuer)
    {
    }

    public function handle(EntrySaved $event): void
    {
        try {
            $this->enqueuer->enqueue($event->entry);
        } catch (Throwable $error) {
            report($error);
        }
    }
}
