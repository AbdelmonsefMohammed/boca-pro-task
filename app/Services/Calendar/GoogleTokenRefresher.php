<?php

namespace App\Services\Calendar;

use App\Exceptions\CalendarAuthExpired;
use App\Exceptions\CalendarUnavailable;
use App\Models\GoogleAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GoogleTokenRefresher
{
    public function __construct(private string $tokenEndpoint = 'https://oauth2.googleapis.com/token') {}

    /**
     * Exchange the stored refresh token for a fresh access token.
     *
     * A grant that Google has revoked comes back as `invalid_grant`. There is no
     * recovering from that without the user consenting again, so the stored tokens
     * are cleared, which is how the UI learns to show the reconnect prompt (4.5).
     *
     * @throws CalendarAuthExpired
     * @throws CalendarUnavailable
     */
    public function refresh(GoogleAccount $account): GoogleAccount
    {
        if ($account->needsReconnect()) {
            throw new CalendarAuthExpired('The Google account has no refresh token and must be reconnected.');
        }

        try {
            $response = Http::asForm()
                ->connectTimeout((int) config('services.calendar.connect_timeout'))
                ->timeout((int) config('services.calendar.timeout'))
                ->post($this->tokenEndpoint, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $account->refresh_token,
                    'client_id' => config('services.google.client_id'),
                    'client_secret' => config('services.google.client_secret'),
                ]);
        } catch (ConnectionException $exception) {
            throw new CalendarUnavailable('Could not reach Google to refresh the token.', previous: $exception);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new CalendarUnavailable("Google returned {$response->status()} while refreshing the token.");
        }

        if ($response->failed()) {
            $this->revoke($account);

            throw new CalendarAuthExpired('Google rejected the refresh token, so the account must be reconnected.');
        }

        $attributes = [
            'access_token' => $response->json('access_token'),
            'token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ];

        if ($response->json('refresh_token') !== null) {
            $attributes['refresh_token'] = $response->json('refresh_token');
        }

        $account->update($attributes);

        return $account;
    }

    private function revoke(GoogleAccount $account): void
    {
        $account->update([
            'refresh_token' => null,
            'token_expires_at' => null,
        ]);
    }
}
