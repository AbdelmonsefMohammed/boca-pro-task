<?php

use App\Enums\SyncStatus;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

test('it casts the sync status to an enum', function (): void {
    $appointment = Appointment::factory()->failed()->create();

    expect($appointment->fresh()->sync_status)->toBe(SyncStatus::Failed);
});

test('it defaults a new booking to pending sync', function (): void {
    $appointment = Appointment::factory()->create();

    expect($appointment->fresh()->sync_status)->toBe(SyncStatus::Pending);
});

test('it reads the booking window back as immutable dates', function (): void {
    $appointment = Appointment::factory()->create()->fresh();

    expect($appointment->starts_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($appointment->ends_at)->toBeInstanceOf(CarbonImmutable::class);
})->group('guards-date-immutability');

test('it round trips the booking window through the database unchanged', function (): void {
    $startsAt = CarbonImmutable::parse('2026-03-29 01:30:00', 'UTC');

    $appointment = Appointment::factory()->startingAt($startsAt, 45)->create([
        'timezone' => 'Europe/Amsterdam',
    ]);

    expect($appointment->fresh()->starts_at->toIso8601String())->toBe($startsAt->toIso8601String())
        ->and($appointment->fresh()->ends_at->toIso8601String())->toBe($startsAt->addMinutes(45)->toIso8601String())
        ->and($appointment->fresh()->timezone)->toBe('Europe/Amsterdam');
});

test('it treats a null cancellation timestamp as scheduled', function (): void {
    $scheduled = Appointment::factory()->create();
    $cancelled = Appointment::factory()->cancelled()->create();

    expect($scheduled->isCancelled())->toBeFalse()
        ->and($cancelled->isCancelled())->toBeTrue();
});

test('it excludes cancelled bookings from the scheduled scope', function (): void {
    $scheduled = Appointment::factory()->create();
    Appointment::factory()->cancelled()->create();

    expect(Appointment::scheduled()->pluck('id')->all())->toBe([$scheduled->id]);
});

test('it generates event ids google will accept', function (): void {
    $eventId = Appointment::generateExternalEventId();

    expect($eventId)->toMatch('/^[a-v0-9]{5,1024}$/');
});

test('it generates a distinct event id every time', function (): void {
    $eventIds = collect(range(1, 250))->map(fn (): string => Appointment::generateExternalEventId());

    expect($eventIds->unique())->toHaveCount(250);
});

test('it refuses to store two bookings under the same event id', function (): void {
    $appointment = Appointment::factory()->create();

    expect(fn () => Appointment::factory()->create(['external_event_id' => $appointment->external_event_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('it deletes bookings when the user is deleted', function (): void {
    $appointment = Appointment::factory()->create();

    $appointment->user->delete();

    $this->assertDatabaseMissing('appointments', ['id' => $appointment->id]);
});
