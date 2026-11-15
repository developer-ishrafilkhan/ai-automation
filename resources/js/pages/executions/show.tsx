import { RunStatusBadge } from '@/components/run-status';
import { StepDetail } from '@/components/step-detail';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { api } from '@/lib/api';
import { type Execution } from '@/types/automation';
import { Head, Link, router } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { useEffect } from 'react';

function duration(from: string | null, to: string | null) {
    if (!from) return '';
    const ms = (to ? new Date(to) : new Date()).getTime() - new Date(from).getTime();
    return ms < 60000 ? `${(ms / 1000).toFixed(1)}s` : `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
}

export default function ExecutionShow({ execution }: { execution: Execution }) {
    const finished = ['succeeded', 'failed'].includes(execution.status);

    useEffect(() => {
        if (finished) return;
        const timer = setTimeout(() => router.reload({ only: ['execution'] }), 2000);
        return () => clearTimeout(timer);
    }, [execution, finished]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Runs', href: '/runs' },
                { title: `#${execution.id}`, href: `/runs/${execution.id}` },
            ]}
        >
            <Head title={`Run #${execution.id}`} />
            <div className="mx-auto w-full max-w-4xl space-y-4 p-6">
                <div className="flex flex-wrap items-center gap-3">
                    <h1 className="text-lg font-semibold">
                        <Link href={`/workflows/${execution.workflow?.id}`} className="hover:underline">
                            {execution.workflow?.name}
                        </Link>{' '}
                        <span className="text-muted-foreground font-normal">v{execution.version?.version}</span>
                    </h1>
                    <RunStatusBadge status={execution.status} />
                    <span className="text-muted-foreground text-sm">{duration(execution.started_at, execution.finished_at)}</span>
                    {execution.status === 'failed' && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="ml-auto"
                            onClick={async () => {
                                await api('POST', `/runs/${execution.id}/retry`);
                                router.reload();
                            }}
                        >
                            <RotateCcw className="size-4" /> Re-run from failed step
                        </Button>
                    )}
                </div>
                {execution.error && <p className="text-sm text-red-600">{execution.error}</p>}

                {(execution.steps ?? []).map((step) => (
                    <div key={step.id} className="space-y-2 rounded-xl border p-4">
                        <div className="flex items-center gap-2">
                            <span className="font-mono text-sm">{step.node_id}</span>
                            <span className="text-muted-foreground text-xs">{step.node_type}</span>
                            <span className="text-muted-foreground ml-auto text-xs">{duration(step.started_at, step.finished_at)}</span>
                            <RunStatusBadge status={step.status} />
                        </div>
                        <StepDetail step={step} />
                        {step.input && (
                            <details className="text-xs">
                                <summary className="text-muted-foreground cursor-pointer">Input</summary>
                                <pre className="bg-muted mt-1 overflow-x-auto rounded p-2">{JSON.stringify(step.input, null, 2)}</pre>
                            </details>
                        )}
                        {step.output && (
                            <details className="text-xs">
                                <summary className="text-muted-foreground cursor-pointer">Output</summary>
                                <pre className="bg-muted mt-1 overflow-x-auto rounded p-2">{JSON.stringify(step.output, null, 2)}</pre>
                            </details>
                        )}
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
