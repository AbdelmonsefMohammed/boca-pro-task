<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Exceptions\CalendarException;
use App\Models\Appointment;
use App\Services\Calendar\CalendarProvider;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RemoveAppointmentFromCalendar implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public Appointment $appointment) {}

    public function uniqueId(): string
    {
        return (string) $this->appointment->id;
    }

    /**
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

        // Only a cancelled booking should be pulled from the calendar. If it was
        // rebooked or the cancellation was rolled back, leave Google alone.
        if (! $appointment->isCancelled()) {
            return;
        }

        $account = $appointment->user->googleAccount;

        if ($account === null) {
            $this->markOutOfSync($appointment, __('The Google account was disconnected, so the event may still be on the calendar.'));

            return;
        }

        try {
            // Deleting an event that was never created returns 404, which the provider
            // treats as success. That is what makes this safe to run unconditionally.
            $provider->deleteEvent($account, $appointment->calendar_id, $appointment->external_event_id);
        } catch (CalendarException $exception) {
            $this->handleFailure($appointment, $exception);

            return;
        }

        $appointment->update([
            'sync_status' => SyncStatus::Synced,
            'sync_error' => null,
        ]);
    }

    /**
     * The booking stays cancelled locally whatever Google says. Local state is what the
     * user acted on, so the only thing a failure changes is that we admit the calendar
     * may disagree.
     */
    private function handleFailure(Appointment $appointment, CalendarException $exception): void
    {
        if ($exception->isRetryable() && $this->attempts() < $this->tries) {
            $this->markOutOfSync($appointment, $exception->getMessage());

            throw $exception;
        }

        $this->markOutOfSync($appointment, $exception->getMessage());
    }

    private function markOutOfSync(Appointment $appointment, string $reason): void
    {
        $appointment->update([
            'sync_status' => SyncStatus::Failed,
            'sync_error' => $reason,
        ]);
    }
}
