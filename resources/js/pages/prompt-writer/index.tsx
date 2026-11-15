import { NativeSelect, Textarea } from '@/components/form-controls';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type Credential, PROVIDER_LABELS, type PromptGuide } from '@/types/automation';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Clapperboard, ImageIcon, Plus, ThumbsUp, Trash2, Wand2 } from 'lucide-react';
import { useState } from 'react';

type Task = 'image' | 'video';

interface Props {
    guides: Record<Task, PromptGuide>;
    credentials: Credential[];
    maxExamples: number;
}

const PLACEHOLDERS: Record<Task, string> = {
    image: `Write how prompts should be written for your brand. For example:
- Brand: Livora Wellness. Palette: sage green, cream, soft olive. Calm, premium, natural.
- Instagram 4:5 posts. Headline top-left in a bold serif; short supporting line below; logo bottom-left.
- Photorealistic product photography, soft daylight, shallow depth of field.
- Show people from India, natural and candid, never stock-photo poses.
- Keep on-image text under 8 words. Never invent prices or claims.`,
    video: `For example:
- Vertical 9:16, 8-10 seconds, one location.
- Open with a strong visual hook in the first 2 seconds.
- Handheld, natural light, warm grade. Light lo-fi music, no voice-over unless the brief has dialogue.
- End on the product with space for a call-to-action.`,
};

export default function PromptWriter({ guides, credentials, maxExamples }: Props) {
    const [task, setTask] = useState<Task>('image');
    const { flash } = usePage<SharedData & { flash: { success?: string | null } }>().props;

    return (
        <AppLayout breadcrumbs={[{ title: 'Prompt writer', href: '/prompt-writer' }]}>
            <Head title="Prompt writer" />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-6">
                {flash?.success && (
                    <div className="rounded-lg border border-emerald-500/40 bg-emerald-500/10 p-3 text-sm text-emerald-700 dark:text-emerald-300">
                        {flash.success}
                    </div>
                )}
                <HeadingSmall
                    title="AI prompt writer"
                    description="On Image and Video nodes you only write a short brief. This AI turns it into a detailed prompt for the image or video model, following the rules and examples you train it with here. Your characters and reference images are still added on top, unchanged."
                />

                <div className="bg-muted inline-flex gap-1 rounded-lg p-1">
                    {(
                        [
                            ['image', ImageIcon, 'Image prompts'],
                            ['video', Clapperboard, 'Video prompts'],
                        ] as const
                    ).map(([value, Icon, label]) => (
                        <button
                            key={value}
                            type="button"
                            onClick={() => setTask(value)}
                            className={cn(
                                'flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium',
                                task === value ? 'bg-background shadow-sm' : 'text-muted-foreground',
                            )}
                        >
                            <Icon className="size-4" /> {label}
                        </button>
                    ))}
                </div>

                <GuideForm key={task} task={task} guide={guides[task]} credentials={credentials} maxExamples={maxExamples} />
            </div>
        </AppLayout>
    );
}

function GuideForm({ task, guide, credentials, maxExamples }: { task: Task; guide: PromptGuide; credentials: Credential[]; maxExamples: number }) {
    const form = useForm({
        instructions: guide.instructions ?? '',
        credential_id: guide.credential_id ?? ('' as number | ''),
        model: guide.model ?? '',
        examples: guide.examples ?? [],
    });
    const models = credentials.find((c) => c.id === form.data.credential_id)?.models ?? [];

    const setExample = (i: number, patch: Partial<{ prompt: string; note: string | null }>) =>
        form.setData(
            'examples',
            form.data.examples.map((e, idx) => (idx === i ? { ...e, ...patch } : e)),
        );

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((data) => ({ ...data, credential_id: data.credential_id || null, model: data.credential_id ? data.model : null }));
                form.put(`/prompt-writer/${task}`, { preserveScroll: true });
            }}
            className="space-y-6"
        >
            <section className="space-y-2 rounded-xl border p-4">
                <Label className="flex items-center gap-1.5 text-base">
                    <Wand2 className="size-4" /> Your rules (house style)
                </Label>
                <p className="text-muted-foreground text-xs">
                    Brand, look, layout, tone, formats, what to always or never do. Plain language is fine.
                </p>
                <Textarea
                    className="min-h-48 font-sans text-sm"
                    placeholder={PLACEHOLDERS[task]}
                    value={form.data.instructions}
                    onChange={(e) => form.setData('instructions', e.target.value)}
                />
                <InputError message={form.errors.instructions} />
            </section>

            <section className="space-y-3 rounded-xl border p-4">
                <div className="flex items-center justify-between">
                    <Label className="flex items-center gap-1.5 text-base">
                        <ThumbsUp className="size-4" /> Prompts you liked ({form.data.examples.length}/{maxExamples})
                    </Label>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={form.data.examples.length >= maxExamples}
                        onClick={() => form.setData('examples', [{ prompt: '', note: null }, ...form.data.examples])}
                    >
                        <Plus className="size-4" /> Add example
                    </Button>
                </div>
                <p className="text-muted-foreground text-xs">
                    The AI copies their structure, detail and tone. Fastest way to add one: in a run's results, click{' '}
                    <strong>“Good result: use as example”</strong>.
                </p>
                {form.data.examples.length === 0 && (
                    <p className="text-muted-foreground rounded-lg border border-dashed p-4 text-center text-sm">No examples yet.</p>
                )}
                {form.data.examples.map((example, i) => (
                    <div key={i} className="space-y-2 rounded-lg border p-3">
                        <div className="flex gap-2">
                            <Input
                                className="h-8 text-xs"
                                placeholder="What you liked about it (optional)"
                                value={example.note ?? ''}
                                onChange={(e) => setExample(i, { note: e.target.value || null })}
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                title="Remove"
                                onClick={() =>
                                    form.setData(
                                        'examples',
                                        form.data.examples.filter((_, idx) => idx !== i),
                                    )
                                }
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                        <Textarea className="min-h-24" value={example.prompt} onChange={(e) => setExample(i, { prompt: e.target.value })} />
                    </div>
                ))}
                <InputError message={Object.entries(form.errors).find(([k]) => k.startsWith('examples'))?.[1]} />
            </section>

            <section className="space-y-2 rounded-xl border p-4">
                <Label className="text-base">Writer model</Label>
                <p className="text-muted-foreground text-xs">
                    Which text AI writes the prompts. A model that can see images works best, since it looks at your references.
                </p>
                <div className="flex gap-2">
                    <NativeSelect
                        className="max-w-xs"
                        value={form.data.credential_id}
                        onChange={(e) => form.setData('credential_id', e.target.value ? Number(e.target.value) : '')}
                    >
                        <option value="">Workspace default text model</option>
                        {credentials.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.label} ({PROVIDER_LABELS[c.provider]})
                            </option>
                        ))}
                    </NativeSelect>
                    {form.data.credential_id !== '' && (
                        <>
                            <Input
                                list="writer-models"
                                placeholder="model id"
                                value={form.data.model}
                                onChange={(e) => form.setData('model', e.target.value)}
                            />
                            <datalist id="writer-models">
                                {models.map((m) => (
                                    <option key={m} value={m} />
                                ))}
                            </datalist>
                        </>
                    )}
                </div>
                <InputError message={form.errors.model ?? form.errors.credential_id} />
            </section>

            <Button disabled={form.processing}>Save {task} prompt writer</Button>
        </form>
    );
}
