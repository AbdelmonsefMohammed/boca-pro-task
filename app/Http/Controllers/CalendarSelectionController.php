<?php

namespace App\Http\Controllers;

use App\Data\Calendar;
use App\Exceptions\CalendarAuthExpired;
use App\Exceptions\CalendarUnavailable;
use App\Http\Requests\CalendarSelectionRequest;
use App\Models\GoogleAccount;
use App\Services\Calendar\CalendarDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CalendarSelectionController extends Controller
{
    public function __construct(private CalendarDirectory $directory) {}

    /**
     * Show the calendars this account can book into.
     */
    public function edit(Request $request): Response|RedirectResponse
    {
        $account = $request->user()->googleAccount;

        if ($account === null) {
            return to_route('google.connection');
        }

        try {
            return $this->page($account, $this->directory->for($account));
        } catch (CalendarAuthExpired) {
            return to_route('google.connection');
        } catch (CalendarUnavailable $exception) {
            return $this->page($account, collect(), $exception->getMessage());
        }
    }

    /**
     * Persist which calendar bookings are written to.
     */
    public function update(CalendarSelectionRequest $request): RedirectResponse
    {
        $account = $request->user()->googleAccount;

        if ($account === null) {
            return to_route('google.connection');
        }

        try {
            $chosen = $this->directory->find($account, $request->string('calendar_id')->toString());
        } catch (CalendarAuthExpired) {
            return to_route('google.connection');
        } catch (CalendarUnavailable) {
            throw ValidationException::withMessages([
                'calendar_id' => __('Google is unavailable, so the calendar could not be confirmed. Try again shortly.'),
            ]);
        }

        if (! $chosen instanceof Calendar) {
            throw ValidationException::withMessages([
                'calendar_id' => __('That calendar is not available on the connected Google account.'),
            ]);
        }

        $account->update([
            'selected_calendar_id' => $chosen->id,
            'selected_calendar_name' => $chosen->name,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bookings will be added to :calendar.', ['calendar' => $chosen->name])]);

        return to_route('calendar.edit');
    }

    /**
     * @param  Collection<int, Calendar>  $calendars
     */
    private function page(GoogleAccount $account, Collection $calendars, ?string $error = null): Response
    {
        return Inertia::render('calendar/select', [
            'accountEmail' => $account->email,
            'calendars' => $calendars->map(fn (Calendar $calendar): array => $calendar->toArray())->all(),
            'selectedCalendarId' => $account->selected_calendar_id,
            'error' => $error,
        ]);
    }
}
