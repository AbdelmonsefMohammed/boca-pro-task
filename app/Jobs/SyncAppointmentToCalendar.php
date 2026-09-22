<?php

namespace App\Jobs;

use App\Data\EventDetails;
use App\Enums\SyncStatus;
use App\Exceptions\CalendarException;
use App\Exceptions\EventAlreadyExists;
use App\Models\Appointment;
use App\Services\Calendar\CalendarProvider;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncAppointmentToCalendar implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public Appointment $appointment) {}

    /**
     * Collapses a double dispatch for the same appointment into one queued job (4.3).
     */
    public function uniqueId(): string
    {
        return (string) $this->appointment->id;
    }

    /**
     * Exponential backoff, so a provider that is briefly unavailable is retried
     * without hammering it.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function handle(CalendarProvider $provider): void
    {
        $appointment = $this->appointment->fresh();

        if ($appointment === null) {
            return;
        }

        // Re-read rather than trust the serialised copy: the appointment may have been
        // cancelled, or already synced by an earlier attempt, while this job waited (4.3).
        if ($appointment->sync_status === SyncStatus::Synced) {
            return;
        }

        if ($appointment->isCancelled()) {
            return;
        }

        $account = $appointment->user->googleAccount;

        if ($account === null) {
            $this->markFailed($appointment, __('The Google account was disconnected before this booking synced.'));

            return;
        }

        try {
            $provider->createEvent($account, $appointment->calendar_id, $this->eventFor($appointment));
        } catch (EventAlreadyExists) {
            // Google already holds the id we generated, so an earlier attempt succeeded.
            $this->markSynced($appointment);

            return;
        } catch (CalendarException $exception) {
            $this->handleFailure($appointment, $exception);

            return;
        }

        $this->markSynced($appointment);
    }

    private function eventFor(Appointment $appointment): EventDetails
    {
        return new EventDetails(
            id: $appointment->external_event_id,
            title: $appointment->title,
            customerName: $appointment->customer_name,
            customerEmail: $appointment->customer_email,
            startsAt: $appointment->starts_at,
            endsAt: $appointment->ends_at,
            timezone: $appointment->timezone,
        );
    }

    /**
     * A retryable provider problem is rethrown so the queue applies the backoff. Anything
     * terminal is recorded on the appointment, which stays booked either way: the user's
     * booking is never silently discarded because Google refused it (4.2).
     */
    private function handleFailure(Appointment $appointment, CalendarException $exception): void
    {
        if ($exception->isRetryable() && $this->attempts() < $this->tries) {
            $appointment->update(['sync_error' => $exception->getMessage()]);

            throw $exception;
        }

        $this->markFailed($appointment, $exception->getMessage());
    }

    private function markSynced(Appointment $appointment): void
    {
        $appointment->update([
            'sync_status' => SyncStatus::Synced,
            'sync_error' => null,
        ]);
    }

    private function markFailed(Appointment $appointment, string $reason): void
    {
        $appointment->update([
            'sync_status' => SyncStatus::Failed,
            'sync_error' => $reason,
        ]);
    }
}
