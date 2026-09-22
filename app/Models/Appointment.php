<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $customer_name
 * @property string $customer_email
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $timezone
 * @property CarbonImmutable|null $cancelled_at
 * @property string $calendar_id
 * @property string $external_event_id
 * @property SyncStatus $sync_status
 * @property string|null $sync_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'title',
    'customer_name',
    'customer_email',
    'starts_at',
    'ends_at',
    'timezone',
    'cancelled_at',
    'calendar_id',
    'external_event_id',
    'sync_status',
    'sync_error',
])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'sync_status' => SyncStatus::class,
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
     * Google requires event ids to be base32hex, `[a-v0-9]{5,1024}`. A UUID's hex
     * digits are `[0-9a-f]`, a subset of that alphabet, so this is both valid and
     * unique. Generating it before insert is what makes the sync job idempotent:
     * a replayed insert collides on Google's side instead of creating a second event.
     */
    public static function generateExternalEventId(): string
    {
        return Str::uuid()->getHex()->toString();
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Only these occupy a slot, so only these can block a booking.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function scheduled(Builder $query): void
    {
        $query->whereNull('cancelled_at');
    }
}
