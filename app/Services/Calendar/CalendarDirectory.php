<?php

namespace App\Services\Calendar;

use App\Data\Calendar;
use App\Models\GoogleAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * A short lived, per account view of the calendars Google will let us book into.
 *
 * Owning the cache here keeps the key, the lifetime and the invalidation in one
 * place. Reading the list and forgetting it used to sit in different classes, which
 * is how a stale list outlives the grant that produced it.
 */
class CalendarDirectory
{
    public function __construct(private CalendarProvider $provider) {}

    /**
     * @return Collection<int, Calendar>
     */
    public function for(GoogleAccount $account): Collection
    {
        return Cache::remember(
            $this->cacheKey($account),
            now()->addMinute(),
            fn (): Collection => $this->provider->listCalendars($account),
        );
    }

    /**
     * Resolve an id against the account's own calendars. Returning null is what stops
     * a tampered form pointing bookings at a calendar this account has no claim to.
     */
    public function find(GoogleAccount $account, string $calendarId): ?Calendar
    {
        return $this->for($account)->firstWhere('id', $calendarId);
    }

    /**
     * Connecting a different Google account reuses the same row, so the cached list
     * has to go with it or the picker offers the previous account's calendars.
     */
    public function forget(GoogleAccount $account): void
    {
        Cache::forget($this->cacheKey($account));
    }

    private function cacheKey(GoogleAccount $account): string
    {
        return "google-calendars:{$account->id}";
    }
}
