<?php

namespace App\Http\Controllers;

use App\Actions\Google\ConnectGoogleAccount;
use App\Exceptions\MissingRefreshToken;
use App\Services\Calendar\GoogleTokenRevoker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class GoogleConnectionController extends Controller
{
    /**
     * Show the connection state for the signed in user.
     */
    public function show(Request $request): Response
    {
        $account = $request->user()->googleAccount;

        return Inertia::render('calendar/connect', [
            'connection' => $account === null ? null : [
                'email' => $account->email,
                'needsReconnect' => $account->needsReconnect(),
                'selectedCalendarId' => $account->selected_calendar_id,
                'selectedCalendarName' => $account->selected_calendar_name,
            ],
        ]);
    }

    /**
     * Send the user to Google for consent.
     *
     * `offline` plus `consent` is what makes Google reliably return a refresh token.
     * Without both, a returning user gets an access token only, and the grant cannot
     * be renewed once it expires.
     */
    public function create(): SymfonyRedirectResponse
    {
        $driver = Socialite::driver('google');

        if ($driver instanceof AbstractProvider) {
            return $driver
                ->scopes(config()->array('services.google.scopes'))
                ->with(['access_type' => 'offline', 'prompt' => 'consent'])
                ->redirect();
        }

        return $driver->redirect();
    }

    /**
     * Receive the consent result and store the grant.
     */
    public function store(Request $request, ConnectGoogleAccount $connect): RedirectResponse
    {
        if ($request->has('error')) {
            return $this->failWith(__('Google access was not granted, so no calendar is connected.'));
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return $this->failWith(__('We could not complete the Google sign in. Please try again.'));
        }

        if (! $googleUser instanceof SocialiteUser) {
            return $this->failWith(__('Google returned an unexpected response. Please try again.'));
        }

        try {
            $connect->handle($request->user(), $googleUser);
        } catch (MissingRefreshToken) {
            return $this->failWith(__('Google did not return a refresh token. Remove this app under your Google account permissions, then connect again.'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Google account connected.')]);

        return to_route('google.connection');
    }

    /**
     * Revoke the grant with Google and forget it locally.
     */
    public function destroy(Request $request, GoogleTokenRevoker $revoker): RedirectResponse
    {
        $account = $request->user()->googleAccount;

        if ($account === null) {
            return to_route('google.connection');
        }

        $revoker->revoke($account);

        $account->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Google account disconnected.')]);

        return to_route('google.connection');
    }

    private function failWith(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('google.connection');
    }
}
