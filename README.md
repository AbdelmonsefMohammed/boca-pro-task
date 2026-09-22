# Boca Pro: Calendar Booking

A small booking application. A signed in user connects a Google account, chooses one of its
calendars, and books appointments that appear both in the app and on that Google Calendar.
Cancelling removes the event again.

Built with Laravel 13, Inertia v3, React 19 and MySQL, against the real Google Calendar API.

---

## Setup

### Requirements

PHP 8.4, Composer, Node 20 or newer, MySQL 8.

### 1. Install

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

### 2. Databases

Two are needed: one for the app, one for the test suite.

```sql
CREATE DATABASE bocapro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE bocapro_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Set `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in `.env`, then:

```bash
php artisan migrate
```

The test database name is fixed in `phpunit.xml`. The suite runs against MySQL rather than
SQLite on purpose, explained under [Testing](#testing).

### 3. Google credentials

In the [Google Cloud Console](https://console.cloud.google.com):

1. **Enable the API.** APIs and Services, then Library, then search for "Google Calendar API"
   and enable it. Nothing works without this and the resulting error is not obvious.
2. **Configure the OAuth consent screen.** User type External. Add these two scopes:
   * `https://www.googleapis.com/auth/calendar.calendarlist.readonly`
   * `https://www.googleapis.com/auth/calendar.events`
   Then add every Google account you intend to sign in with under **Test users**. While the
   consent screen is in Testing status, any account not on that list is refused with
   `Error 403: access_denied`.
3. **Create the client.** Credentials, then Create Credentials, then OAuth client ID, with
   application type **Web application**. Add exactly this authorized redirect URI:

   ```
   http://localhost:8000/google/callback
   ```

   It must match byte for byte or Google returns `redirect_uri_mismatch`.

Put the result in `.env`:

```dotenv
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/google/callback"
```

### 4. Run it

```bash
composer dev
```

That starts the web server on port 8000, a queue worker, and Vite together.

**The queue worker matters.** Calendar writes are pushed by a queued job, never during the
request. Without a worker, bookings save correctly but stay on "Syncing" forever and never
reach Google.

**Use `http://localhost:8000`, not `http://127.0.0.1:8000`.** Google treats them as different
hosts. Starting from `127.0.0.1` sends you to Google and returns you to `localhost`, a
different origin from the one holding your session, and the sign in fails with an unhelpful
message.

### Reviewing without Google credentials

Set `CALENDAR_DRIVER=fake` in `.env`. The whole application runs against an in memory calendar
that honours the same contract, including duplicate detection and tolerant deletes. Everything
except the OAuth handshake itself can be exercised this way.

---

## Using it

1. Register at `/register`.
2. Connect a Google account at `/google`.
3. You land on `/appointments`. Choose a booking calendar.
4. Create an appointment. Watch the badge move from "Syncing" to "On Google Calendar", then
   check the real calendar.
5. Cancel one and watch the event disappear from Google.

Worth deliberately breaking, since this is where most of the engineering went:

* Stop the queue worker and book. The appointment saves and shows "Syncing". Restart the
  worker and it syncs. Booking never fails because Google is unreachable.
* Book 09:00 to 09:30, then try 09:15 (rejected), then 09:30 (accepted, because the overlap
  check is half open).
* Book with a timezone other than your own and confirm the event lands at the right wall clock
  time in Google.

---

## Main technical decisions and trade-offs

### Calendar writes go through a queue, not the request

Booking commits to MySQL first with `sync_status = pending`, then a job pushes the event to
Google. Booking therefore never blocks on, or fails because of, a slow or unavailable provider.

The trade-off is that the UI has a brief pending state and a queue worker becomes part of the
runtime. A synchronous write would be simpler and would let the user see failure immediately,
but it would also mean Google's availability decides whether your customer gets booked.

### A provider interface, not the Google SDK

`App\Services\Calendar\CalendarProvider` declares three operations: `listCalendars`,
`createEvent`, `deleteEvent`. `GoogleCalendarProvider` implements it over Laravel's HTTP client
against Calendar v3. `FakeCalendarProvider` implements it in memory.

`google/apiclient` was avoided deliberately. It is a large dependency and hard to fake, and the
REST surface needed here is three endpoints. The cost is that pagination, partial responses and
other API details are handled by hand rather than by a maintained SDK.

The fake is not a stub. It enforces the same idempotency contract as the real provider, so a
test cannot pass against the fake in a way that would fail against Google.

### Errors are classified, not just caught

Every provider failure becomes one of four exceptions carrying `isRetryable()`:

| Exception | Cause | Retryable |
| --- | --- | --- |
| `CalendarUnavailable` | timeout, connection refused, `429`, `5xx` | yes |
| `CalendarAuthExpired` | `401` that survived a token refresh | no |
| `EventAlreadyExists` | `409`, meaning our own replay | no, treated as success |
| `CalendarRequestRejected` | other `4xx`, such as an unknown calendar | no |

The sync job asks the exception whether to retry instead of re-inspecting status codes, so the
retry policy lives in one place.

### A nullable timestamp instead of a status enum

`appointments.cancelled_at` being null means scheduled. One column carries both whether and
when, and `whereNull('cancelled_at')` is idiomatic. A third lifecycle state later would need a
real status column, which is the accepted cost.

The same reasoning removed `google_accounts.requires_reauth_at`. A null `refresh_token` already
means the grant cannot be renewed, so "needs reconnect" is derived rather than stored, which
removes a column and a UI state that could drift out of sync.

### `datetime` rather than `timestamp` for the booking window

MySQL converts `timestamp` columns between the session timezone and UTC on every read and
write. That is exactly the silent corruption the timezone handling exists to prevent, and it
caps out in 2038. `starts_at` and `ends_at` are plain `datetime` holding UTC.

`ends_at` is stored rather than derived from a duration so the overlap check is a single
indexable range comparison instead of arithmetic over candidate rows.

### The booking calendar is copied onto each appointment

`appointments.calendar_id` duplicates the account's selected calendar. This is deliberate
denormalisation: cancelling has to reach the calendar the event was actually written to, even
after the user switches calendars or disconnects.

### Actions for domain policy, services for external concerns

`CreateAppointment`, `CancelAppointment` and `ConnectGoogleAccount` hold rules worth testing
without an HTTP request. Controllers keep transport concerns: validation shape, redirects,
flash messages. `CalendarDirectory` owns the cached calendar list, including its key, lifetime
and invalidation, so nothing else touches the cache.

This was applied where there was a seam, not uniformly. Cancellation is two lines in the
controller plus an action; disconnecting is left inline because wrapping two statements in a
class adds indirection without adding clarity.

---

## Edge cases

### Two users booking the same time

Overlap is checked per calendar rather than per user, because the constraint belongs to the
calendar: two users could share one, and the same user could hold several.

MySQL has no exclusion constraint, so the guard is application level but serialised. Inside one
transaction, `CreateAppointment` takes a `lockForUpdate` row lock on the `google_accounts` row
for the target calendar, then checks for an overlapping live appointment
(`cancelled_at is null and starts_at < :end and ends_at > :start`), then inserts. The lock
funnels every concurrent booking attempt against that calendar into a queue of one, so two
requests cannot both pass the check.

The comparison is half open, so a booking ending at 09:30 does not collide with one starting at
09:30 and back to back slots stay bookable.

**Trade-off.** Serialising per calendar is correct and cheap at this scale and wrong at high
write volume, where the lock becomes a bottleneck. Per slot advisory locks would scale further
at the cost of more moving parts.

**A race we do not solve.** Someone can create a conflicting event directly in Google Calendar
between our check and our push. With more time the sync job would query Google's freebusy
endpoint before inserting and mark the appointment as conflicted rather than synced.

**Tested by** rejecting an overlap, accepting a back to back booking, ignoring cancelled
bookings, allowing the same slot on a different calendar, and asserting the `for update` query
is issued before the insert. MySQL's own locking is the database's behaviour and not ours to
test; taking the lock in the right order is ours.

### The provider is slow, unavailable, or returns an error

* Booking never waits on Google, as above.
* The HTTP client has explicit connect and response timeouts, so a hanging Google cannot hold a
  worker indefinitely.
* The sync job retries with exponential backoff (10s, 30s, 120s, 300s, capped at five attempts)
  for retryable failures only.
* Terminal failures mark the appointment `sync_status = failed` with the reason stored. **The
  booking is never discarded because Google refused it.** The row shows "Not on Google
  Calendar" with the error and a Retry button.
* A `401` triggers exactly one token refresh and one retry. If a freshly minted token is also
  refused, the grant is genuinely dead and the user is asked to reconnect.
* The calendar picker degrades on its own. If Google is unreachable, the picker shows an
  explanation while existing bookings still list normally, because bookings are local data and
  should not be taken down with the provider.
* `php artisan appointments:resync` requeues bookings whose job was lost, for example when a
  worker is killed mid run or a deploy drops what was in flight. It skips anything updated in
  the last 15 minutes so a job that is merely still backing off is not duplicated.

### The same sync operation running twice

Three independent layers:

1. **Client generated event ids.** Google Calendar accepts a caller supplied event `id`. One is
   generated before insert and stored in `external_event_id`, unique in the database. A replayed
   insert therefore collides with Google's own duplicate check and returns `409`, which is
   treated as success rather than as an error. The id is a UUID's hex digits, which are a subset
   of the base32hex alphabet Google requires.
2. **A status guard.** Each job re-reads the appointment and exits early if the work is already
   done, so a replay costs no network call at all rather than merely surviving one.
3. **`ShouldBeUnique`** keyed on the appointment id, so a double dispatch collapses to one
   queued job.

Deleting an event that is already gone returns `404` or `410`, which the provider also treats
as success. Both directions are therefore idempotent end to end.

This is also why cancellation dispatches the removal job unconditionally rather than skipping it
for appointments that never synced. Skipping raced with an in flight sync: if the sync job had
already called Google but not yet recorded the result, the cancellation would see `pending`,
skip the delete, and strand an event on the calendar. Since a `404` already counts as success,
deleting an event that was never created is harmless.

### Timezones

* **Everything in MySQL is UTC.** All comparisons, including the overlap check, are between real
  instants.
* **The IANA zone is stored per appointment.** It is the zone the booking was made in, not a
  global setting, so two bookings in different zones each display correctly.
* **Conversion happens once, at the boundary.** The form submits a date, a time, a duration and
  an explicit timezone. `StoreAppointmentRequest::startsAt()` is the only place a wall clock
  time becomes an instant.
* **Google receives RFC3339 with an explicit `timeZone` field**, so daylight saving transitions
  are resolved by Google rather than reimplemented here.
* **The list groups bookings by their own local day**, not the UTC day. A 22:00 UTC booking made
  in Tokyo appears under the following morning, which is the day the person who booked it means.

**Tested by** asserting that 14:30 in Tokyo lands at 05:30 UTC, and that the same Amsterdam
local time in February and July lands on different UTC instants, which is the daylight saving
case a naive offset would get wrong.

### Credentials and tokens for the external service

* `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` live in `.env` only, never in the repository.
* Access and refresh tokens are stored with Laravel's `encrypted` cast, so a database dump alone
  does not expose them.
* Tokens never reach the frontend. `GoogleAccount` marks them hidden and the Inertia props
  expose only the connected email and the calendar choice. There is a test asserting the token
  values do not appear in the rendered response.
* `access_type=offline` with `prompt=consent` is requested so Google reliably returns a refresh
  token.
* **A reconnect reuses the stored refresh token.** Google only issues one on first consent, so
  naively storing the response would null it out and silently break renewal at the next expiry.
* Refresh happens lazily, just before a call, when a `401` says the token is stale.
* `invalid_grant` clears the stored tokens, which is what makes the reconnect banner appear.
* Disconnecting revokes the grant with Google, then deletes the row. The revoke is best effort:
  a Google outage still removes the local grant, because the user asked to disconnect and should
  not be held connected by a third party's availability.
* The narrowest workable scopes are requested, configured in `config/services.php`, not full
  `calendar` access.

**Note for reviewers.** While the consent screen is in Testing status, Google expires refresh
tokens after seven days. The reconnect path is therefore a real operational case here, not a
theoretical one. Publishing the consent screen is the production fix.

---

## Testing

```bash
composer test-local     # rector, pint, phpstan, then the suite in parallel
php artisan test        # the suite alone
```

162 tests. Three are skipped because two factor authentication is disabled in this application.

**The suite runs against MySQL, not SQLite.** The double booking guard depends on
`lockForUpdate`, which SQLite ignores. Testing it on SQLite would pass while proving nothing.
The cost is a slower suite, measured at roughly 1.7s to 3.7s on the starter kit baseline.

**Google is never called.** Provider level tests use `Http::fake()` to exercise each mapped
status code. Higher level tests bind `FakeCalendarProvider`.

Coverage is weighted toward the risks above rather than toward a percentage: concurrency,
idempotency, timezone conversion, provider failure modes, token handling and authorisation.

Every non trivial branch was checked by mutation: the code was deliberately broken and the suite
confirmed to fail. That exercise repeatedly found tests that passed for the wrong reason, and
those tests were strengthened rather than left as false comfort. One example: removing the
already synced guard still passed the replay test, because the client generated id meant Google
rejected the duplicate anyway. The test now counts calls and asserts the second run makes none,
which is what the guard is actually for.

---

## Known gaps and what would come next

**No inbound sync from Google.** An event deleted or moved inside Google Calendar is not
reflected back, so the app can believe a slot is taken when it is free. This is the largest gap.
With more time: Google push notification channels through a webhook, incremental sync tokens,
and a periodic reconciliation job as the backstop for missed notifications.

**No freebusy check before writing.** An event created directly in Google can collide with one
of ours. The sync job would query the freebusy endpoint before inserting and mark the
appointment conflicted rather than synced.

**One Google account per user.** The schema enforces this with a unique `user_id`. Supporting
several would need a migration to add `google_account_id` back onto `appointments`, which was
removed as redundant while the limit holds.

**No availability rules.** Any time of day is bookable. Working hours, buffers between
appointments and blackout dates are the obvious next feature.

**No customer facing booking page and no notifications.** The brief scopes this to a signed in
user creating bookings, so the customer is data on an appointment rather than a participant. The
customer is written into the Google event description rather than added as an attendee, so a
test booking never emails a real address. Making them a real attendee is a one line change to
`EventDetails::toGooglePayload()`.

**No frontend tests.** The suite is server side. A CORS bug in the OAuth link, where an Inertia
visit could not follow a cross origin redirect, was found by hand and could not have been caught
by these tests. `vitest` is installed; component tests for the form wiring would be the next
addition.

**Per calendar serialisation does not scale.** See the double booking section.

**Cancelled appointments are kept.** They are never hard deleted, which preserves the audit
trail and keeps `external_event_id` unique. A retention policy would eventually be needed.
