<?php

namespace App\Actions\Appointments;

use App\Exceptions\SlotAlreadyBooked;
use App\Jobs\SyncAppointmentToCalendar;
use App\Models\Appointment;
use App\Models\GoogleAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CreateAppointment
{
    /**
     * Book a slot on the account's chosen calendar.
     *
     * Google is never called here. The row is committed first and pushed by a queued
     * job, so a slow or unavailable provider cannot make booking fail or hang (4.2).
     *
     * @param  array{title: string, customer_name: string, customer_email: string}  $details
     *
     * @throws SlotAlreadyBooked
     */
    public function handle(
        GoogleAccount $account,
        array $details,
        CarbonImmutable $startsAt,
        int $durationMinutes,
        string $timezone,
    ): Appointment {
        $endsAt = $startsAt->addMinutes($durationMinutes);

        $appointment = DB::transaction(function () use ($account, $details, $startsAt, $endsAt, $timezone): Appointment {
            // Locking the account row serialises every booking attempt against this
            // calendar, so two requests cannot both pass the overlap check (4.1).
            GoogleAccount::whereKey($account->id)->lockForUpdate()->first();

            if ($this->isTaken($account->selected_calendar_id, $startsAt, $endsAt)) {
                throw new SlotAlreadyBooked('That time overlaps an existing booking.');
            }

            return Appointment::query()->create([
                ...$details,
                'user_id' => $account->user_id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'timezone' => $timezone,
                'calendar_id' => $account->selected_calendar_id,
                'external_event_id' => Appointment::generateExternalEventId(),
            ]);
        });

        SyncAppointmentToCalendar::dispatch($appointment);

        return $appointment;
    }

    /**
     * Half open comparison: a booking that ends exactly when another starts does not
     * overlap, so back to back slots remain bookable.
     */
    private function isTaken(?string $calendarId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        return Appointment::query()
            ->scheduled()
            ->where('calendar_id', $calendarId)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }
}
