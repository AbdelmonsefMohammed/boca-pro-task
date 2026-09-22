import { Link, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { connection } from '@/routes/google';

type SharedProps = {
    googleNeedsReconnect?: boolean;
};

/**
 * Google revoked the grant, so every sync from here on will fail. This is shared from
 * the server rather than passed per page, so the prompt follows the user around instead
 * of hiding on the connection screen they have no reason to visit.
 */
export function ReconnectBanner() {
    const { googleNeedsReconnect } = usePage<SharedProps>().props;

    if (!googleNeedsReconnect) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-amber-500/40 bg-amber-500/10 px-4 py-3">
            <div className="flex items-center gap-3">
                <TriangleAlert className="size-4 shrink-0 text-amber-600 dark:text-amber-500" />
                <p className="text-sm">
                    Google revoked access to your calendar. Bookings will not
                    sync until you reconnect.
                </p>
            </div>

            <Button asChild size="sm">
                <Link href={connection()}>Reconnect</Link>
            </Button>
        </div>
    );
}
