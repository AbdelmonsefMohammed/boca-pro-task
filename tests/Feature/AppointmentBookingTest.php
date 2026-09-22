<?php

use App\Enums\SyncStatus;
use App\Jobs\SyncAppointmentToCalendar;
use App\Models\Appointment;
use App\Models\GoogleAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->account = GoogleAccount::factory()->for($this->user)
        ->withSelectedCalendar('work@example.com')
        ->create();
});

function booking(array $overrides = []): array
{
    return [
        'title' => 'Consultation',
        'customer_name' => 'Ada Lovelace',
        'customer_email' => 'ada@example.com',
        'date' => CarbonImmutable::now('UTC')->addWeek()->format('Y-m-d'),
        'start_time' => '09:00',
        'duration_minutes' => 30,
        'timezone' => 'UTC',
        ...$overrides,
    ];
}

test('it books a slot and queues the calendar sync', function (): void {
    $this->actingAs($this->user)
        ->post(route('appointments.store'), booking())
        ->assertRedirect(route('appointments.index'))
        ->assertSessionHasNoErrors();

    $appointment = Appointment::sole();

    expect($appointment->title)->toBe('Consultation')
        ->and($appointment->customer_email)->toBe('ada@example.com')
        ->and($appointment->calendar_id)->toBe('work@example.com')
        ->and($appointment->sync_status)->toBe(SyncStatus::Pending)
        ->and($appointment->external_event_id)->toMatch('/^[a-v0-9]{5,1024}$/');

    Queue::assertPushed(SyncAppointmentToCalendar::class, 1);
});

test('it converts the submitted wall clock time to the right utc instant', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'));

    $this->actingAs($this->user)->post(route('appointments.store'), booking([
        'date' => '2026-07-15',
        'start_time' => '14:30',
        'timezone' => 'Asia/Tokyo',
        'duration_minutes' => 45,
    ]));

    $appointment = Appointment::sole();

    // 14:30 in Tokyo (UTC+9) is 05:30 UTC on the same day.
    expect($appointment->starts_at->toIso8601String())->toBe('2026-07-15T05:30:00+00:00')
        ->and($appointment->ends_at->toIso8601String())->toBe('2026-07-15T06:15:00+00:00')
        ->and($appointment->timezone)->toBe('Asia/Tokyo');
});

test('it keeps the local wall clock time across a daylight saving shift', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-01-01 00:00:00', 'UTC'));

    // Amsterdam is UTC+1 in February and UTC+2 in July, so the same local time
    // must land on different UTC instants.
    $this->actingAs($this->user)->post(route('appointments.store'), booking([
        'date' => '2027-02-10', 'start_time' => '10:00', 'timezone' => 'Europe/Amsterdam',
    ]));
    $this->actingAs($this->user)->post(route('appointments.store'), booking([
        'date' => '2027-07-10', 'start_time' => '10:00', 'timezone' => 'Europe/Amsterdam',
    ]));

    $instants = Appointment::orderBy('starts_at')->pluck('starts_at')
        ->map(fn (CarbonImmutable $starts): string => $starts->format('H:i'))->all();

    expect($instants)->toBe(['09:00', '08:00']);
});

test('it rejects a booking that overlaps a live one on the same calendar', function (): void {
    $startsAt = CarbonImmutable::now('UTC')->addWeek()->setTime(9, 0);
    Appointment::factory()->for($this->user)->onCalendar('work@example.com')
        ->startingAt($startsAt, 60)->create();

    $this->actingAs($this->user)
        ->post(route('appointments.store'), booking([
            'date' => $startsAt->format('Y-m-d'),
            'start_time' => '09:30',
        ]))
        ->assertSessionHasErrors('start_time');

    expect(Appointment::count())->toBe(1);
    Queue::assertNothingPushed();
});

test('it allows a booking that starts exactly when another ends', function (): void {
    $startsAt = CarbonImmutable::now('UTC')->addWeek()->setTime(9, 0);
    Appointment::factory()->for($this->user)->onCalendar('work@example.com')
        ->startingAt($startsAt, 30)->create();

    $this->actingAs($this->user)
        ->post(route('appointments.store'), booking([
            'date' => $startsAt->format('Y-m-d'),
            'start_time' => '09:30',
        ]))
        ->assertSessionHasNoErrors();

    expect(Appointment::count())->toBe(2);
});

test('it ignores a cancelled booking when checking for an overlap', function (): void {
    $startsAt = CarbonImmutable::now('UTC')->addWeek()->setTime(9, 0);
    Appointment::factory()->for($this->user)->onCalendar('work@example.com')
        ->startingAt($startsAt, 60)->cancelled()->create();

    $this->actingAs($this->user)
        ->post(route('appointments.store'), booking([
            'date' => $startsAt->format('Y-m-d'),
            'start_time' => '09:00',
        ]))
        ->assertSessionHasNoErrors();

    expect(Appointment::whereNull('cancelled_at')->count())->toBe(1);
});

test('it allows the same slot on a different calendar', function (): void {
    $startsAt = CarbonImmutable::now('UTC')->addWeek()->setTime(9, 0);
    Appointment::factory()->onCalendar('someone-else@example.com')
        ->startingAt($startsAt, 60)->create();

    $this->actingAs($this->user)
        ->post(route('appointments.store'), booking([
            'date' => $startsAt->format('Y-m-d'),
            'start_time' => '09:00',
        ]))
        ->assertSessionHasNoErrors();

    expect(Appointment::count())->toBe(2);
});

test('it locks the calendar row before checking for overlaps', function (): void {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($this->user)->post(route('appointments.store'), booking());

    $lockAt = collect($queries)->search(
        fn (string $sql): bool => str_contains($sql, 'google_accounts') && str_contains($sql, 'for update'),
    );
    $insertAt = collect($queries)->search(
        fn (string $sql): bool => str_contains($sql, 'insert into `appointments`'),
    );

    // Without the lock preceding the insert, two concurrent requests could both pass
    // the overlap check and double book the slot (4.1).
    expect($lockAt)->not->toBeFalse()
        ->and($insertAt)->not->toBeFalse()
        ->and($lockAt)->toBeLessThan($insertAt);
});

test('it rejects a start time in the past', function (): void {
    $this->actingAs($this->user)
        ->post(route('appointments.store'), booking([
            'date' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d'),
        ]))
        ->assertSessionHasErrors('start_time');

    expect(Appointment::count())->toBe(0);
});

test('it refuses to book without a chosen calendar', function (): void {
    $this->account->update(['selected_calendar_id' => null, 'selected_calendar_name' => null]);

    $this->actingAs($this->user)
        ->post(route('appointments.store'), booking())
        ->assertSessionHasErrors('title');

    expect(Appointment::count())->toBe(0);
});

test('it validates the booking form', function (array $payload, string $field): void {
    $this->actingAs($this->user)
        ->post(route('appointments.store'), booking($payload))
        ->assertSessionHasErrors($field);
})->with([
    'missing title' => [['title' => ''], 'title'],
    'bad customer email' => [['customer_email' => 'not-an-email'], 'customer_email'],
    'unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'duration off the list' => [['duration_minutes' => 37], 'duration_minutes'],
    'malformed date' => [['date' => '15-07-2026'], 'date'],
    'malformed time' => [['start_time' => '9am'], 'start_time'],
]);

test('it lists bookings split into upcoming and past', function (): void {
    Appointment::factory()->for($this->user)
        ->startingAt(CarbonImmutable::now('UTC')->addWeek(), 30)->create(['title' => 'Later']);
    Appointment::factory()->for($this->user)
        ->startingAt(CarbonImmutable::now('UTC')->subWeek(), 30)->create(['title' => 'Earlier']);

    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('appointments/index')
            ->where('selectedCalendarName', 'Work')
            ->has('upcoming', 1)
            ->where('upcoming.0.bookings.0.title', 'Later')
            ->has('past', 1)
            ->where('past.0.bookings.0.title', 'Earlier')
        );
});

test('it groups bookings under the day they fall on', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-01 00:00:00', 'UTC'));

    $day = CarbonImmutable::parse('2027-03-10 09:00:00', 'UTC');
    Appointment::factory()->for($this->user)->startingAt($day, 30)->create(['title' => 'Morning']);
    Appointment::factory()->for($this->user)->startingAt($day->addHours(5), 30)->create(['title' => 'Afternoon']);
    Appointment::factory()->for($this->user)->startingAt($day->addDay(), 30)->create(['title' => 'Next day']);

    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page
            ->has('upcoming', 2)
            ->where('upcoming.0.date', 'Wednesday 10 March 2027')
            ->has('upcoming.0.bookings', 2)
            ->where('upcoming.0.bookings.0.startsAt', '09:00')
            ->where('upcoming.0.bookings.1.startsAt', '14:00')
            ->where('upcoming.1.date', 'Thursday 11 March 2027')
            ->has('upcoming.1.bookings', 1)
        );
});

test('it groups a booking under its own local day, not utc', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-01 00:00:00', 'UTC'));

    // 22:00 UTC on the 10th is 07:00 on the 11th in Tokyo.
    Appointment::factory()->for($this->user)
        ->startingAt(CarbonImmutable::parse('2027-03-10 22:00:00', 'UTC'), 30)
        ->create(['timezone' => 'Asia/Tokyo', 'title' => 'Tokyo morning']);

    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page
            ->where('upcoming.0.date', 'Thursday 11 March 2027')
            ->where('upcoming.0.bookings.0.startsAt', '07:00')
        );
});

test('it shows a user only their own bookings', function (): void {
    Appointment::factory()->for($this->user)->create(['title' => 'Mine']);
    Appointment::factory()->create(['title' => 'Someone elses']);

    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page->has('upcoming', 1)->where('upcoming.0.bookings.0.title', 'Mine'));
});

test('it offers the picker in place of the form when no calendar is chosen', function (): void {
    $this->account->update(['selected_calendar_id' => null, 'selected_calendar_name' => null]);

    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('appointments/index')
            ->where('selectedCalendarId', null)
            ->has('calendars')
        );
});

test('it keeps the booking routes behind authentication', function (string $method, string $route): void {
    $this->{$method}(route($route))->assertRedirect(route('login'));
})->with([
    ['get', 'appointments.index'],
    ['post', 'appointments.store'],
]);
