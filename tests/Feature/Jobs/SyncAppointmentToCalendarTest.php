<?php

use App\Data\EventDetails;
use App\Enums\SyncStatus;
use App\Exceptions\CalendarRequestRejected;
use App\Exceptions\CalendarUnavailable;
use App\Jobs\SyncAppointmentToCalendar;
use App\Models\Appointment;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Calendar\CalendarProvider;
use App\Services\Calendar\FakeCalendarProvider;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->calendar = new FakeCalendarProvider;
    $this->app->instance(CalendarProvider::class, $this->calendar);

    $this->user = User::factory()->create();
    GoogleAccount::factory()->for($this->user)->withSelectedCalendar('work@example.com')->create();

    $this->appointment = Appointment::factory()->for($this->user)
        ->onCalendar('work@example.com')
        ->startingAt(CarbonImmutable::now('UTC')->addWeek()->setTime(9, 0), 30)
        ->create();
});

function runSync(Appointment $appointment): void
{
    app()->call([new SyncAppointmentToCalendar($appointment), 'handle']);
}

function eventFor(Appointment $appointment): EventDetails
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

test('it writes the booking to the chosen calendar and marks it synced', function (): void {
    runSync($this->appointment);

    expect($this->calendar->hasEvent('work@example.com', $this->appointment->external_event_id))->toBeTrue()
        ->and($this->appointment->fresh()->sync_status)->toBe(SyncStatus::Synced)
        ->and($this->appointment->fresh()->sync_error)->toBeNull();
});

test('it sends the booking window with its original timezone', function (): void {
    $appointment = Appointment::factory()->for($this->user)->onCalendar('work@example.com')
        ->startingAt(CarbonImmutable::parse('2026-07-15 05:30:00', 'UTC'), 45)
        ->create(['timezone' => 'Asia/Tokyo', 'title' => 'Consultation']);

    runSync($appointment);

    $event = $this->calendar->event('work@example.com', $appointment->external_event_id);

    expect($event->timezone)->toBe('Asia/Tokyo')
        ->and($event->title)->toBe('Consultation')
        ->and($event->toGooglePayload()['start']['dateTime'])->toStartWith('2026-07-15T14:30:00');
});

test('it creates no duplicate, and makes no second call, when the same job runs twice', function (): void {
    $counting = new class extends FakeCalendarProvider
    {
        public int $creates = 0;

        public function createEvent(GoogleAccount $account, string $calendarId, EventDetails $event): void
        {
            $this->creates++;

            parent::createEvent($account, $calendarId, $event);
        }
    };
    $this->app->instance(CalendarProvider::class, $counting);

    runSync($this->appointment);
    runSync($this->appointment);

    // Relying on the duplicate id alone would still reach Google a second time. The
    // status guard is what makes the replay free.
    expect($counting->creates)->toBe(1)
        ->and($counting->eventCount())->toBe(1)
        ->and($this->appointment->fresh()->sync_status)->toBe(SyncStatus::Synced);
});

test('it treats a duplicate event id from the provider as already done', function (): void {
    // An earlier attempt reached Google but never recorded the result locally.
    $this->calendar->createEvent(
        $this->user->googleAccount,
        'work@example.com',
        eventFor($this->appointment),
    );

    runSync($this->appointment);

    expect($this->appointment->fresh()->sync_status)->toBe(SyncStatus::Synced)
        ->and($this->appointment->fresh()->sync_error)->toBeNull()
        ->and($this->calendar->eventCount())->toBe(1);
});

test('it does nothing for a booking that was cancelled before the job ran', function (): void {
    $this->appointment->update(['cancelled_at' => now()]);

    runSync($this->appointment);

    expect($this->calendar->eventCount())->toBe(0)
        ->and($this->appointment->fresh()->sync_status)->toBe(SyncStatus::Pending);
});

test('it keeps the booking and records why when the provider rejects it outright', function (): void {
    $this->calendar->alwaysFailWith(new CalendarRequestRejected('Calendar not found.'));

    runSync($this->appointment);

    $appointment = $this->appointment->fresh();

    expect($appointment)->not->toBeNull()
        ->and($appointment->sync_status)->toBe(SyncStatus::Failed)
        ->and($appointment->sync_error)->toBe('Calendar not found.');
});

test('it rethrows a retryable failure so the queue backs off and retries', function (): void {
    $this->calendar->alwaysFailWith(new CalendarUnavailable('Google Calendar returned 503.'));

    expect(fn () => runSync($this->appointment))->toThrow(CalendarUnavailable::class);

    expect($this->appointment->fresh()->sync_status)->toBe(SyncStatus::Pending)
        ->and($this->appointment->fresh()->sync_error)->toBe('Google Calendar returned 503.');
});

test('it marks the booking failed when the google account was disconnected', function (): void {
    $this->user->googleAccount->delete();

    runSync($this->appointment->fresh());

    expect($this->appointment->fresh()->sync_status)->toBe(SyncStatus::Failed)
        ->and($this->calendar->eventCount())->toBe(0);
});

test('it backs off with increasing delays', function (): void {
    expect((new SyncAppointmentToCalendar($this->appointment))->backoff())->toBe([10, 30, 120, 300]);
});

test('it collapses a double dispatch to one queued job', function (): void {
    expect((new SyncAppointmentToCalendar($this->appointment))->uniqueId())
        ->toBe((string) $this->appointment->id);
});
