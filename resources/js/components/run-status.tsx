import { cn } from '@/lib/utils';
import { type RunStatus } from '@/types/automation';

const STYLES: Record<RunStatus, string> = {
    queued: 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    running: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    waiting: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    succeeded: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
};

export function RunStatusBadge({ status, className }: { status: RunStatus; className?: string }) {
    return <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', STYLES[status], className)}>{status}</span>;
}
