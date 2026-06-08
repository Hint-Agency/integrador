<?php

namespace App\Listeners\Contact;

use App\Events\Contact\UpdateContactEvent;
use App\Jobs\ProcessObjectUpdateJob;
use App\Listeners\BaseListener;

class UpdateContactListener extends BaseListener
{
    public function handle(UpdateContactEvent $event): void
    {
        $record = $this->createRecord(
            $event->getEventType(),
            $event->data,
            $event->getEventDescription(),
            $event->parentRecord->id ?? null,
            $event->eventSchedule->id ?? null
        );

        ProcessObjectUpdateJob::dispatch($event->eventSchedule, $record, $event->data);
    }
}
