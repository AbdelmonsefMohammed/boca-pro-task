<?php

use App\Data\EventDetails;
use App\Exceptions\CalendarUnavailable;
use App\Exceptions\EventAlreadyExists;
use App\Models\GoogleAccount;
use App\Services\Calendar\CalendarProvider;
use App\Services\Calendar\FakeCalendarProvider;
use App\Services\Calendar\GoogleCalendarProvider;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->provider = new FakeCalendarProvider;
    $this->account = GoogleAccount::factory()->create();
});

function fakeEvent(string $id = 'abc123'): EventDetails
{
    return new EventDetails(
        id: $id,
        title: 'Consultation',
        customerName: 'Ada Lovelace',
        customerEmail: 'ada@example.com',
        startsAt: CarbonImmutable::parse('2026-06-01 09:00:00', 'UTC'),
        endsAt: CarbonImmutable::parse('2026-06-01 09:30:00', 'UTC'),
        timezone: 'UTC',
    );
}

test('it rejects a replayed event id the same way google does', function (): void {
    $this->provider->createEvent($this->account, 'primary', fakeEvent());

    expect(fn () => $this->provider->createEvent($this->account, 'primary', fakeEvent()))
        ->toThrow(EventAlreadyExists::class)
        ->and($this->provider->eventCount())->toBe(1);
});

test('it treats deleting an absent event as success', function (): void {
    $this->provider->deleteEvent($this->account, 'primary', 'never-created');

    expect($this->provider->eventCount())->toBe(0);
});

test('it removes an event that was created', function (): void {
    $this->provider->createEvent($this->account, 'primary', fakeEvent());

    $this->provider->deleteEvent($this->account, 'primary', 'abc123');

    expect($this->provider->hasEvent('primary', 'abc123'))->toBeFalse();
});

test('it keeps events on separate calendars apart', function (): void {
    $this->provider->createEvent($this->account, 'primary', fakeEvent());
    $this->provider->createEvent($this->account, 'work@example.com', fakeEvent());

    expect($this->provider->hasEvent('primary', 'abc123'))->toBeTrue()
        ->and($this->provider->hasEvent('work@example.com', 'abc123'))->toBeTrue()
        ->and($this->provider->eventCount())->toBe(2);
});

test('it can be told to fail a fixed number of times then recover', function (): void {
    $this->provider->alwaysFailWith(new CalendarUnavailable('Down for maintenance.'), times: 1);

    expect(fn () => $this->provider->createEvent($this->account, 'primary', fakeEvent()))
        ->toThrow(CalendarUnavailable::class);

    $this->provider->createEvent($this->account, 'primary', fakeEvent());

    expect($this->provider->hasEvent('primary', 'abc123'))->toBeTrue();
});

test('it resolves the google provider by default', function (): void {
    expect(app(CalendarProvider::class))->toBeInstanceOf(GoogleCalendarProvider::class);
});

test('it resolves the fake provider when the driver is set to fake', function (): void {
    config(['services.calendar.driver' => 'fake']);
    app()->forgetInstance(CalendarProvider::class);

    expect(app(CalendarProvider::class))->toBeInstanceOf(FakeCalendarProvider::class);
});
