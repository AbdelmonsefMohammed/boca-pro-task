<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Another live appointment already covers part of the requested window on this
 * calendar. Raised inside the locked transaction in 4.1, so it is the losing side
 * of a race rather than a stale read.
 */
class SlotAlreadyBooked extends RuntimeException {}
