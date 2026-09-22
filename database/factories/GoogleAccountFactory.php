<?php

namespace Database\Factories;

use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GoogleAccount>
 */
class GoogleAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'access_token' => 'ya29.'.Str::random(40),
            'refresh_token' => '1//'.Str::random(40),
            'token_expires_at' => now()->addHour(),
            'selected_calendar_id' => null,
            'selected_calendar_name' => null,
        ];
    }

    /**
     * A calendar has been chosen, so the account can take bookings.
     */
    public function withSelectedCalendar(string $calendarId = 'primary'): static
    {
        return $this->state(fn (array $attributes) => [
            'selected_calendar_id' => $calendarId,
            'selected_calendar_name' => 'Work',
        ]);
    }

    /**
     * Google refused to renew the grant, so the user must reconnect.
     */
    public function needingReconnect(): static
    {
        return $this->state(fn (array $attributes) => [
            'refresh_token' => null,
            'token_expires_at' => now()->subHour(),
        ]);
    }

    public function withExpiredToken(): static
    {
        return $this->state(fn (array $attributes) => [
            'token_expires_at' => now()->subHour(),
        ]);
    }
}
