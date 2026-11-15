import { NativeSelect } from '@/components/form-controls';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type Credential, type ModelDefault, PROVIDER_LABELS, type Provider, TASK_PROVIDERS, type TaskType } from '@/types/automation';
import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle2, KeyRound, RefreshCw, Trash2, XCircle } from 'lucide-react';
import { FormEvent } from 'react';

interface Props {
    credentials: Credential[];
    defaults: Partial<Record<TaskType, ModelDefault>>;
    providers: Provider[];
    taskTypes: TaskType[];
}

const TASK_LABELS: Record<TaskType, string> = {
    text: 'Text & writing',
    research: 'Research (web + connectors)',
    image: 'Image generation',
    video: 'Video generation',
};

export default function Credentials({ credentials, defaults, providers, taskTypes }: Props) {
    const keyForm = useForm({ provider: 'anthropic' as Provider, label: '', key: '' });

    const defaultsForm = useForm({
        defaults: Object.fromEntries(
            taskTypes.map((task) => [
                task,
                {
                    credential_id: defaults[task]?.credential_id ?? null,
                    model: defaults[task]?.model ?? '',
                    fallback_credential_id: defaults[task]?.fallback_credential_id ?? null,
                    fallback_model: defaults[task]?.fallback_model ?? '',
                },
            ]),
        ) as Record<TaskType, Omit<ModelDefault, 'task_type'>>,
    });

    const addKey = (e: FormEvent) => {
        e.preventDefault();
        keyForm.post('/keys', { preserveScroll: true, onSuccess: () => keyForm.reset('key', 'label') });
    };

    const saveDefaults = (e: FormEvent) => {
        e.preventDefault();
        defaultsForm.put('/model-defaults', { preserveScroll: true });
    };

    const setDefault = (task: TaskType, field: string, value: string | number | null) =>
        defaultsForm.setData('defaults', { ...defaultsForm.data.defaults, [task]: { ...defaultsForm.data.defaults[task], [field]: value } });

    const usable = (task: TaskType) => credentials.filter((c) => c.status === 'valid' && TASK_PROVIDERS[task].includes(c.provider));
    const modelsOf = (id: number | null) => credentials.find((c) => c.id === id)?.models ?? [];

    return (
        <AppLayout breadcrumbs={[{ title: 'AI keys & models', href: '/keys' }]}>
            <Head title="AI keys & models" />
            <div className="mx-auto w-full max-w-5xl space-y-10 p-6">
                <section className="space-y-4">
                    <HeadingSmall
                        title="Your API keys"
                        description="Bring your own keys. They are encrypted per workspace (AES-256-GCM) and only decrypted by the worker at the moment of a call. We only ever show the last 4 characters."
                    />

                    <div className="divide-y rounded-xl border">
                        {credentials.length === 0 && (
                            <p className="text-muted-foreground p-4 text-sm">No keys yet. Add one below to start building flows.</p>
                        )}
                        {credentials.map((credential) => (
                            <div key={credential.id} className="flex items-center gap-4 p-4">
                                <KeyRound className="text-muted-foreground size-5 shrink-0" />
                                <div className="min-w-0 flex-1">
                                    <div className="font-medium">
                                        {credential.label}{' '}
                                        <span className="text-muted-foreground font-normal">· {PROVIDER_LABELS[credential.provider]}</span>
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        ••••{credential.key_last4}
                                        {credential.status === 'valid' && ` · ${credential.models?.length ?? 0} models available`}
                                    </div>
                                    {credential.status === 'invalid' && <div className="mt-1 text-xs text-red-600">{credential.status_message}</div>}
                                </div>
                                {credential.status === 'valid' ? (
                                    <span className="flex items-center gap-1 text-xs text-emerald-600">
                                        <CheckCircle2 className="size-4" /> Working
                                    </span>
                                ) : (
                                    <span className="flex items-center gap-1 text-xs text-red-600">
                                        <XCircle className="size-4" /> Not working
                                    </span>
                                )}
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    title="Test again"
                                    onClick={() => router.post(`/keys/${credential.id}/test`, {}, { preserveScroll: true })}
                                >
                                    <RefreshCw className="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    title="Delete key"
                                    onClick={() =>
                                        confirm(`Delete "${credential.label}"? Flows using it will stop working.`) &&
                                        router.delete(`/keys/${credential.id}`, { preserveScroll: true })
                                    }
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                        ))}
                    </div>

                    <form onSubmit={addKey} className="grid gap-3 rounded-xl border p-4 md:grid-cols-[180px_1fr_2fr_auto] md:items-end">
                        <div className="space-y-1">
                            <Label htmlFor="provider">Provider</Label>
                            <NativeSelect
                                id="provider"
                                value={keyForm.data.provider}
                                onChange={(e) => keyForm.setData('provider', e.target.value as Provider)}
                            >
                                {providers.map((p) => (
                                    <option key={p} value={p}>
                                        {PROVIDER_LABELS[p]}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="label">Label</Label>
                            <Input
                                id="label"
                                placeholder="e.g. Agency account"
                                value={keyForm.data.label}
                                onChange={(e) => keyForm.setData('label', e.target.value)}
                            />
                            <InputError message={keyForm.errors.label} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="key">API key</Label>
                            <Input
                                id="key"
                                type="password"
                                autoComplete="off"
                                value={keyForm.data.key}
                                onChange={(e) => keyForm.setData('key', e.target.value)}
                            />
                            <InputError message={keyForm.errors.key} />
                        </div>
                        <Button disabled={keyForm.processing}>{keyForm.processing ? 'Testing…' : 'Add & test'}</Button>
                    </form>
                </section>

                <section className="space-y-4">
                    <HeadingSmall
                        title="Default model per task"
                        description="Nodes use these unless you pick a model on the node itself. If the first choice fails or is rate limited, the fallback runs instead."
                    />
                    <form onSubmit={saveDefaults} className="space-y-3">
                        {taskTypes.map((task) => {
                            const row = defaultsForm.data.defaults[task];
                            return (
                                <div key={task} className="grid gap-3 rounded-xl border p-4 md:grid-cols-[180px_1fr_1fr]">
                                    <div className="pt-1 text-sm font-medium">{TASK_LABELS[task]}</div>
                                    <ModelPicker
                                        label="Default"
                                        credentials={usable(task)}
                                        credentialId={row.credential_id}
                                        model={row.model ?? ''}
                                        models={modelsOf(row.credential_id)}
                                        onCredential={(id) => setDefault(task, 'credential_id', id)}
                                        onModel={(m) => setDefault(task, 'model', m)}
                                        listId={`models-${task}`}
                                    />
                                    <ModelPicker
                                        label="Fallback"
                                        credentials={usable(task)}
                                        credentialId={row.fallback_credential_id}
                                        model={row.fallback_model ?? ''}
                                        models={modelsOf(row.fallback_credential_id)}
                                        onCredential={(id) => setDefault(task, 'fallback_credential_id', id)}
                                        onModel={(m) => setDefault(task, 'fallback_model', m)}
                                        listId={`fallback-${task}`}
                                    />
                                </div>
                            );
                        })}
                        <Button disabled={defaultsForm.processing}>Save defaults</Button>
                    </form>
                </section>
            </div>
        </AppLayout>
    );
}

export function ModelPicker(props: {
    label: string;
    credentials: Credential[];
    credentialId: number | null | undefined;
    model: string;
    models: string[];
    onCredential: (id: number | null) => void;
    onModel: (model: string) => void;
    listId: string;
}) {
    return (
        <div className="space-y-1">
            <Label className="text-muted-foreground text-xs">{props.label}</Label>
            <div className="flex gap-2">
                <NativeSelect
                    className="w-40 shrink-0"
                    value={props.credentialId ?? ''}
                    onChange={(e) => props.onCredential(e.target.value ? Number(e.target.value) : null)}
                >
                    <option value="">—</option>
                    {props.credentials.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.label}
                        </option>
                    ))}
                </NativeSelect>
                <Input list={props.listId} placeholder="model id" value={props.model} onChange={(e) => props.onModel(e.target.value)} />
                <datalist id={props.listId}>
                    {props.models.map((m) => (
                        <option key={m} value={m} />
                    ))}
                </datalist>
            </div>
        </div>
    );
}
