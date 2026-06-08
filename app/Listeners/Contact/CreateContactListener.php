<?php

namespace App\Listeners\Contact;

use App\Events\Contact\CreateContactEvent;
use App\Jobs\ProcessObjectUpdateJob;
use App\Listeners\BaseListener;

class CreateContactListener extends BaseListener
{
    public function handle(CreateContactEvent $event): void
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
