<?php

namespace App\Http\Controllers;

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Appointments\CreateAppointment;
use App\Data\Calendar;
use App\Enums\SyncStatus;
use App\Exceptions\CalendarAuthExpired;
use App\Exceptions\CalendarUnavailable;
use App\Exceptions\SlotAlreadyBooked;
use App\Http\Requests\StoreAppointmentRequest;
use App\Jobs\RemoveAppointmentFromCalendar;
use App\Jobs\SyncAppointmentToCalendar;
use App\Models\Appointment;
use App\Services\Calendar\CalendarDirectory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    /**
     * List the signed in user's bookings, split into upcoming and past.
     */
    public function index(Request $request, CalendarDirectory $directory): Response|RedirectResponse
    {
        $account = $request->user()->googleAccount;

        if ($account === null) {
            return to_route('google.connection');
        }

        $appointments = $request->user()->appointments()->orderBy('starts_at')->get();

        // The calendar list needs Google; the bookings do not. Failing softly here is what
        // keeps an outage from taking down the page that shows bookings already made.
        $calendars = collect();
        $calendarError = null;

        try {
            $calendars = $directory->for($account);
        } catch (CalendarAuthExpired) {
            $calendarError = __('Google revoked access. Reconnect the account to change calendars.');
        } catch (CalendarUnavailable $exception) {
            $calendarError = $exception->getMessage();
        }

        return Inertia::render('appointments/index', [
            'calendars' => $calendars->map(fn (Calendar $calendar): array => $calendar->toArray())->all(),
            'selectedCalendarId' => $account->selected_calendar_id,
            'selectedCalendarName' => $account->selected_calendar_name,
            'calendarError' => $calendarError,
            'durations' => StoreAppointmentRequest::$durations,
            'upcoming' => $this->present($appointments->filter(fn (Appointment $appointment): bool => $appointment->ends_at->isFuture())),
            'past' => $this->present($appointments->filter(fn (Appointment $appointment): bool => $appointment->ends_at->isPast())->reverse()),
        ]);
    }

    public function store(StoreAppointmentRequest $request, CreateAppointment $createAppointment): RedirectResponse
    {
        $account = $request->user()->googleAccount;

        if ($account === null || ! $account->hasSelectedCalendar()) {
            throw ValidationException::withMessages([
                'title' => __('Choose a booking calendar before creating an appointment.'),
            ]);
        }

        try {
            $createAppointment->handle(
                $account,
                $request->details(),
                $request->startsAt(),
                $request->durationMinutes(),
                $request->string('timezone')->toString(),
            );
        } catch (SlotAlreadyBooked $exception) {
            throw ValidationException::withMessages(['start_time' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Appointment booked.')]);

        return to_route('appointments.index');
    }

    /**
     * Cancel a booking, which frees the slot and pulls the event from the calendar.
     */
    public function destroy(Request $request, Appointment $appointment, CancelAppointment $cancelAppointment): RedirectResponse
    {
        Gate::authorize('delete', $appointment);

        $cancelAppointment->handle($appointment);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Appointment cancelled.')]);

        return to_route('appointments.index');
    }

    /**
     * Try a failed sync again.
     *
     * Which job to run depends on what the booking now is: a cancelled one needs
     * removing from the calendar, a live one needs writing to it. Retrying the wrong
     * direction would push a cancelled booking back onto the calendar.
     */
    public function sync(Request $request, Appointment $appointment): RedirectResponse
    {
        Gate::authorize('sync', $appointment);

        if ($appointment->sync_status !== SyncStatus::Failed) {
            return to_route('appointments.index');
        }

        $appointment->update(['sync_status' => SyncStatus::Pending, 'sync_error' => null]);

        $appointment->isCancelled()
            ? RemoveAppointmentFromCalendar::dispatch($appointment)
            : SyncAppointmentToCalendar::dispatch($appointment);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Retrying the calendar sync.')]);

        return to_route('appointments.index');
    }

    /**
     * Group bookings under the day they fall on, in their own timezone, which is the
     * day the person who booked them means. Ordering comes from the query, so the
     * groups stay in chronological order.
     *
     * @param  EloquentCollection<int, Appointment>  $appointments
     * @return array<int, array<string, mixed>>
     */
    private function present(EloquentCollection $appointments): array
    {
        return $appointments
            ->groupBy(fn (Appointment $appointment): string => $appointment->starts_at
                ->setTimezone($appointment->timezone)
                ->format('Y-m-d'))
            ->map(fn (EloquentCollection $onThisDay): array => [
                'date' => $onThisDay->first()->starts_at
                    ->setTimezone($onThisDay->first()->timezone)
                    ->format('l j F Y'),
                'bookings' => $onThisDay->map(fn (Appointment $appointment): array => [
                    'id' => $appointment->id,
                    'title' => $appointment->title,
                    'customerName' => $appointment->customer_name,
                    'customerEmail' => $appointment->customer_email,
                    'startsAt' => $appointment->starts_at->setTimezone($appointment->timezone)->format('H:i'),
                    'endsAt' => $appointment->ends_at->setTimezone($appointment->timezone)->format('H:i'),
                    'timezone' => $appointment->timezone,
                    'syncStatus' => $appointment->sync_status->value,
                    'syncError' => $appointment->sync_error,
                    'isCancelled' => $appointment->isCancelled(),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
