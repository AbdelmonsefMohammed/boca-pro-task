<?php

namespace App\Actions\Google;

use App\Exceptions\MissingRefreshToken;
use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Two\User as SocialiteUser;

class ConnectGoogleAccount
{
    /**
     * Persist a completed Google consent as the user's single connected account.
     *
     * @throws MissingRefreshToken
     */
    public function handle(User $user, SocialiteUser $googleUser): GoogleAccount
    {
        $existing = $user->googleAccount;
        $email = (string) $googleUser->getEmail();
        $refreshToken = $this->resolveRefreshToken($googleUser, $existing, $email);

        if (blank($refreshToken)) {
            throw new MissingRefreshToken('Google returned no refresh token for this grant.');
        }

        return DB::transaction(fn (): GoogleAccount => GoogleAccount::updateOrCreate(
            ['user_id' => $user->id],
            [
                'email' => $email,
                'access_token' => $googleUser->token,
                'refresh_token' => $refreshToken,
                'token_expires_at' => now()->addSeconds($googleUser->expiresIn > 0 ? $googleUser->expiresIn : 3600),
                ...$this->isDifferentAccount($existing, $email)
                    ? ['selected_calendar_id' => null, 'selected_calendar_name' => null]
                    : [],
            ],
        ));
    }

    /**
     * Socialite types refreshToken as a string, but Google omits it whenever it has
     * already issued one for this grant, so it arrives empty in practice. Carrying the
     * stored one forward is what keeps a reconnect of the same account renewable.
     */
    private function resolveRefreshToken(SocialiteUser $googleUser, ?GoogleAccount $existing, string $email): ?string
    {
        if (filled($googleUser->refreshToken)) {
            return $googleUser->refreshToken;
        }

        if ($existing === null) {
            return null;
        }

        if ($existing->email !== $email) {
            return null;
        }

        return $existing->refresh_token;
    }

    /**
     * A calendar chosen under one Google account is meaningless under another, so the
     * selection is dropped rather than left pointing at something the new grant cannot see.
     */
    private function isDifferentAccount(?GoogleAccount $existing, string $email): bool
    {
        if ($existing === null) {
            return false;
        }

        return $existing->email !== $email;
    }
}
