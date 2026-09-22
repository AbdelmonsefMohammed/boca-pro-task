<?php

use App\Actions\Google\ConnectGoogleAccount;
use App\Exceptions\MissingRefreshToken;
use App\Models\GoogleAccount;
use App\Models\User;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function (): void {
    $this->action = new ConnectGoogleAccount;
    $this->user = User::factory()->create();
});

function googleUser(array $attributes = []): SocialiteUser
{
    return SocialiteUser::fake([
        'email' => 'owner@example.com',
        'token' => 'access-123',
        'refreshToken' => 'refresh-123',
        'expiresIn' => 3600,
        ...$attributes,
    ]);
}

test('it stores a first time grant', function (): void {
    $account = $this->action->handle($this->user, googleUser());

    expect($account->email)->toBe('owner@example.com')
        ->and($account->access_token)->toBe('access-123')
        ->and($account->refresh_token)->toBe('refresh-123')
        ->and($account->token_expires_at)->not->toBeNull();
});

test('it carries the stored refresh token forward when google omits it', function (): void {
    GoogleAccount::factory()->for($this->user)->create([
        'email' => 'owner@example.com',
        'refresh_token' => 'original-refresh',
    ]);

    $account = $this->action->handle($this->user, googleUser([
        'token' => 'new-access',
        'refreshToken' => null,
    ]));

    expect($account->access_token)->toBe('new-access')
        ->and($account->refresh_token)->toBe('original-refresh');
});

test('it refuses a grant that can never be renewed', function (): void {
    expect(fn () => $this->action->handle($this->user, googleUser(['refreshToken' => null])))
        ->toThrow(MissingRefreshToken::class);

    expect($this->user->fresh()->googleAccount)->toBeNull();
});

test('it will not carry a refresh token across to a different google account', function (): void {
    GoogleAccount::factory()->for($this->user)->create([
        'email' => 'old@example.com',
        'refresh_token' => 'old-refresh',
    ]);

    expect(fn () => $this->action->handle($this->user, googleUser([
        'email' => 'different@example.com',
        'refreshToken' => null,
    ])))->toThrow(MissingRefreshToken::class);
});

test('it keeps the chosen calendar when the same account reconnects', function (): void {
    GoogleAccount::factory()->for($this->user)->withSelectedCalendar('work@example.com')->create([
        'email' => 'owner@example.com',
    ]);

    $account = $this->action->handle($this->user, googleUser());

    expect($account->selected_calendar_id)->toBe('work@example.com');
});

test('it drops the chosen calendar when a different account connects', function (): void {
    GoogleAccount::factory()->for($this->user)->withSelectedCalendar('work@example.com')->create([
        'email' => 'old@example.com',
    ]);

    $account = $this->action->handle($this->user, googleUser(['email' => 'different@example.com']));

    expect($account->email)->toBe('different@example.com')
        ->and($account->selected_calendar_id)->toBeNull()
        ->and($account->selected_calendar_name)->toBeNull();
});

test('it never creates a second account for the same user', function (): void {
    $this->action->handle($this->user, googleUser());
    $this->action->handle($this->user, googleUser(['token' => 'second-access']));

    expect(GoogleAccount::where('user_id', $this->user->id)->count())->toBe(1)
        ->and($this->user->fresh()->googleAccount->access_token)->toBe('second-access');
});

test('it falls back to a one hour expiry when google reports none', function (): void {
    $account = $this->action->handle($this->user, googleUser(['expiresIn' => 0]));

    expect($account->token_expires_at->diffInMinutes(now()->addHour()))->toBeLessThan(1);
});
