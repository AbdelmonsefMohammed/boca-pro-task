<?php

namespace App\Console\Commands;

use App\Enums\SyncStatus;
use App\Jobs\RemoveAppointmentFromCalendar;
use App\Jobs\SyncAppointmentToCalendar;
use App\Models\Appointment;
use Illuminate\Console\Command;

class ResyncAppointmentsCommand extends Command
{
    protected $signature = 'appointments:resync
        {--minutes=15 : Only consider bookings that have been waiting at least this long}
        {--failed : Include bookings whose sync failed outright}';

    protected $description = 'Re-queue bookings whose calendar sync never finished';

    /**
     * The operational escape hatch for when a job is lost: a worker is killed mid run,
     * the queue is flushed, or a deploy drops what was in flight. Those bookings sit on
     * `pending` forever because nothing else will ever retry them.
     */
    public function handle(): int
    {
        $statuses = $this->option('failed')
            ? [SyncStatus::Pending, SyncStatus::Failed]
            : [SyncStatus::Pending];

        $stuck = Appointment::query()
            ->whereIn('sync_status', $statuses)
            ->where('updated_at', '<=', now()->subMinutes((int) $this->option('minutes')))
            ->get();

        if ($stuck->isEmpty()) {
            $this->comment('Nothing to resync.');

            return self::SUCCESS;
        }

        $stuck->each(function (Appointment $appointment): void {
            $this->info("Requeueing appointment [{$appointment->id}] for calendar {$appointment->calendar_id}...");

            $appointment->update(['sync_status' => SyncStatus::Pending, 'sync_error' => null]);

            $appointment->isCancelled()
                ? RemoveAppointmentFromCalendar::dispatch($appointment)
                : SyncAppointmentToCalendar::dispatch($appointment);
        });

        $this->comment("Requeued {$stuck->count()} booking(s).");

        return self::SUCCESS;
    }
}
