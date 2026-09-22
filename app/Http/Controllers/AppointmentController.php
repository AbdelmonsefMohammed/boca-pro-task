<?php

namespace App\Http\Controllers;

use App\Actions\Appointments\CreateAppointment;
use App\Exceptions\SlotAlreadyBooked;
use App\Http\Requests\StoreAppointmentRequest;
use App\Models\Appointment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    /**
     * List the signed in user's bookings, split into upcoming and past.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $account = $request->user()->googleAccount;

        if ($account === null) {
            return to_route('google.connection');
        }

        if (! $account->hasSelectedCalendar()) {
            return to_route('calendar.edit');
        }

        $appointments = $request->user()->appointments()->orderBy('starts_at')->get();

        return Inertia::render('appointments/index', [
            'calendarName' => $account->selected_calendar_name,
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
