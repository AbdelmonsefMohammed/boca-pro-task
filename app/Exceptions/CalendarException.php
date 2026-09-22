<?php

namespace App\Exceptions;

use RuntimeException;

abstract class CalendarException extends RuntimeException
{
    /**
     * Whether retrying the same call later could plausibly succeed.
     */
    abstract public function isRetryable(): bool;
}
