import { Form, Head } from '@inertiajs/react';
import { CalendarCheck, CalendarX, TriangleAlert } from 'lucide-react';
import GoogleConnectionController from '@/actions/App/Http/Controllers/GoogleConnectionController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { connect } from '@/routes/google';

type Connection = {
    email: string;
    needsReconnect: boolean;
    selectedCalendarId: string | null;
    selectedCalendarName: string | null;
};

type PageProps = {
    connection: Connection | null;
};

/**
 * The connect links are plain anchors, not Inertia links. Inertia visits are XHR, and
 * an XHR cannot follow the redirect to accounts.google.com: the browser blocks it as a
 * cross origin request. OAuth needs a real top level navigation.
 */
export default function Connect({ connection }: PageProps) {
    return (
        <>
            <Head title="Google Calendar" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Google Calendar"
                    description="Connect a Google account so bookings appear in your calendar."
                />

                <div className="max-w-2xl rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                    {connection === null ? (
                        <NotConnected />
                    ) : (
                        <Connected connection={connection} />
                    )}
                </div>
            </div>
        </>
    );
}

function NotConnected() {
    return (
        <div className="flex flex-col items-start gap-4">
            <div className="flex items-center gap-3">
                <CalendarX className="size-5 text-muted-foreground" />
                <div>
                    <p className="font-medium">No account connected</p>
                    <p className="text-sm text-muted-foreground">
                        You need a connected Google account before you can take
                        bookings.
                    </p>
                </div>
            </div>

            <Button asChild>
                <a href={connect.url()}>Connect Google account</a>
            </Button>
        </div>
    );
}

function Connected({ connection }: { connection: Connection }) {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center gap-3">
                <CalendarCheck className="size-5 text-muted-foreground" />
                <div>
                    <p className="font-medium">{connection.email}</p>
                    <p className="text-sm text-muted-foreground">
                        {connection.selectedCalendarName
                            ? `Booking into ${connection.selectedCalendarName}`
                            : 'No booking calendar chosen yet'}
                    </p>
                </div>
            </div>

            {connection.needsReconnect && (
                <div className="flex items-start gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-500" />
                    <p className="text-sm">
                        Google revoked access to this account. Reconnect it to
                        keep bookings in sync.
                    </p>
                </div>
            )}

            <div className="flex items-center gap-3">
                <Button
                    asChild
                    variant={connection.needsReconnect ? 'default' : 'outline'}
                >
                    <a href={connect.url()}>
                        {connection.needsReconnect
                            ? 'Reconnect'
                            : 'Reconnect a different account'}
                    </a>
                </Button>

                <Form {...GoogleConnectionController.destroy.form()}>
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="ghost"
                            disabled={processing}
                        >
                            Disconnect
                        </Button>
                    )}
                </Form>
            </div>
        </div>
    );
}
