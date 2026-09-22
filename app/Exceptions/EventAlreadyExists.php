<?php

namespace App\Exceptions;

/**
 * Google already holds an event with the id we generated, which means this is a
 * replay of a sync that already succeeded. Callers treat this as success (4.3).
 */
class EventAlreadyExists extends CalendarException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
