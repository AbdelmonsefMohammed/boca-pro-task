<?php

namespace App\Exceptions;

/**
 * The provider timed out, refused the connection, rate limited us, or returned a 5xx.
 * Nothing is wrong with the request itself, so the sync job should back off and retry.
 */
class CalendarUnavailable extends CalendarException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
