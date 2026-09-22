<?php

namespace App\Services\Calendar;

use App\Models\GoogleAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleTokenRevoker
{
    public function __construct(private string $revokeEndpoint = 'https://oauth2.googleapis.com/revoke') {}

    /**
     * Ask Google to invalidate the grant.
     *
     * Best effort on purpose. The user asked to disconnect, so a Google outage must
     * not leave them stuck connected. A failure is logged and the local row is still
     * removed, which means the worst case is a stale grant sitting in Google's own
     * account permissions screen rather than a broken disconnect button.
     */
    public function revoke(GoogleAccount $account): bool
    {
        try {
            $response = Http::asForm()
                ->connectTimeout((int) config('services.calendar.connect_timeout'))
                ->timeout((int) config('services.calendar.timeout'))
                ->post($this->revokeEndpoint, [
                    'token' => $account->refresh_token ?? $account->access_token,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Could not reach Google to revoke a calendar grant.', [
                'google_account_id' => $account->id,
                'reason' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('Google refused to revoke a calendar grant.', [
                'google_account_id' => $account->id,
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }
}
