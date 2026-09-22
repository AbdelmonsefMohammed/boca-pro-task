<?php

namespace App\Services\Calendar;

use App\Data\Calendar;
use App\Data\EventDetails;
use App\Exceptions\CalendarAuthExpired;
use App\Exceptions\CalendarRequestRejected;
use App\Exceptions\CalendarUnavailable;
use App\Exceptions\EventAlreadyExists;
use App\Models\GoogleAccount;
use Illuminate\Support\Collection;

/**
 * The seam between the application and the external calendar. Controllers and jobs
 * depend on this rather than on Google, which is what lets the suite run without
 * ever making a network call.
 */
interface CalendarProvider
{
    /**
     * @return Collection<int, Calendar>
     *
     * @throws CalendarUnavailable
     * @throws CalendarAuthExpired
     */
    public function listCalendars(GoogleAccount $account): Collection;

    /**
     * @throws EventAlreadyExists when the event id is already present, meaning this is a replay
     * @throws CalendarUnavailable
     * @throws CalendarAuthExpired
     * @throws CalendarRequestRejected
     */
    public function createEvent(GoogleAccount $account, string $calendarId, EventDetails $event): void;

    /**
     * Deleting an event that is already gone is a success, not an error.
     *
     * @throws CalendarUnavailable
     * @throws CalendarAuthExpired
     * @throws CalendarRequestRejected
     */
    public function deleteEvent(GoogleAccount $account, string $calendarId, string $eventId): void;
}
