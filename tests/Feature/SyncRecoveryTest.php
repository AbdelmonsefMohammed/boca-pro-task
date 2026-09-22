<?php

use App\Enums\SyncStatus;
use App\Jobs\RemoveAppointmentFromCalendar;
use App\Jobs\SyncAppointmentToCalendar;
use App\Models\Appointment;
use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->account = GoogleAccount::factory()->for($this->user)
        ->withSelectedCalendar('work@example.com')
        ->create();
});

test('it requeues a failed sync and clears the error', function (): void {
    $appointment = Appointment::factory()->for($this->user)->failed('Google Calendar returned 503.')->create();

    $this->actingAs($this->user)
        ->post(route('appointments.sync', $appointment))
        ->assertRedirect(route('appointments.index'));

    expect($appointment->fresh()->sync_status)->toBe(SyncStatus::Pending)
        ->and($appointment->fresh()->sync_error)->toBeNull();

    Queue::assertPushed(SyncAppointmentToCalendar::class, 1);
});

test('it requeues a removal, not a creation, for a failed cancellation', function (): void {
    $appointment = Appointment::factory()->for($this->user)->cancelled()->failed()->create();

    $this->actingAs($this->user)->post(route('appointments.sync', $appointment));

    // Retrying the wrong direction would put a cancelled booking back on the calendar.
    Queue::assertPushed(RemoveAppointmentFromCalendar::class, 1);
    Queue::assertNotPushed(SyncAppointmentToCalendar::class);
});

test('it ignores a retry for a booking that is not failed', function (string $state): void {
    $appointment = Appointment::factory()->for($this->user)->{$state}()->create();

    $this->actingAs($this->user)->post(route('appointments.sync', $appointment));

    Queue::assertNothingPushed();
})->with(['synced', 'cancelled']);

test('it forbids retrying a booking that belongs to someone else', function (): void {
    $appointment = Appointment::factory()->failed()->create();

    $this->actingAs($this->user)
        ->post(route('appointments.sync', $appointment))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('it tells every page when the google grant has been revoked', function (): void {
    $this->account->update(['refresh_token' => null]);

    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page->where('googleNeedsReconnect', true));
});

test('it does not prompt a reconnect while the grant is healthy', function (): void {
    $this->actingAs($this->user)->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page->where('googleNeedsReconnect', false));
});

test('it requeues bookings whose sync never finished', function (): void {
    $stuck = Appointment::factory()->for($this->user)->create();

    $this->travel(1)->hour();

    $this->artisan('appointments:resync')
        ->expectsOutputToContain("Requeueing appointment [{$stuck->id}]")
        ->assertSuccessful();

    Queue::assertPushed(SyncAppointmentToCalendar::class, 1);
});

test('it leaves recent bookings alone so a running job is not duplicated', function (): void {
    Appointment::factory()->for($this->user)->create();

    $this->artisan('appointments:resync')
        ->expectsOutputToContain('Nothing to resync.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('it leaves failed bookings alone unless asked for them', function (): void {
    Appointment::factory()->for($this->user)->failed()->create();

    $this->travel(1)->hour();

    $this->artisan('appointments:resync')->assertSuccessful();
    Queue::assertNothingPushed();

    $this->artisan('appointments:resync --failed')->assertSuccessful();
    Queue::assertPushed(SyncAppointmentToCalendar::class, 1);
});

test('it requeues a stuck cancellation as a removal', function (): void {
    Appointment::factory()->for($this->user)->cancelled()->create();

    $this->travel(1)->hour();

    $this->artisan('appointments:resync')->assertSuccessful();

    Queue::assertPushed(RemoveAppointmentFromCalendar::class, 1);
    Queue::assertNotPushed(SyncAppointmentToCalendar::class);
});

test('it rate limits the booking endpoint', function (): void {
    expect(collect(Route::getRoutes()->getByName('appointments.store')->gatherMiddleware()))
        ->toContain('throttle:30,1');
});
