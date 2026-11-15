import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { type Citation, type Step } from '@/types/automation';
import { Check, ThumbsUp, Wand2 } from 'lucide-react';
import { useState } from 'react';

export function StepDetail({ step }: { step: Step }) {
    const output = step.output ?? {};
    const url = typeof output.url === 'string' ? output.url : null;

    return (
        <div className="space-y-2 text-xs">
            {step.status === 'waiting' && (
                <p className="text-amber-600">Waiting on the provider (e.g. video rendering). This resumes automatically.</p>
            )}
            {step.error && <p className="whitespace-pre-wrap text-red-600">{step.error}</p>}
            {url && step.node_type === 'ai.image' && <img src={url} alt="" className="w-full rounded border" />}
            {url && step.node_type === 'ai.video' && <video src={url} controls className="w-full rounded border" />}
            {step.progress && (
                <p className="text-muted-foreground">
                    {step.progress.done} of {step.progress.total} scenes ready
                </p>
            )}
            {typeof output.scene_count === 'number' && (
                <p className="text-muted-foreground">
                    {output.scene_count} scenes joined{typeof output.duration_seconds === 'number' && ` · ${Math.round(output.duration_seconds)} s`}
                </p>
            )}
            {Array.isArray(output.citations) && output.citations.length > 0 && (
                <details className="rounded border p-2" open={step.node_type === 'ai.research'}>
                    <summary className="cursor-pointer font-medium">{output.citations.length} sources</summary>
                    <ol className="mt-1 list-decimal space-y-0.5 pl-4">
                        {(output.citations as Citation[]).map((c) => (
                            <li key={c.url}>
                                <a href={c.url} target="_blank" rel="noreferrer noopener" className="break-all underline">
                                    {c.title}
                                </a>
                            </li>
                        ))}
                    </ol>
                </details>
            )}
            {typeof output.text === 'string' && <p className="bg-muted max-h-64 overflow-y-auto rounded p-2 whitespace-pre-wrap">{output.text}</p>}
            {typeof output.prompt_written_by === 'string' && (
                <div className="text-muted-foreground flex items-center gap-1">
                    <Wand2 className="size-3" /> Prompt written by AI ({output.prompt_written_by})
                </div>
            )}
            {typeof output.prompt_sent === 'string' && step.status === 'succeeded' && ['ai.image', 'ai.video'].includes(step.node_type) && (
                <LearnButton stepId={step.id} />
            )}
            {typeof output.prompt_sent === 'string' && (
                <details>
                    <summary className="text-muted-foreground cursor-pointer">
                        Prompt sent to the model{typeof output.reference_count === 'number' ? ` · ${output.reference_count} reference image(s)` : ''}
                    </summary>
                    <pre className="bg-muted mt-1 max-h-64 overflow-auto rounded p-2 whitespace-pre-wrap">{output.prompt_sent}</pre>
                </details>
            )}
            <div className="text-muted-foreground">
                {output.model ? `${output.provider} · ${output.model}` : null}
                {step.tokens ? ` · ${step.tokens.input} in / ${step.tokens.output} out tokens` : null}
                {step.attempts > 1 ? ` · ${step.attempts} attempts` : null}
            </div>
        </div>
    );
}

/** Teach the prompt writer from a result the user liked. */
function LearnButton({ stepId }: { stepId: number }) {
    const [state, setState] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');
    const [message, setMessage] = useState('');

    const learn = async () => {
        const note = window.prompt('What did you like about this result? (optional, helps the AI)') ?? '';
        setState('saving');
        try {
            const { examples } = await api<{ examples: number }>('POST', `/runs/steps/${stepId}/learn`, { note });
            setMessage(`Saved as example ${examples}. Future prompts will follow this style.`);
            setState('saved');
        } catch (e) {
            setMessage((e as Error).message);
            setState('error');
        }
    };

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Button
                type="button"
                size="sm"
                variant="outline"
                className="h-7 text-xs"
                disabled={state === 'saving' || state === 'saved'}
                onClick={learn}
            >
                {state === 'saved' ? <Check className="size-3.5" /> : <ThumbsUp className="size-3.5" />}
                {state === 'saved' ? 'Saved as example' : 'Good result: use as example'}
            </Button>
            {message && <span className={state === 'error' ? 'text-red-600' : 'text-muted-foreground'}>{message}</span>}
        </div>
    );
}
