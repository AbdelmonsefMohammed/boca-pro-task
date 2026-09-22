<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GoogleAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $email
 * @property string $access_token
 * @property string|null $refresh_token
 * @property CarbonImmutable|null $token_expires_at
 * @property string|null $selected_calendar_id
 * @property string|null $selected_calendar_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'email',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'selected_calendar_id',
    'selected_calendar_name',
])]
#[Hidden(['access_token', 'refresh_token'])]
class GoogleAccount extends Model
{
    /** @use HasFactory<GoogleAccountFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A grant with no refresh token cannot be renewed, so the user must reconnect.
     */
    public function needsReconnect(): bool
    {
        return $this->refresh_token === null;
    }

    public function hasSelectedCalendar(): bool
    {
        return $this->selected_calendar_id !== null;
    }
}
