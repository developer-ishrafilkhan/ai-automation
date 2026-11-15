import { Textarea } from '@/components/form-controls';
import { RunStatusBadge } from '@/components/run-status';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { api } from '@/lib/api';
import { type Execution, type Workflow } from '@/types/automation';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Globe, Loader2, Plus, Sparkles, Workflow as WorkflowIcon } from 'lucide-react';
import { useState } from 'react';

interface Props {
    workflows: (Workflow & { executions_count: number; executions: Execution[]; form_enabled?: boolean })[];
}

const IDEAS = [
    'A form where a shop owner uploads a product photo, names the product and picks a tone, and gets an Instagram post image plus a caption with hashtags.',
    'Visitors enter a topic; research it quickly with sources, then write a 800-word SEO blog post and a cover image.',
    'A form for a 15-second vertical promo video: brand name, offer and logo upload. Write the script first, then make the video.',
];

export default function Workflows({ workflows }: Props) {
    const form = useForm({ name: '' });
    const [description, setDescription] = useState('');
    const [generating, setGenerating] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const generate = async () => {
        setGenerating(true);
        setError(null);
        try {
            const { url } = await api<{ url: string }>('POST', '/workflows/generate', { description });
            router.visit(url);
        } catch (e) {
            setError((e as Error).message);
            setGenerating(false);
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Flows', href: '/workflows' }]}>
            <Head title="Flows" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-6">
                <section className="space-y-3 rounded-xl border bg-gradient-to-br from-violet-500/5 to-sky-500/5 p-5">
                    <div className="flex items-center gap-2">
                        <Sparkles className="size-5 text-violet-500" />
                        <h2 className="font-semibold">Describe it, AI builds it</h2>
                    </div>
                    <p className="text-muted-foreground text-sm">
                        Say what should happen and what people should fill in. The AI designs the steps and a ready-to-share form; you review it on
                        the canvas before anything runs.
                    </p>
                    <Textarea
                        className="bg-background min-h-24 font-sans text-sm"
                        placeholder="e.g. A form where a café owner types today’s special and gets a square Instagram image and a caption…"
                        value={description}
                        maxLength={3000}
                        onChange={(e) => setDescription(e.target.value)}
                        disabled={generating}
                    />
                    <div className="flex flex-wrap gap-1.5">
                        {IDEAS.map((idea) => (
                            <button
                                key={idea}
                                type="button"
                                onClick={() => setDescription(idea)}
                                disabled={generating}
                                className="bg-background hover:bg-muted max-w-xs truncate rounded-full border px-2.5 py-1 text-xs"
                                title={idea}
                            >
                                {idea}
                            </button>
                        ))}
                    </div>
                    <div className="flex items-center gap-3">
                        <Button onClick={generate} disabled={generating || description.trim().length < 15}>
                            {generating ? <Loader2 className="size-4 animate-spin" /> : <Sparkles className="size-4" />}
                            {generating ? 'Designing your flow…' : 'Generate flow'}
                        </Button>
                        <span className="text-muted-foreground text-xs">Uses your default text model. Takes about 10–40 seconds.</span>
                    </div>
                    {error && <p className="text-sm text-red-600">{error}</p>}
                </section>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/workflows');
                    }}
                    className="flex gap-2"
                >
                    <Input
                        placeholder="Or start empty: new flow name, e.g. Weekly reel from topic"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <Button variant="outline" disabled={form.processing || !form.data.name}>
                        <Plus className="size-4" /> New flow
                    </Button>
                </form>

                <div className="divide-y rounded-xl border">
                    {workflows.length === 0 && (
                        <div className="text-muted-foreground flex flex-col items-center gap-2 p-10 text-center text-sm">
                            <WorkflowIcon className="size-8" />
                            No flows yet. Describe one above, or name an empty one.
                        </div>
                    )}
                    {workflows.map((workflow) => (
                        <Link key={workflow.id} href={`/workflows/${workflow.id}`} className="hover:bg-muted/50 flex items-center gap-4 p-4">
                            <WorkflowIcon className="text-muted-foreground size-5" />
                            <div className="flex-1">
                                <div className="font-medium">{workflow.name}</div>
                                <div className="text-muted-foreground text-xs">
                                    {workflow.draft_graph.nodes.length} nodes · {workflow.executions_count} runs · edited{' '}
                                    {new Date(workflow.updated_at).toLocaleString()}
                                </div>
                            </div>
                            {workflow.form_enabled && (
                                <span className="flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs text-emerald-700 dark:text-emerald-300">
                                    <Globe className="size-3" /> Public form
                                </span>
                            )}
                            {workflow.executions[0] && <RunStatusBadge status={workflow.executions[0].status} />}
                        </Link>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
