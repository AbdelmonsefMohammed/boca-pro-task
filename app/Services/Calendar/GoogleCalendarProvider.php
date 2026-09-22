<?php

namespace App\Services\Calendar;

use App\Data\Calendar;
use App\Data\EventDetails;
use App\Exceptions\CalendarAuthExpired;
use App\Exceptions\CalendarRequestRejected;
use App\Exceptions\CalendarUnavailable;
use App\Exceptions\EventAlreadyExists;
use App\Models\GoogleAccount;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class GoogleCalendarProvider implements CalendarProvider
{
    public function __construct(private GoogleTokenRefresher $refresher) {}

    public function listCalendars(GoogleAccount $account): Collection
    {
        $response = $this->send($account, fn (PendingRequest $request): Response => $request->get(
            $this->url('users/me/calendarList'),
            ['maxResults' => 250, 'minAccessRole' => 'writer'],
        ));

        $this->guardAgainstFailure($response);

        /** @var array<int, array<string, mixed>> $items */
        $items = $response->json('items', []);

        return collect($items)->map(fn (array $entry): Calendar => Calendar::fromGoogle($entry))->values();
    }

    public function createEvent(GoogleAccount $account, string $calendarId, EventDetails $event): void
    {
        $response = $this->send($account, fn (PendingRequest $request): Response => $request->post(
            $this->url('calendars/'.rawurlencode($calendarId).'/events'),
            $event->toGooglePayload(),
        ));

        if ($response->status() === 409) {
            throw new EventAlreadyExists("Google already holds an event with id [{$event->id}].");
        }

        $this->guardAgainstFailure($response);
    }

    public function deleteEvent(GoogleAccount $account, string $calendarId, string $eventId): void
    {
        $response = $this->send($account, fn (PendingRequest $request): Response => $request->delete(
            $this->url('calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId)),
        ));

        if (in_array($response->status(), [404, 410], true)) {
            return;
        }

        $this->guardAgainstFailure($response);
    }

    /**
     * Run the call, and on a 401 refresh the token once and run it again. One retry
     * only: if a freshly minted token is still refused, the grant is genuinely dead.
     *
     * @param  Closure(PendingRequest): Response  $call
     *
     * @throws CalendarUnavailable
     * @throws CalendarAuthExpired
     */
    private function send(GoogleAccount $account, Closure $call): Response
    {
        $response = $this->attempt($account, $call);

        if ($response->status() !== 401) {
            return $response;
        }

        return $this->attempt($this->refresher->refresh($account), $call);
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     *
     * @throws CalendarUnavailable
     */
    private function attempt(GoogleAccount $account, Closure $call): Response
    {
        try {
            return $call(
                Http::withToken($account->access_token)
                    ->connectTimeout((int) config('services.calendar.connect_timeout'))
                    ->timeout((int) config('services.calendar.timeout'))
            );
        } catch (ConnectionException $exception) {
            throw new CalendarUnavailable('Could not reach Google Calendar.', previous: $exception);
        }
    }

    /**
     * Maps a response onto the retry policy in 4.2: 429 and 5xx are worth retrying,
     * 401 means the grant is dead, and every other 4xx will fail the same way again.
     *
     * @throws CalendarUnavailable
     * @throws CalendarAuthExpired
     * @throws CalendarRequestRejected
     */
    private function guardAgainstFailure(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new CalendarUnavailable("Google Calendar returned {$response->status()}.");
        }

        if ($response->status() === 401) {
            throw new CalendarAuthExpired('Google rejected the access token after a refresh.');
        }

        throw new CalendarRequestRejected(
            "Google Calendar rejected the request with {$response->status()}: ".$this->reason($response)
        );
    }

    private function reason(Response $response): string
    {
        return (string) $response->json('error.message', 'no reason given');
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.calendar.base_url'), '/').'/'.$path;
    }
}
