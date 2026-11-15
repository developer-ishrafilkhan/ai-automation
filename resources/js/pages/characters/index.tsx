import { NativeSelect, Textarea } from '@/components/form-controls';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { api } from '@/lib/api';
import { type SharedData } from '@/types';
import { type Character, CHARACTER_STYLE_LABELS, type Credential, PROVIDER_LABELS } from '@/types/automation';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ImagePlus, Loader2, Pencil, Plus, Sparkles, Trash2, UserRound, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Props {
    characters: Character[];
    styles: string[];
    credentials: Credential[];
}

const APPEARANCE_HINT =
    'Be specific, this text is locked into every generation. e.g. "Indian woman in her late 20s, oval face, warm brown skin, large dark-brown eyes, thick straight eyebrows, small gold nose stud on the left, shoulder-length wavy black hair with a centre parting, slim build, 5\'4". Always wears a mustard-yellow cotton kurta with white block-print, blue jeans and white sneakers."';

export default function Characters({ characters, styles, credentials }: Props) {
    const { flash } = usePage<SharedData & { flash: { success?: string | null; error?: string | null } }>().props;
    const [editing, setEditing] = useState<Character | 'new' | null>(characters.length === 0 ? 'new' : null);

    // Refresh while a character sheet is being generated.
    const generating = characters.some((c) => c.sheet_status === 'generating');
    useEffect(() => {
        if (!generating) return;
        const timer = setInterval(() => router.reload({ only: ['characters'] }), 3000);
        return () => clearInterval(timer);
    }, [generating]);

    return (
        <AppLayout breadcrumbs={[{ title: 'Characters', href: '/characters' }]}>
            <Head title="Characters" />
            <div className="mx-auto w-full max-w-5xl space-y-8 p-6">
                {flash?.success && (
                    <div className="rounded-lg border border-emerald-500/40 bg-emerald-500/10 p-3 text-sm text-emerald-700 dark:text-emerald-300">
                        {flash.success}
                    </div>
                )}

                <div className="flex items-start justify-between gap-4">
                    <HeadingSmall
                        title="Characters"
                        description="Create a character once, then pick it on any Image or Video node. Its character sheet and photos are sent as references every time, so it looks the same in every post and video."
                    />
                    {editing === null && (
                        <Button onClick={() => setEditing('new')}>
                            <Plus className="size-4" /> New character
                        </Button>
                    )}
                </div>

                {editing !== null && (
                    <CharacterForm
                        key={editing === 'new' ? 'new' : editing.id}
                        character={editing === 'new' ? null : editing}
                        styles={styles}
                        onDone={() => setEditing(null)}
                    />
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    {characters.map((c) => (
                        <CharacterCard key={c.id} character={c} credentials={credentials} onEdit={() => setEditing(c)} />
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}

function CharacterCard({ character: c, credentials, onEdit }: { character: Character; credentials: Credential[]; onEdit: () => void }) {
    const [credentialId, setCredentialId] = useState<number | ''>('');
    const [model, setModel] = useState('');
    const models = credentials.find((x) => x.id === credentialId)?.models ?? [];

    const generate = () =>
        router.post(`/characters/${c.id}/sheet`, credentialId ? { credential_id: credentialId, model } : {}, { preserveScroll: true });

    return (
        <div className="bg-card overflow-hidden rounded-xl border">
            <div className="bg-muted relative aspect-[16/9]">
                {c.sheet_url ? (
                    <a href={c.sheet_url} target="_blank" rel="noreferrer">
                        <img src={c.sheet_url} alt={`${c.name} character sheet`} className="size-full object-contain" />
                    </a>
                ) : (
                    <div className="text-muted-foreground flex size-full flex-col items-center justify-center gap-2 text-sm">
                        <UserRound className="size-10" />
                        {c.sheet_status === 'generating' ? 'Drawing the character sheet…' : 'No character sheet yet'}
                    </div>
                )}
                {c.sheet_status === 'generating' && (
                    <div className="absolute inset-0 flex items-center justify-center bg-black/40 text-white">
                        <Loader2 className="size-8 animate-spin" />
                    </div>
                )}
            </div>
            <div className="space-y-3 p-4">
                <div className="flex items-start gap-2">
                    <div className="min-w-0 flex-1">
                        <div className="font-semibold">{c.name}</div>
                        <div className="text-muted-foreground text-xs">
                            {CHARACTER_STYLE_LABELS[c.style] ?? c.style} · {c.reference_urls.length} photo{c.reference_urls.length === 1 ? '' : 's'}
                        </div>
                    </div>
                    <Button variant="ghost" size="icon" title="Edit" onClick={onEdit}>
                        <Pencil className="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        title="Delete"
                        onClick={() =>
                            confirm(`Delete ${c.name}? Flows using this character will fail until you pick another.`) &&
                            router.delete(`/characters/${c.id}`)
                        }
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>
                <p className="text-muted-foreground line-clamp-3 text-xs">{c.appearance}</p>
                {c.reference_urls.length > 0 && (
                    <div className="flex gap-1">
                        {c.reference_urls.map((r) => (
                            <img key={r.id} src={r.url} alt="" className="size-10 rounded border object-cover" />
                        ))}
                    </div>
                )}
                {c.sheet_status === 'failed' && <p className="text-xs text-red-600">{c.sheet_error}</p>}

                <div className="space-y-2 border-t pt-3">
                    <div className="flex gap-2">
                        <NativeSelect
                            className="h-8 text-xs"
                            value={credentialId}
                            onChange={(e) => setCredentialId(e.target.value ? Number(e.target.value) : '')}
                        >
                            <option value="">Default image model</option>
                            {credentials.map((cr) => (
                                <option key={cr.id} value={cr.id}>
                                    {cr.label} ({PROVIDER_LABELS[cr.provider]})
                                </option>
                            ))}
                        </NativeSelect>
                        {credentialId !== '' && (
                            <>
                                <Input
                                    className="h-8 text-xs"
                                    list={`sheet-models-${c.id}`}
                                    placeholder="model id"
                                    value={model}
                                    onChange={(e) => setModel(e.target.value)}
                                />
                                <datalist id={`sheet-models-${c.id}`}>
                                    {models.map((m) => (
                                        <option key={m} value={m} />
                                    ))}
                                </datalist>
                            </>
                        )}
                    </div>
                    <Button
                        size="sm"
                        className="w-full"
                        variant={c.sheet_status === 'ready' ? 'outline' : 'default'}
                        disabled={c.sheet_status === 'generating' || (credentialId !== '' && !model)}
                        onClick={generate}
                    >
                        <Sparkles className="size-4" /> {c.sheet_status === 'ready' ? 'Regenerate character sheet' : 'Generate character sheet'}
                    </Button>
                    <p className="text-muted-foreground text-[11px]">
                        Turnaround (front, ¾, side, back) and expressions, drawn from your photos. This becomes the master reference.
                    </p>
                </div>
            </div>
        </div>
    );
}

function CharacterForm({ character, styles, onDone }: { character: Character | null; styles: string[]; onDone: () => void }) {
    const form = useForm({
        name: character?.name ?? '',
        appearance: character?.appearance ?? '',
        style: character?.style ?? 'photorealistic',
        voice: character?.voice ?? '',
        reference_asset_ids: character?.reference_asset_ids ?? ([] as number[]),
    });
    const [photos, setPhotos] = useState<{ id: number; url: string }[]>(character?.reference_urls ?? []);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    const upload = async (files: FileList) => {
        setUploading(true);
        setUploadError(null);
        try {
            for (const file of Array.from(files).slice(0, 6 - photos.length)) {
                const body = new FormData();
                body.append('image', file);
                const asset = await api<{ id: number; url: string }>('POST', '/assets/upload', body);
                setPhotos((list) => [...list, asset]);
                form.setData((data) => ({ ...data, reference_asset_ids: [...data.reference_asset_ids, asset.id] }));
            }
        } catch (e) {
            setUploadError((e as Error).message);
        } finally {
            setUploading(false);
        }
    };

    const remove = (id: number) => {
        setPhotos((list) => list.filter((p) => p.id !== id));
        form.setData(
            'reference_asset_ids',
            form.data.reference_asset_ids.filter((x) => x !== id),
        );
    };

    const submit = () =>
        character
            ? form.put(`/characters/${character.id}`, { preserveScroll: true, onSuccess: onDone })
            : form.post('/characters', { preserveScroll: true, onSuccess: onDone });

    return (
        <div className="space-y-4 rounded-xl border p-4">
            <div className="flex items-center justify-between">
                <h3 className="font-medium">{character ? `Edit ${character.name}` : 'New character'}</h3>
                <Button variant="ghost" size="icon" onClick={onDone}>
                    <X className="size-4" />
                </Button>
            </div>
            <div className="grid gap-3 md:grid-cols-2">
                <div className="space-y-1">
                    <Label>Name</Label>
                    <Input placeholder="e.g. Maya" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    <InputError message={form.errors.name} />
                </div>
                <div className="space-y-1">
                    <Label>Style</Label>
                    <NativeSelect value={form.data.style} onChange={(e) => form.setData('style', e.target.value)}>
                        {styles.map((s) => (
                            <option key={s} value={s}>
                                {CHARACTER_STYLE_LABELS[s] ?? s}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={form.errors.style} />
                </div>
            </div>
            <div className="space-y-1">
                <Label>Appearance (locked identity)</Label>
                <Textarea
                    className="min-h-28 font-sans text-sm"
                    placeholder={APPEARANCE_HINT}
                    value={form.data.appearance}
                    onChange={(e) => form.setData('appearance', e.target.value)}
                />
                <p className="text-muted-foreground text-[11px]">
                    Face, skin tone, eyes, hair, build, age, signature outfit and any marks. The more precise, the more consistent.
                    {form.data.appearance.trim().length < 20 && ` (at least 20 characters, ${20 - form.data.appearance.trim().length} to go)`}
                </p>
                <InputError message={form.errors.appearance} />
            </div>
            <div className="space-y-1">
                <Label>Voice (for videos, optional)</Label>
                <Input
                    placeholder="e.g. Warm, upbeat female voice, light Indian English accent"
                    value={form.data.voice}
                    onChange={(e) => form.setData('voice', e.target.value)}
                />
                <InputError message={form.errors.voice} />
            </div>
            <div className="space-y-2">
                <Label>Photos of the character (optional, up to 6)</Label>
                <p className="text-muted-foreground text-[11px]">
                    Clear, well-lit photos or artwork from different angles. Only upload people who have agreed to be used this way.
                </p>
                <div className="flex flex-wrap gap-2">
                    {photos.map((p) => (
                        <div key={p.id} className="group relative size-20 overflow-hidden rounded-md border">
                            <img src={p.url} alt="" className="size-full object-cover" />
                            <button
                                type="button"
                                onClick={() => remove(p.id)}
                                className="absolute top-0.5 right-0.5 rounded-full bg-black/60 p-0.5 text-white opacity-0 group-hover:opacity-100"
                            >
                                <X className="size-3" />
                            </button>
                        </div>
                    ))}
                    {photos.length < 6 && (
                        <button
                            type="button"
                            onClick={() => fileInput.current?.click()}
                            className="hover:bg-muted flex size-20 flex-col items-center justify-center gap-1 rounded-md border border-dashed text-xs"
                        >
                            {uploading ? <Loader2 className="size-5 animate-spin" /> : <ImagePlus className="size-5" />}
                            Add
                        </button>
                    )}
                    <input
                        ref={fileInput}
                        type="file"
                        multiple
                        accept="image/png,image/jpeg,image/webp"
                        className="hidden"
                        onChange={(e) => {
                            if (e.target.files) upload(e.target.files);
                            e.target.value = '';
                        }}
                    />
                </div>
                {uploadError && <p className="text-xs text-red-600">{uploadError}</p>}
                <InputError message={Object.entries(form.errors).find(([key]) => key.startsWith('reference_asset_ids'))?.[1]} />
            </div>
            {Object.keys(form.errors).length > 0 && <p className="text-sm text-red-600">Please fix the highlighted fields above.</p>}
            <Button disabled={form.processing || uploading || !form.data.name.trim() || form.data.appearance.trim().length < 20} onClick={submit}>
                {character ? 'Save character' : 'Create character'}
            </Button>
        </div>
    );
}
