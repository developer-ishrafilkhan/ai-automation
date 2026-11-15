import { Textarea } from '@/components/form-controls';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { api } from '@/lib/api';
import { type FormState } from '@/types/automation';
import { Check, Copy, ExternalLink, Globe, Loader2, X } from 'lucide-react';
import { useState } from 'react';

/** Turn the flow into a public form: anyone with the link fills it in and gets the results. */
export function FormSharePanel({
    workflowId,
    form,
    fieldCount,
    beforeSave,
    onChange,
    onClose,
}: {
    workflowId: number;
    form: FormState;
    fieldCount: number;
    /** Save the draft first, so publishing includes the latest edits */
    beforeSave: () => Promise<void>;
    onChange: (form: FormState) => void;
    onClose: () => void;
}) {
    const [settings, setSettings] = useState(form.settings);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    const submit = async (enabled: boolean) => {
        setBusy(true);
        setError(null);
        try {
            await beforeSave();
            onChange(await api<FormState>('PUT', `/workflows/${workflowId}/form`, { enabled, ...settings }));
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };

    const copy = async () => {
        if (!form.url) return;
        await navigator.clipboard.writeText(form.url);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <div className="space-y-4 p-4">
            <div className="flex items-center gap-2">
                <Globe className="size-4" />
                <h2 className="flex-1 font-semibold">Public form</h2>
                <Button variant="ghost" size="icon" onClick={onClose} title="Close">
                    <X className="size-4" />
                </Button>
            </div>
            <p className="text-muted-foreground text-sm">
                Anyone with the link fills in the form and gets this flow’s results, without logging in. Runs use your AI keys, so set a daily limit.
            </p>

            {fieldCount === 0 && (
                <p className="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    Add questions on the Form step first (click the first node on the canvas).
                </p>
            )}

            {form.enabled && form.url && (
                <div className="space-y-2 rounded-lg border border-emerald-300 bg-emerald-50 p-3 dark:border-emerald-800 dark:bg-emerald-950">
                    <div className="text-xs font-medium text-emerald-800 dark:text-emerald-200">
                        Live · {form.runs_today} {form.runs_today === 1 ? 'run' : 'runs'} today
                    </div>
                    <div className="flex gap-1">
                        <Input
                            readOnly
                            value={form.url}
                            className="h-8 bg-white font-mono text-xs dark:bg-neutral-900"
                            onFocus={(e) => e.target.select()}
                        />
                        <Button size="sm" variant="outline" onClick={copy} title="Copy link">
                            {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                        </Button>
                        <Button size="sm" variant="outline" asChild title="Open form">
                            <a href={form.url} target="_blank" rel="noreferrer">
                                <ExternalLink className="size-4" />
                            </a>
                        </Button>
                    </div>
                    {form.outdated && (
                        <p className="text-xs text-amber-700 dark:text-amber-300">
                            You changed the flow since it was published. Visitors still get the published version.{' '}
                            <button className="font-medium underline" onClick={() => submit(true)} disabled={busy}>
                                Publish changes
                            </button>
                        </p>
                    )}
                </div>
            )}

            <div className="space-y-1">
                <Label>Heading</Label>
                <Input value={settings.title} onChange={(e) => setSettings({ ...settings, title: e.target.value })} />
            </div>
            <div className="space-y-1">
                <Label>Intro text</Label>
                <Textarea
                    className="min-h-16 font-sans text-sm"
                    placeholder="What visitors get, e.g. Answer three questions and get a ready-to-post Instagram image and caption."
                    value={settings.description}
                    onChange={(e) => setSettings({ ...settings, description: e.target.value })}
                />
            </div>
            <div className="grid grid-cols-2 gap-2">
                <div className="space-y-1">
                    <Label>Button text</Label>
                    <Input value={settings.submit_label} onChange={(e) => setSettings({ ...settings, submit_label: e.target.value })} />
                </div>
                <div className="space-y-1">
                    <Label>Runs per day</Label>
                    <Input
                        type="number"
                        min={1}
                        max={1000}
                        value={settings.daily_limit}
                        onChange={(e) => setSettings({ ...settings, daily_limit: Number(e.target.value) })}
                    />
                </div>
            </div>
            <p className="text-muted-foreground text-xs">
                Each visitor can submit 5 times a minute and 20 times an hour. On the results page visitors see each step’s output, except steps you
                mark “Hide from form results”. They never see prompts, models or error details.
            </p>

            {error && <p className="text-sm text-red-600">{error}</p>}

            <div className="flex gap-2">
                {form.enabled ? (
                    <>
                        <Button onClick={() => submit(true)} disabled={busy}>
                            {busy && <Loader2 className="size-4 animate-spin" />} Save & publish
                        </Button>
                        <Button variant="outline" onClick={() => submit(false)} disabled={busy}>
                            Turn off
                        </Button>
                    </>
                ) : (
                    <Button onClick={() => submit(true)} disabled={busy || fieldCount === 0}>
                        {busy ? <Loader2 className="size-4 animate-spin" /> : <Globe className="size-4" />} Publish & get link
                    </Button>
                )}
            </div>
        </div>
    );
}
