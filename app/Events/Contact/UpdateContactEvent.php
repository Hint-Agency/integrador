<?php

namespace App\Events\Contact;

use App\Events\BaseEvent;

class UpdateContactEvent extends BaseEvent
{
    public function getEventType(): string
    {
        return 'contact.updated';
    }

    public function getEventDescription(): string
    {
        return 'Contact update event';
    }
}
