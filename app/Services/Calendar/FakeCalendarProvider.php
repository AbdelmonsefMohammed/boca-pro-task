<?php

namespace App\Services\Calendar;

use App\Data\Calendar;
use App\Data\EventDetails;
use App\Exceptions\CalendarException;
use App\Exceptions\EventAlreadyExists;
use App\Models\GoogleAccount;
use Illuminate\Support\Collection;

/**
 * An in-memory calendar. Tests bind this so nothing reaches the network, and it
 * doubles as the documented substitute for a reviewer with no Google credentials.
 *
 * It enforces the same idempotency contract as the real provider: inserting an
 * event id that is already present raises EventAlreadyExists, and deleting one
 * that is absent succeeds silently.
 */
class FakeCalendarProvider implements CalendarProvider
{
    /** @var Collection<int, Calendar> */
    private Collection $calendars;

    /** @var array<string, array<string, EventDetails>> */
    private array $events = [];

    private ?CalendarException $failure = null;

    private int $failuresRemaining = 0;

    public function __construct()
    {
        $this->calendars = collect([
            new Calendar('primary', 'Primary', 'UTC', isPrimary: true, isWritable: true),
            new Calendar('work@example.com', 'Work', 'Europe/Amsterdam', isPrimary: false, isWritable: true),
        ]);
    }

    /**
     * @param  Collection<int, Calendar>  $calendars
     */
    public function withCalendars(Collection $calendars): self
    {
        $this->calendars = $calendars;

        return $this;
    }

    /**
     * Make the next calls fail, so retry and failure handling can be exercised.
     */
    public function alwaysFailWith(CalendarException $failure, int $times = PHP_INT_MAX): self
    {
        $this->failure = $failure;
        $this->failuresRemaining = $times;

        return $this;
    }

    public function stopFailing(): self
    {
        $this->failure = null;
        $this->failuresRemaining = 0;

        return $this;
    }

    public function listCalendars(GoogleAccount $account): Collection
    {
        $this->failIfConfigured();

        return $this->calendars;
    }

    public function createEvent(GoogleAccount $account, string $calendarId, EventDetails $event): void
    {
        $this->failIfConfigured();

        if (isset($this->events[$calendarId][$event->id])) {
            throw new EventAlreadyExists("The fake calendar already holds an event with id [{$event->id}].");
        }

        $this->events[$calendarId][$event->id] = $event;
    }

    public function deleteEvent(GoogleAccount $account, string $calendarId, string $eventId): void
    {
        $this->failIfConfigured();

        unset($this->events[$calendarId][$eventId]);
    }

    public function hasEvent(string $calendarId, string $eventId): bool
    {
        return isset($this->events[$calendarId][$eventId]);
    }

    public function event(string $calendarId, string $eventId): ?EventDetails
    {
        return $this->events[$calendarId][$eventId] ?? null;
    }

    public function eventCount(): int
    {
        return collect($this->events)->sum(fn (array $events): int => count($events));
    }

    private function failIfConfigured(): void
    {
        if ($this->failure === null) {
            return;
        }

        if ($this->failuresRemaining <= 0) {
            return;
        }

        $this->failuresRemaining--;

        throw $this->failure;
    }
}
