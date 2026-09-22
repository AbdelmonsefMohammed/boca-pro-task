<?php

namespace App\Exceptions;

/**
 * The grant can no longer be used and refreshing it did not help, so the user
 * must reconnect. Retrying with the same credentials cannot succeed.
 */
class CalendarAuthExpired extends CalendarException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
