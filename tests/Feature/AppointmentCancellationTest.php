<?php

use App\Data\EventDetails;
use App\Enums\SyncStatus;
use App\Exceptions\CalendarRequestRejected;
use App\Exceptions\CalendarUnavailable;
use App\Jobs\RemoveAppointmentFromCalendar;
use App\Models\Appointment;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Calendar\CalendarProvider;
use App\Services\Calendar\FakeCalendarProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->calendar = new FakeCalendarProvider;
    $this->app->instance(CalendarProvider::class, $this->calendar);

    $this->user = User::factory()->create();
    GoogleAccount::factory()->for($this->user)->withSelectedCalendar('work@example.com')->create();

    $this->appointment = Appointment::factory()->for($this->user)
        ->onCalendar('work@example.com')
        ->startingAt(CarbonImmutable::now('UTC')->addWeek()->setTime(9, 0), 30)
        ->synced()
        ->create();
});

function runRemoval(Appointment $appointment): void
{
    app()->call([new RemoveAppointmentFromCalendar($appointment), 'handle']);
}

function eventDetailsFor(Appointment $appointment): EventDetails
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

test('it cancels the booking and queues the calendar removal', function (): void {
    Queue::fake();

    $this->actingAs($this->user)
        ->delete(route('appointments.destroy', $this->appointment))
        ->assertRedirect(route('appointments.index'));

    expect($this->appointment->fresh()->isCancelled())->toBeTrue();
    Queue::assertPushed(RemoveAppointmentFromCalendar::class, 1);
});

test('it removes the event from the calendar', function (): void {
    $this->calendar->createEvent(
        $this->user->googleAccount,
        'work@example.com',
        eventDetailsFor($this->appointment),
    );

    $this->actingAs($this->user)->delete(route('appointments.destroy', $this->appointment));
    runRemoval($this->appointment->fresh());

    expect($this->calendar->hasEvent('work@example.com', $this->appointment->external_event_id))->toBeFalse()
        ->and($this->appointment->fresh()->sync_status)->toBe(SyncStatus::Synced);
});

test('it frees the slot so the time can be rebooked', function (): void {
    $startsAt = $this->appointment->starts_at;

    $this->actingAs($this->user)->delete(route('appointments.destroy', $this->appointment));

    $this->actingAs($this->user)->post(route('appointments.store'), [
        'title' => 'Replacement',
        'customer_name' => 'Grace Hopper',
        'customer_email' => 'grace@example.com',
        'date' => $startsAt->format('Y-m-d'),
        'start_time' => $startsAt->format('H:i'),
        'duration_minutes' => 30,
        'timezone' => 'UTC',
    ])->assertSessionHasNoErrors();

    expect(Appointment::scheduled()->count())->toBe(1);
});

test('it is idempotent when cancelled twice', function (): void {
    Queue::fake();

    $this->actingAs($this->user)->delete(route('appointments.destroy', $this->appointment));
    $cancelledAt = $this->appointment->fresh()->cancelled_at;

    $this->travel(5)->minutes();
    $this->actingAs($this->user)->delete(route('appointments.destroy', $this->appointment));

    expect($this->appointment->fresh()->cancelled_at->toIso8601String())->toBe($cancelledAt->toIso8601String());
    Queue::assertPushed(RemoveAppointmentFromCalendar::class, 1);
});

test('it tolerates removing an event the calendar never had', function (): void {
    $this->actingAs($this->user)->delete(route('appointments.destroy', $this->appointment));

    runRemoval($this->appointment->fresh());

    expect($this->appointment->fresh()->sync_status)->toBe(SyncStatus::Synced)
        ->and($this->appointment->fresh()->sync_error)->toBeNull();
});

test('it keeps the cancellation and flags the mismatch when the provider refuses', function (): void {
    $this->actingAs($this->user)->delete(route('appointments.destroy', $this->appointment));

    $this->calendar->alwaysFailWith(new CalendarRequestRejected('Calendar not found.'));
    runRemoval($this->appointment->fresh());

    $appointment = $this->appointment->fresh();

    expect($appointment->isCancelled())->toBeTrue()
        ->and($appointment->sync_status)->toBe(SyncStatus::Failed)
        ->and($appointment->sync_error)->toBe('Calendar not found.');
});

test('it rethrows a retryable removal failure so the queue retries', function (): void {
    $this->actingAs($this->user)->delete(route('appointments.destroy', $this->appointment));

    $this->calendar->alwaysFailWith(new CalendarUnavailable('Google Calendar returned 503.'));

    expect(fn () => runRemoval($this->appointment->fresh()))->toThrow(CalendarUnavailable::class);
    expect($this->appointment->fresh()->isCancelled())->toBeTrue();
});

test('it leaves the calendar alone for a booking that is not cancelled', function (): void {
    $this->calendar->createEvent(
        $this->user->googleAccount,
        'work@example.com',
        eventDetailsFor($this->appointment),
    );

    runRemoval($this->appointment);

    expect($this->calendar->hasEvent('work@example.com', $this->appointment->external_event_id))->toBeTrue();
});

test('it flags the mismatch when the google account was disconnected first', function (): void {
    $this->actingAs($this->user)->delete(route('appointments.destroy', $this->appointment));
    $this->user->googleAccount->delete();

    runRemoval($this->appointment->fresh());

    expect($this->appointment->fresh()->sync_status)->toBe(SyncStatus::Failed)
        ->and($this->appointment->fresh()->isCancelled())->toBeTrue();
});

test('it forbids cancelling a booking that belongs to someone else', function (): void {
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->delete(route('appointments.destroy', $this->appointment))
        ->assertForbidden();

    expect($this->appointment->fresh()->isCancelled())->toBeFalse();
});

test('it keeps cancellation behind authentication', function (): void {
    $this->delete(route('appointments.destroy', $this->appointment))
        ->assertRedirect(route('login'));
});
