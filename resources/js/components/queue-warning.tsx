import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';

/** Runs and character sheets are processed by a background worker; warn when nothing is picking them up. */
export function QueueWarning() {
    const { queueStalled } = usePage<SharedData & { queueStalled?: boolean }>().props;
    if (!queueStalled) return null;

    return (
        <div className="mx-4 mt-2 flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-200">
            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
            <div>
                <strong>The background worker isn't running</strong>, so runs and character sheets are waiting in the queue. Start it with{' '}
                <code className="rounded bg-black/10 px-1 dark:bg-white/10">composer dev</code> or{' '}
                <code className="rounded bg-black/10 px-1 dark:bg-white/10">php artisan queue:work --timeout=960</code>.
            </div>
        </div>
    );
}
