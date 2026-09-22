<?php

use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Support\SessionKey;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response([], 200)]);
});

test('it asks google for offline access so the grant can be renewed', function (): void {
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-client-secret',
        'services.google.redirect' => 'http://localhost/google/callback',
    ]);

    $response = $this->actingAs($this->user)->get(route('google.connect'));

    $response->assertRedirect();

    $location = urldecode((string) $response->headers->get('Location'));

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/auth')
        ->toContain('access_type=offline')
        ->toContain('prompt=consent')
        ->toContain('https://www.googleapis.com/auth/calendar.events')
        ->toContain('https://www.googleapis.com/auth/calendar.calendarlist.readonly');
});

test('it stores the grant when the user consents', function (): void {
    Socialite::fake('google', SocialiteUser::fake([
        'email' => 'owner@example.com',
        'token' => 'access-123',
        'refreshToken' => 'refresh-123',
        'expiresIn' => 3600,
    ]));

    $this->actingAs($this->user)->get(route('google.callback'))
        ->assertRedirect(route('appointments.index'));

    $account = $this->user->fresh()->googleAccount;

    expect($account->email)->toBe('owner@example.com')
        ->and($account->access_token)->toBe('access-123')
        ->and($account->refresh_token)->toBe('refresh-123')
        ->and($account->token_expires_at)->not->toBeNull();
});

test('it updates the existing row instead of creating a second one on reconnect', function (): void {
    GoogleAccount::factory()->for($this->user)->withSelectedCalendar('work@example.com')->create([
        'email' => 'owner@example.com',
        'access_token' => 'old-access',
    ]);

    Socialite::fake('google', SocialiteUser::fake([
        'email' => 'owner@example.com',
        'token' => 'new-access',
        'refreshToken' => 'new-refresh',
    ]));

    $this->actingAs($this->user)->get(route('google.callback'));

    expect(GoogleAccount::where('user_id', $this->user->id)->count())->toBe(1)
        ->and($this->user->fresh()->googleAccount->access_token)->toBe('new-access')
        ->and($this->user->fresh()->googleAccount->selected_calendar_id)->toBe('work@example.com');
});

test('it forgets the chosen calendar when a different google account is connected', function (): void {
    GoogleAccount::factory()->for($this->user)->withSelectedCalendar('work@example.com')->create([
        'email' => 'old@example.com',
    ]);

    Socialite::fake('google', SocialiteUser::fake([
        'email' => 'different@example.com',
        'refreshToken' => 'refresh-123',
    ]));

    $this->actingAs($this->user)->get(route('google.callback'));

    $account = $this->user->fresh()->googleAccount;

    expect($account->email)->toBe('different@example.com')
        ->and($account->selected_calendar_id)->toBeNull()
        ->and($account->selected_calendar_name)->toBeNull();
});

test('it keeps the stored refresh token when google reissues only an access token', function (): void {
    GoogleAccount::factory()->for($this->user)->create([
        'email' => 'owner@example.com',
        'access_token' => 'original-access',
        'refresh_token' => 'original-refresh',
    ]);

    Socialite::fake('google', SocialiteUser::fake([
        'email' => 'owner@example.com',
        'token' => 'new-access',
        'refreshToken' => null,
    ]));

    $this->actingAs($this->user)->get(route('google.callback'));

    $account = $this->user->fresh()->googleAccount;

    expect($account->access_token)->toBe('new-access')
        ->and($account->refresh_token)->toBe('original-refresh');
});

test('it refuses a first time connection that returns no refresh token', function (): void {
    Socialite::fake('google', SocialiteUser::fake([
        'email' => 'owner@example.com',
        'refreshToken' => null,
    ]));

    $this->actingAs($this->user)->get(route('google.callback'))
        ->assertRedirect(route('google.connection'));

    expect($this->user->fresh()->googleAccount)->toBeNull();
});

test('it stores nothing when the user denies consent', function (): void {
    $this->actingAs($this->user)
        ->get(route('google.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('google.connection'))
        ->assertSessionHas(
            SessionKey::FLASH_DATA,
            fn (array $flash): bool => $flash['toast']['message'] === 'Google access was not granted, so no calendar is connected.',
        );

    expect($this->user->fresh()->googleAccount)->toBeNull();
});

test('it stores nothing when the callback cannot be completed', function (): void {
    Socialite::fake('google', fn () => throw new RuntimeException('Invalid state.'));

    $this->actingAs($this->user)->get(route('google.callback'))
        ->assertRedirect(route('google.connection'));

    expect($this->user->fresh()->googleAccount)->toBeNull();
});

test('it revokes the grant with google and forgets it on disconnect', function (): void {
    GoogleAccount::factory()->for($this->user)->create(['refresh_token' => 'refresh-123']);

    $this->actingAs($this->user)->delete(route('google.disconnect'))
        ->assertRedirect(route('google.connection'));

    expect($this->user->fresh()->googleAccount)->toBeNull();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke'
        && $request['token'] === 'refresh-123');
});

test('it still disconnects locally when google cannot be reached', function (): void {
    Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response([], 503)]);
    GoogleAccount::factory()->for($this->user)->create();

    $this->actingAs($this->user)->delete(route('google.disconnect'));

    expect($this->user->fresh()->googleAccount)->toBeNull();
});

test('it shows the connection state to the signed in user', function (): void {
    GoogleAccount::factory()->for($this->user)->withSelectedCalendar()->create([
        'email' => 'owner@example.com',
    ]);

    $this->actingAs($this->user)->get(route('google.connection'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('calendar/connect')
            ->where('connection.email', 'owner@example.com')
            ->where('connection.needsReconnect', false)
            ->where('connection.selectedCalendarName', 'Work')
        );
});

test('it never exposes oauth tokens to the frontend', function (): void {
    GoogleAccount::factory()->for($this->user)->create([
        'access_token' => 'secret-access',
        'refresh_token' => 'secret-refresh',
    ]);

    $response = $this->actingAs($this->user)->get(route('google.connection'));

    expect($response->getContent())
        ->not->toContain('secret-access')
        ->not->toContain('secret-refresh');
});

test('it keeps the connection pages behind authentication', function (string $method, string $route): void {
    $this->{$method}(route($route))->assertRedirect(route('login'));
})->with([
    ['get', 'google.connection'],
    ['get', 'google.connect'],
    ['get', 'google.callback'],
    ['delete', 'google.disconnect'],
]);
