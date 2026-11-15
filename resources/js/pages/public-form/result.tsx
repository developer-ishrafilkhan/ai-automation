import { RunStatusBadge } from '@/components/run-status';
import { Button } from '@/components/ui/button';
import PublicFormLayout from '@/layouts/public-form-layout';
import { type PublicResult, type PublicResultItem } from '@/types/automation';
import { Head, Link } from '@inertiajs/react';
import { Check, Copy, Download, Loader2, RotateCcw } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    slug: string;
    token: string;
    title: string;
    formOpen: boolean;
    result: PublicResult;
}

export default function PublicFormResult({ slug, token, title, formOpen, result: initial }: Props) {
    const [result, setResult] = useState(initial);
    const finished = result.status === 'succeeded' || result.status === 'failed';

    // Poll until the run finishes; research and video can take several minutes.
    useEffect(() => {
        if (finished) return;
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(`/f/${slug}/r/${token}/status`, { headers: { Accept: 'application/json' } });
                if (response.ok) setResult(await response.json());
                else setResult((r) => ({ ...r })); // try again
            } catch {
                setResult((r) => ({ ...r }));
            }
        }, 3000);
        return () => clearTimeout(timer);
    }, [result, finished, slug, token]);

    const done = result.items.filter((i) => i.status === 'succeeded').length;

    return (
        <PublicFormLayout>
            <Head title={title} />
            <div className="space-y-6">
                <header className="bg-background space-y-3 rounded-2xl border p-6 shadow-sm">
                    <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                    {!finished && (
                        <div className="flex items-center gap-2 text-sm">
                            <Loader2 className="size-4 animate-spin" />
                            Working on it… {done}/{result.items.length} ready. You can keep this page open; results appear as soon as each part is
                            done.
                        </div>
                    )}
                    {result.status === 'succeeded' && (
                        <p className="text-sm text-emerald-700 dark:text-emerald-300">All done. Here are your results.</p>
                    )}
                    {result.failed && (
                        <p className="text-sm text-red-600">
                            Sorry, something went wrong while creating this. Anything finished is shown below. Please try again in a few minutes.
                        </p>
                    )}
                    <p className="text-muted-foreground text-xs">Save this page’s link to come back to your results.</p>
                </header>

                {result.items.map((item) => (
                    <ResultCard key={item.id} item={item} failed={result.failed} />
                ))}

                {finished && formOpen && (
                    <div className="text-center">
                        <Button variant="outline" asChild>
                            <Link href={`/f/${slug}`}>
                                <RotateCcw className="size-4" /> Fill in the form again
                            </Link>
                        </Button>
                    </div>
                )}
            </div>
        </PublicFormLayout>
    );
}

function ResultCard({ item, failed }: { item: PublicResultItem; failed: boolean }) {
    const [copied, setCopied] = useState(false);
    const waiting = item.status !== 'succeeded';

    return (
        <section className="bg-background space-y-3 rounded-2xl border p-6 shadow-sm">
            <div className="flex items-center gap-2">
                <h2 className="flex-1 font-semibold">{item.label}</h2>
                {waiting &&
                    (failed && item.status !== 'failed' ? (
                        <span className="text-muted-foreground text-xs">not started</span>
                    ) : (
                        <RunStatusBadge status={item.status === 'pending' ? 'queued' : item.status} />
                    ))}
                {item.text && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={async () => {
                            await navigator.clipboard.writeText(item.text ?? '');
                            setCopied(true);
                            setTimeout(() => setCopied(false), 1500);
                        }}
                    >
                        {copied ? <Check className="size-4" /> : <Copy className="size-4" />} Copy
                    </Button>
                )}
                {item.url && (
                    <Button size="sm" variant="ghost" asChild>
                        <a href={item.url} download>
                            <Download className="size-4" /> Download
                        </a>
                    </Button>
                )}
            </div>

            {waiting && !item.text && !item.url && (
                <div className="bg-muted flex h-24 animate-pulse items-center justify-center rounded-lg text-sm" aria-label="In progress">
                    {item.progress && !failed && `Scene ${item.progress.done} of ${item.progress.total} ready…`}
                </div>
            )}
            {item.kind === 'image' && item.url && <img src={item.url} alt={item.label} className="w-full rounded-lg border" />}
            {item.kind === 'video' && item.url && <video src={item.url} controls playsInline className="w-full rounded-lg border" />}
            {item.text && <div className="text-sm leading-relaxed whitespace-pre-wrap">{item.text}</div>}
            {item.citations.length > 0 && (
                <div className="space-y-1 border-t pt-3">
                    <div className="text-muted-foreground text-xs font-medium">Sources</div>
                    <ol className="list-decimal space-y-0.5 pl-5 text-xs">
                        {item.citations.map((c, i) => (
                            <li key={i}>
                                <a href={c.url} target="_blank" rel="noreferrer nofollow" className="underline">
                                    {c.title || c.url}
                                </a>
                            </li>
                        ))}
                    </ol>
                </div>
            )}
        </section>
    );
}
