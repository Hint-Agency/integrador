<?php

namespace App\Events\Contact;

use App\Events\BaseEvent;

class CreateContactEvent extends BaseEvent
{
    public function getEventType(): string
    {
        return 'contact.created';
    }

    public function getEventDescription(): string
    {
        return 'Contact creation event';
    }
}
