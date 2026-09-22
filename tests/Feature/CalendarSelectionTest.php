<?php

use App\Data\Calendar;
use App\Exceptions\CalendarAuthExpired;
use App\Exceptions\CalendarUnavailable;
use App\Models\Appointment;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Calendar\CalendarProvider;
use App\Services\Calendar\FakeCalendarProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function (): void {
    $this->calendars = new FakeCalendarProvider;
    $this->app->instance(CalendarProvider::class, $this->calendars);

    $this->user = User::factory()->create();
    $this->account = GoogleAccount::factory()->for($this->user)->create();
});

test('it lists the calendars the connected account can book into', function (): void {
    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('appointments/index')
            ->where('calendarError', null)
            ->has('calendars', 2)
            ->where('calendars.0.id', 'primary')
            ->where('calendars.1.timezone', 'Europe/Amsterdam')
        );
});

test('it persists the chosen calendar', function (): void {
    $this->actingAs($this->user)
        ->put(route('calendar.update'), ['calendar_id' => 'work@example.com'])
        ->assertRedirect(route('appointments.index'))
        ->assertSessionHasNoErrors();

    $account = $this->account->fresh();

    expect($account->selected_calendar_id)->toBe('work@example.com')
        ->and($account->selected_calendar_name)->toBe('Work')
        ->and($account->hasSelectedCalendar())->toBeTrue();
});

test('it rejects a calendar the connected account does not have', function (): void {
    $this->actingAs($this->user)
        ->put(route('calendar.update'), ['calendar_id' => 'someone-elses@example.com'])
        ->assertSessionHasErrors([
            'calendar_id' => 'That calendar is not available on the connected Google account.',
        ]);

    expect($this->account->fresh()->selected_calendar_id)->toBeNull();
});

test('it requires a calendar id', function (): void {
    $this->actingAs($this->user)
        ->put(route('calendar.update'), [])
        ->assertSessionHasErrors('calendar_id');
});

test('it renders the booking page with an error instead of failing when google is unavailable', function (): void {
    $this->calendars->alwaysFailWith(new CalendarUnavailable('Google Calendar returned 503.'));

    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('appointments/index')
            ->has('calendars', 0)
            ->where('calendarError', 'Google Calendar returned 503.')
        );
});

test('it refuses to save a selection it cannot confirm with google', function (): void {
    $this->calendars->alwaysFailWith(new CalendarUnavailable('Google Calendar returned 503.'));

    $this->actingAs($this->user)
        ->put(route('calendar.update'), ['calendar_id' => 'primary'])
        ->assertSessionHasErrors([
            'calendar_id' => 'Google is unavailable, so the calendar could not be confirmed. Try again shortly.',
        ]);

    expect($this->account->fresh()->selected_calendar_id)->toBeNull();
});

test('it still lists bookings when google is unreachable', function (): void {
    Appointment::factory()->for($this->user)
        ->startingAt(CarbonImmutable::now('UTC')->addWeek(), 30)
        ->create(['title' => 'Already booked']);

    $this->calendars->alwaysFailWith(new CalendarUnavailable('Google Calendar returned 503.'));

    // The picker needs Google. The bookings do not, and must not be taken down with it.
    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('calendarError', 'Google Calendar returned 503.')
            ->has('calendars', 0)
            ->where('upcoming.0.bookings.0.title', 'Already booked')
        );
});

test('it still lists bookings when the grant is dead', function (): void {
    $this->calendars->alwaysFailWith(new CalendarAuthExpired('Reconnect required.'));

    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('calendars', 0)
            ->where('calendarError', 'Google revoked access. Reconnect the account to change calendars.')
        );
});

test('it shows an empty state when the account has no writable calendars', function (): void {
    $this->calendars->withCalendars(collect());

    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('calendars', 0)->where('calendarError', null));
});

test('it sends a user with no connected account to the connect page', function (): void {
    $this->account->delete();

    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertRedirect(route('google.connection'));

    $this->actingAs($this->user)
        ->put(route('calendar.update'), ['calendar_id' => 'primary'])
        ->assertRedirect(route('google.connection'));
});

test('it caches the calendar list so a refresh does not hammer google', function (): void {
    $counting = new class extends FakeCalendarProvider
    {
        public int $calls = 0;

        public function listCalendars(GoogleAccount $account): Collection
        {
            $this->calls++;

            return parent::listCalendars($account);
        }
    };
    $this->app->instance(CalendarProvider::class, $counting);

    $this->actingAs($this->user)->get(route('appointments.index'));
    $this->actingAs($this->user)->get(route('appointments.index'));

    expect($counting->calls)->toBe(1);
});

test('it refreshes the cached list when a different google account is connected', function (): void {
    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page->where('calendars.0.id', 'primary'));

    $this->calendars->withCalendars(collect([
        new Calendar('other@example.com', 'Other', 'UTC', isPrimary: false, isWritable: true),
    ]));

    Socialite::fake('google', SocialiteUser::fake([
        'email' => 'different@example.com',
        'refreshToken' => 'refresh-123',
    ]));
    $this->actingAs($this->user)->get(route('google.callback'));

    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page
            ->has('calendars', 1)
            ->where('calendars.0.id', 'other@example.com')
        );
});

test('it caches calendars in a form that survives a serializing cache store', function (): void {
    // The array store never serializes, so it hides the failure the database and redis
    // stores hit: a cached object whose class has since moved comes back unreadable.
    config(['cache.default' => 'database']);

    $this->actingAs($this->user)->get(route('appointments.index'))->assertOk();

    $cached = Cache::store('database')->get('google-calendars:v1:'.$this->account->id);

    expect($cached)->toBeArray()
        ->and($cached[0])->toBeArray()
        ->and($cached[0]['id'])->toBe('primary');

    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('calendars.0.id', 'primary'));
});

test('it keeps another user from reading or setting this account calendar', function (): void {
    $intruder = User::factory()->create();

    $this->actingAs($intruder)->get(route('appointments.index'))
        ->assertRedirect(route('google.connection'));

    $this->actingAs($intruder)
        ->put(route('calendar.update'), ['calendar_id' => 'primary'])
        ->assertRedirect(route('google.connection'));

    expect($this->account->fresh()->selected_calendar_id)->toBeNull();
});

test('it keeps the calendar routes behind authentication', function (string $method, string $route): void {
    $this->{$method}(route($route))->assertRedirect(route('login'));
})->with([
    ['put', 'calendar.update'],
]);
