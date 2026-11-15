import { Pagination } from '@/components/pagination';
import { RunStatusBadge } from '@/components/run-status';
import AppLayout from '@/layouts/app-layout';
import { type Execution } from '@/types/automation';
import { Head, Link } from '@inertiajs/react';

interface Props {
    executions: { data: Execution[]; links: { url: string | null; label: string; active: boolean }[] };
}

export default function Executions({ executions }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Runs', href: '/runs' }]}>
            <Head title="Runs" />
            <div className="mx-auto w-full max-w-5xl space-y-4 p-6">
                <div className="divide-y rounded-xl border">
                    {executions.data.length === 0 && <p className="text-muted-foreground p-6 text-sm">No runs yet.</p>}
                    {executions.data.map((e) => (
                        <Link key={e.id} href={`/runs/${e.id}`} className="hover:bg-muted/50 flex items-center gap-4 p-4 text-sm">
                            <span className="text-muted-foreground w-14">#{e.id}</span>
                            <span className="flex-1 font-medium">
                                {e.workflow?.name} <span className="text-muted-foreground font-normal">v{e.version?.version}</span>
                            </span>
                            <span className="text-muted-foreground">{new Date(e.created_at).toLocaleString()}</span>
                            <RunStatusBadge status={e.status} />
                        </Link>
                    ))}
                </div>
                <Pagination links={executions.links} />
            </div>
        </AppLayout>
    );
}
