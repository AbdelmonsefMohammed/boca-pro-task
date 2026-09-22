<?php

namespace App\Actions\Appointments;

use App\Jobs\RemoveAppointmentFromCalendar;
use App\Models\Appointment;

class CancelAppointment
{
    /**
     * Cancel a booking and pull it back off the calendar.
     *
     * Cancelling is idempotent: a booking that is already cancelled is left alone
     * rather than having its timestamp moved or a second removal queued.
     */
    public function handle(Appointment $appointment): Appointment
    {
        if ($appointment->isCancelled()) {
            return $appointment;
        }

        // Freeing the slot happens locally and immediately. Google is reconciled by the
        // queued job, so cancelling never blocks on a provider that is slow or down.
        $appointment->update(['cancelled_at' => now()]);

        RemoveAppointmentFromCalendar::dispatch($appointment);

        return $appointment;
    }
}
