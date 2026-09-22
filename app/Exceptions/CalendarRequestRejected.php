<?php

namespace App\Exceptions;

/**
 * The provider rejected the request itself, for example an unknown calendar or a
 * malformed payload. Replaying it unchanged will fail the same way.
 */
class CalendarRequestRejected extends CalendarException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
