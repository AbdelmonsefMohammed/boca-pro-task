import { Form, Head, Link } from '@inertiajs/react';
import { CalendarDays, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import CalendarSelectionController from '@/actions/App/Http/Controllers/CalendarSelectionController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit } from '@/routes/calendar';
import { connection } from '@/routes/google';

type CalendarOption = {
    id: string;
    name: string;
    timezone: string;
    isPrimary: boolean;
    isWritable: boolean;
};

type PageProps = {
    accountEmail: string;
    calendars: CalendarOption[];
    selectedCalendarId: string | null;
    error: string | null;
};

export default function SelectCalendar({
    accountEmail,
    calendars,
    selectedCalendarId,
    error,
}: PageProps) {
    const [chosen, setChosen] = useState(selectedCalendarId ?? '');

    return (
        <>
            <Head title="Booking calendar" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Booking calendar"
                    description={`Choose which calendar on ${accountEmail} receives your bookings.`}
                />

                <div className="max-w-2xl rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                    {error ? (
                        <Unavailable message={error} />
                    ) : calendars.length === 0 ? (
                        <NoCalendars />
                    ) : (
                        <Picker
                            calendars={calendars}
                            chosen={chosen}
                            onChoose={setChosen}
                        />
                    )}
                </div>
            </div>
        </>
    );
}

function Picker({
    calendars,
    chosen,
    onChoose,
}: {
    calendars: CalendarOption[];
    chosen: string;
    onChoose: (id: string) => void;
}) {
    return (
        <Form
            {...CalendarSelectionController.update.form()}
            options={{ preserveScroll: true }}
            className="space-y-6"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="calendar_id">Calendar</Label>

                        <input
                            type="hidden"
                            name="calendar_id"
                            value={chosen}
                        />

                        <Select value={chosen} onValueChange={onChoose}>
                            <SelectTrigger id="calendar_id" className="w-full">
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
                                        <span className="ml-2 text-muted-foreground">
                                            {calendar.timezone}
                                        </span>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <InputError message={errors.calendar_id} />
                    </div>

                    <Button
                        type="submit"
                        disabled={processing || chosen === ''}
                    >
                        Save calendar
                    </Button>
                </>
            )}
        </Form>
    );
}

function NoCalendars() {
    return (
        <div className="flex items-start gap-3">
            <CalendarDays className="mt-0.5 size-5 text-muted-foreground" />
            <div>
                <p className="font-medium">No writable calendars</p>
                <p className="text-sm text-muted-foreground">
                    This Google account has no calendar you can add events to.
                    Create one in Google Calendar, or{' '}
                    <Link
                        href={connection()}
                        className="underline underline-offset-4"
                    >
                        connect a different account
                    </Link>
                    .
                </p>
            </div>
        </div>
    );
}

function Unavailable({ message }: { message: string }) {
    return (
        <div className="flex items-start gap-3">
            <TriangleAlert className="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-500" />
            <div>
                <p className="font-medium">Could not reach Google Calendar</p>
                <p className="text-sm text-muted-foreground">{message}</p>
                <Button asChild variant="outline" className="mt-4">
                    <Link href={edit()}>Try again</Link>
                </Button>
            </div>
        </div>
    );
}
