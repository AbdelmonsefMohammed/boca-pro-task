import { Form, Head } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import CalendarSelectionController from '@/actions/App/Http/Controllers/CalendarSelectionController';
import AppointmentController from '@/actions/App/Http/Controllers/AppointmentController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type SyncStatus = 'pending' | 'synced' | 'failed';

type Booking = {
    id: number;
    title: string;
    customerName: string;
    customerEmail: string;
    startsAt: string;
    endsAt: string;
    timezone: string;
    syncStatus: SyncStatus;
    syncError: string | null;
    isCancelled: boolean;
};

type Day = {
    date: string;
    bookings: Booking[];
};

type CalendarOption = {
    id: string;
    name: string;
    timezone: string;
    isPrimary: boolean;
    isWritable: boolean;
};

type PageProps = {
    calendars: CalendarOption[];
    selectedCalendarId: string | null;
    selectedCalendarName: string | null;
    calendarError: string | null;
    durations: number[];
    upcoming: Day[];
    past: Day[];
};

const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

export default function Appointments({
    calendars,
    selectedCalendarId,
    selectedCalendarName,
    calendarError,
    durations,
    upcoming,
    past,
}: PageProps) {
    return (
        <>
            <Head title="Appointments" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Appointments"
                    description={
                        selectedCalendarName
                            ? `Bookings are added to ${selectedCalendarName}.`
                            : 'Choose a calendar to start taking bookings.'
                    }
                />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
                    <div className="space-y-6">
                        <CalendarPicker
                            calendars={calendars}
                            selectedCalendarId={selectedCalendarId}
                            error={calendarError}
                        />

                        {selectedCalendarId ? (
                            <BookingForm durations={durations} />
                        ) : (
                            <p className="rounded-xl border border-dashed border-sidebar-border/70 p-6 text-sm text-muted-foreground dark:border-sidebar-border">
                                Pick a booking calendar above before adding an
                                appointment.
                            </p>
                        )}
                    </div>

                    <div className="space-y-6">
                        <BookingList title="Upcoming" days={upcoming} />
                        <BookingList title="Past" days={past} />
                    </div>
                </div>
            </div>
        </>
    );
}

function CalendarPicker({
    calendars,
    selectedCalendarId,
    error,
}: {
    calendars: CalendarOption[];
    selectedCalendarId: string | null;
    error: string | null;
}) {
    const [chosen, setChosen] = useState(selectedCalendarId ?? '');

    return (
        <div className="rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
            <h2 className="mb-4 text-sm font-medium">Booking calendar</h2>

            {error ? (
                <div className="flex items-start gap-3">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-500" />
                    <p className="text-sm text-muted-foreground">{error}</p>
                </div>
            ) : calendars.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    This Google account has no calendar you can add events to.
                </p>
            ) : (
                <Form
                    {...CalendarSelectionController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="calendar_id"
                                value={chosen}
                            />

                            <Select value={chosen} onValueChange={setChosen}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Pick a calendar" />
                                </SelectTrigger>

                                <SelectContent>
                                    {calendars.map((calendar) => (
                                        <SelectItem
                                            key={calendar.id}
                                            value={calendar.id}
                                        >
                                            {calendar.name}
                                            {calendar.isPrimary && ' (primary)'}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <InputError message={errors.calendar_id} />

                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={
                                    processing ||
                                    chosen === '' ||
                                    chosen === selectedCalendarId
                                }
                            >
                                Save calendar
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

function BookingForm({ durations }: { durations: number[] }) {
    return (
        <div className="h-fit rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
            <Form
                {...AppointmentController.store.form()}
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <Field label="Title" error={errors.title}>
                            <Input
                                name="title"
                                required
                                placeholder="Consultation"
                            />
                        </Field>

                        <Field
                            label="Customer name"
                            error={errors.customer_name}
                        >
                            <Input
                                name="customer_name"
                                required
                                placeholder="Ada Lovelace"
                            />
                        </Field>

                        <Field
                            label="Customer email"
                            error={errors.customer_email}
                        >
                            <Input
                                name="customer_email"
                                type="email"
                                required
                                placeholder="ada@example.com"
                            />
                        </Field>

                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Date" error={errors.date}>
                                <Input name="date" type="date" required />
                            </Field>

                            <Field label="Start time" error={errors.start_time}>
                                <Input name="start_time" type="time" required />
                            </Field>
                        </div>

                        <Field label="Duration" error={errors.duration_minutes}>
                            <select
                                name="duration_minutes"
                                defaultValue={30}
                                className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                            >
                                {durations.map((minutes) => (
                                    <option key={minutes} value={minutes}>
                                        {minutes} minutes
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Timezone" error={errors.timezone}>
                            <Input
                                name="timezone"
                                defaultValue={browserTimezone}
                                required
                            />
                        </Field>

                        <Button type="submit" disabled={processing}>
                            Book appointment
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function BookingList({ title, days }: { title: string; days: Day[] }) {
    return (
        <section className="space-y-4">
            <h2 className="text-sm font-medium text-muted-foreground">
                {title}
            </h2>

            {days.length === 0 ? (
                <p className="rounded-xl border border-dashed border-sidebar-border/70 p-6 text-sm text-muted-foreground dark:border-sidebar-border">
                    Nothing here yet.
                </p>
            ) : (
                days.map((day) => (
                    <div key={day.date} className="space-y-2">
                        <h3 className="border-b border-sidebar-border/70 pb-1 text-sm font-semibold dark:border-sidebar-border">
                            {day.date}
                        </h3>

                        <ul className="space-y-2">
                            {day.bookings.map((booking) => (
                                <BookingRow
                                    key={booking.id}
                                    booking={booking}
                                />
                            ))}
                        </ul>
                    </div>
                ))
            )}
        </section>
    );
}

function BookingRow({ booking }: { booking: Booking }) {
    return (
        <li
            className={`rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border ${
                booking.isCancelled ? 'opacity-60' : ''
            }`}
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="flex items-start gap-4">
                    <p className="w-28 shrink-0 font-mono text-sm tabular-nums">
                        {booking.startsAt}&ndash;{booking.endsAt}
                    </p>
                    <div>
                        <p className="font-medium">{booking.title}</p>
                        <p className="text-xs text-muted-foreground">
                            {booking.timezone}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {booking.customerName} · {booking.customerEmail}
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    <SyncBadge
                        status={booking.syncStatus}
                        isCancelled={booking.isCancelled}
                    />

                    {!booking.isCancelled && (
                        <Form
                            {...AppointmentController.destroy.form(booking.id)}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="sm"
                                    disabled={processing}
                                >
                                    Cancel
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            </div>

            {booking.syncStatus === 'failed' && (
                <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm text-amber-700 dark:text-amber-500">
                        {booking.isCancelled
                            ? 'Cancelled here, but may still be on Google Calendar: '
                            : 'Not on Google Calendar: '}
                        {booking.syncError}
                    </p>

                    <Form
                        {...AppointmentController.sync.form(booking.id)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={processing}
                            >
                                Retry sync
                            </Button>
                        )}
                    </Form>
                </div>
            )}
        </li>
    );
}

function SyncBadge({
    status,
    isCancelled,
}: {
    status: SyncStatus;
    isCancelled: boolean;
}) {
    if (isCancelled) {
        return <Badge variant="outline">Cancelled</Badge>;
    }

    const label = {
        pending: 'Syncing',
        synced: 'On Google Calendar',
        failed: 'Not synced',
    }[status];

    const variant = {
        pending: 'secondary',
        synced: 'default',
        failed: 'destructive',
    }[status] as 'secondary' | 'default' | 'destructive';

    return <Badge variant={variant}>{label}</Badge>;
}
