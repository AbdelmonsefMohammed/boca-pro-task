<?php

namespace Database\Factories;

use App\Enums\SyncStatus;
use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = CarbonImmutable::now('UTC')->addDays(fake()->numberBetween(1, 30))->startOfHour();

        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'customer_name' => fake()->name(),
            'customer_email' => fake()->unique()->safeEmail(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
            'timezone' => 'UTC',
            'cancelled_at' => null,
            'calendar_id' => 'primary',
            'external_event_id' => Appointment::generateExternalEventId(),
            'sync_status' => SyncStatus::Pending,
            'sync_error' => null,
        ];
    }

    /**
     * Pin the booking window, which overlap tests depend on.
     */
    public function startingAt(CarbonImmutable $startsAt, int $minutes = 30): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes($minutes),
        ]);
    }

    public function onCalendar(string $calendarId): static
    {
        return $this->state(fn (array $attributes) => [
            'calendar_id' => $calendarId,
        ]);
    }

    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            'sync_status' => SyncStatus::Synced,
            'sync_error' => null,
        ]);
    }

    public function failed(string $error = 'The calendar provider is unavailable.'): static
    {
        return $this->state(fn (array $attributes) => [
            'sync_status' => SyncStatus::Failed,
            'sync_error' => $error,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'cancelled_at' => CarbonImmutable::now(),
        ]);
    }
}
