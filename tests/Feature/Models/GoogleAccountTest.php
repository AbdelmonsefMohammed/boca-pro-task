<?php

use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

test('it stores oauth tokens encrypted at rest', function (): void {
    $account = GoogleAccount::factory()->create([
        'access_token' => 'plain-access-token',
        'refresh_token' => 'plain-refresh-token',
    ]);

    $stored = DB::table('google_accounts')->where('id', $account->id)->sole();

    expect($stored->access_token)->not->toBe('plain-access-token')
        ->and($stored->refresh_token)->not->toBe('plain-refresh-token')
        ->and(Crypt::decryptString($stored->access_token))->toBe('plain-access-token')
        ->and($account->fresh()->access_token)->toBe('plain-access-token');
});

test('it keeps oauth tokens out of the serialized model', function (): void {
    $account = GoogleAccount::factory()->create();

    expect($account->toArray())
        ->not->toHaveKey('access_token')
        ->not->toHaveKey('refresh_token')
        ->toHaveKey('email');
});

test('it reports that an account without a refresh token needs reconnecting', function (): void {
    $connected = GoogleAccount::factory()->create();
    $revoked = GoogleAccount::factory()->needingReconnect()->create();

    expect($connected->needsReconnect())->toBeFalse()
        ->and($revoked->needsReconnect())->toBeTrue();
});

test('it reports whether a booking calendar has been chosen', function (): void {
    $unselected = GoogleAccount::factory()->create();
    $selected = GoogleAccount::factory()->withSelectedCalendar()->create();

    expect($unselected->hasSelectedCalendar())->toBeFalse()
        ->and($selected->hasSelectedCalendar())->toBeTrue();
});

test('it allows only one connected account per user', function (): void {
    $user = User::factory()->create();
    GoogleAccount::factory()->for($user)->create();

    expect(fn () => GoogleAccount::factory()->for($user)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('it deletes the connected account when the user is deleted', function (): void {
    $account = GoogleAccount::factory()->create();

    $account->user->delete();

    $this->assertDatabaseMissing('google_accounts', ['id' => $account->id]);
});
