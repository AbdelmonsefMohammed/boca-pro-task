<?php

use App\Data\EventDetails;
use App\Exceptions\CalendarAuthExpired;
use App\Exceptions\CalendarRequestRejected;
use App\Exceptions\CalendarUnavailable;
use App\Exceptions\EventAlreadyExists;
use App\Models\GoogleAccount;
use App\Services\Calendar\GoogleCalendarProvider;
use App\Services\Calendar\GoogleTokenRefresher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->provider = new GoogleCalendarProvider(new GoogleTokenRefresher);
    $this->account = GoogleAccount::factory()->create(['access_token' => 'stale-token']);
});

function calendarUrl(string $path): string
{
    return rtrim((string) config('services.calendar.base_url'), '/')."/{$path}";
}

function anEvent(string $id = 'abc123'): EventDetails
{
    return new EventDetails(
        id: $id,
        title: 'Consultation',
        customerName: 'Ada Lovelace',
        customerEmail: 'ada@example.com',
        startsAt: CarbonImmutable::parse('2026-06-01 09:00:00', 'UTC'),
        endsAt: CarbonImmutable::parse('2026-06-01 09:30:00', 'UTC'),
        timezone: 'Europe/Amsterdam',
    );
}

test('it returns the writable calendars google reports', function (): void {
    Http::fake([
        calendarUrl('users/me/calendarList*') => Http::response(['items' => [
            ['id' => 'primary', 'summary' => 'Personal', 'timeZone' => 'UTC', 'primary' => true, 'accessRole' => 'owner'],
            ['id' => 'work@example.com', 'summary' => 'Work', 'timeZone' => 'Europe/Amsterdam', 'accessRole' => 'writer'],
        ]]),
    ]);

    $calendars = $this->provider->listCalendars($this->account);

    expect($calendars)->toHaveCount(2)
        ->and($calendars->first()->id)->toBe('primary')
        ->and($calendars->first()->isPrimary)->toBeTrue()
        ->and($calendars->last()->timezone)->toBe('Europe/Amsterdam')
        ->and($calendars->last()->isWritable)->toBeTrue();
});

test('it sends the event id we generated so google can reject a replay', function (): void {
    Http::fake([calendarUrl('*') => Http::response([], 200)]);

    $this->provider->createEvent($this->account, 'primary', anEvent('deadbeef'));

    Http::assertSent(function ($request): bool {
        expect($request['id'])->toBe('deadbeef')
            ->and($request['start']['timeZone'])->toBe('Europe/Amsterdam')
            ->and($request['start']['dateTime'])->toStartWith('2026-06-01T11:00:00');

        return true;
    });
});

test('it refreshes the token once and retries after a 401', function (): void {
    Http::fakeSequence()
        ->push(['error' => ['message' => 'Invalid Credentials']], 401)
        ->push(['access_token' => 'fresh-token', 'expires_in' => 3600], 200)
        ->push([], 200);

    $this->provider->createEvent($this->account, 'primary', anEvent());

    expect($this->account->fresh()->access_token)->toBe('fresh-token');
    Http::assertSentCount(3);
});

test('it gives up when the refreshed token is also refused', function (): void {
    Http::fakeSequence()
        ->push(['error' => ['message' => 'Invalid Credentials']], 401)
        ->push(['access_token' => 'fresh-token', 'expires_in' => 3600], 200)
        ->push(['error' => ['message' => 'Invalid Credentials']], 401);

    expect(fn () => $this->provider->createEvent($this->account, 'primary', anEvent()))
        ->toThrow(CalendarAuthExpired::class);
});

test('it clears the stored tokens when google rejects the refresh token', function (): void {
    Http::fakeSequence()
        ->push(['error' => ['message' => 'Invalid Credentials']], 401)
        ->push(['error' => 'invalid_grant'], 400);

    expect(fn () => $this->provider->listCalendars($this->account))
        ->toThrow(CalendarAuthExpired::class);

    expect($this->account->fresh()->refresh_token)->toBeNull()
        ->and($this->account->fresh()->needsReconnect())->toBeTrue();
});

test('it reports a duplicate event id as a replay rather than a failure', function (): void {
    Http::fake([calendarUrl('*') => Http::response(['error' => ['message' => 'The requested identifier already exists.']], 409)]);

    expect(fn () => $this->provider->createEvent($this->account, 'primary', anEvent()))
        ->toThrow(EventAlreadyExists::class);
});

test('it treats deleting an already deleted event as success', function (int $status): void {
    Http::fake([calendarUrl('*') => Http::response([], $status)]);

    $this->provider->deleteEvent($this->account, 'primary', 'abc123');

    Http::assertSentCount(1);
})->with([404, 410]);

test('it maps a retryable response to a retryable exception', function (int $status): void {
    Http::fake([calendarUrl('*') => Http::response([], $status)]);

    $thrown = null;

    try {
        $this->provider->listCalendars($this->account);
    } catch (CalendarUnavailable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(CalendarUnavailable::class)
        ->and($thrown->isRetryable())->toBeTrue();
})->with([429, 500, 502, 503]);

test('it maps a connection timeout to a retryable exception', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    expect(fn () => $this->provider->listCalendars($this->account))
        ->toThrow(CalendarUnavailable::class);
});

test('it maps a rejected request to a terminal exception', function (int $status): void {
    Http::fake([calendarUrl('*') => Http::response(['error' => ['message' => 'Not Found']], $status)]);

    $thrown = null;

    try {
        $this->provider->createEvent($this->account, 'primary', anEvent());
    } catch (CalendarRequestRejected $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(CalendarRequestRejected::class)
        ->and($thrown->isRetryable())->toBeFalse();
})->with([400, 403, 404]);
