import { NativeSelect, Textarea } from '@/components/form-controls';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { api } from '@/lib/api';
import { REFERENCE_ROLES, type ReferenceEntry, type ReferenceRole } from '@/types/automation';
import { ImagePlus, Link2, Loader2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export interface ReferenceAsset {
    id: number;
    url: string;
    tags: string[] | null;
    type?: 'image' | 'video';
}

const MAX = 4;

/**
 * References for Image and Video nodes: the user's logo, people, products or any other image
 * (plus one short clip on Video nodes), each with a role and a "how & where to use it" note.
 * Stored as {src, role, note}; src is "asset:12", "{{cover.output.url}}" or "https://...".
 */
export function ReferenceImagesField({
    value: rawValue,
    onChange,
    upstreamImageKeys,
    formImageSources = [],
    assets,
    hasCharacters = false,
    allowVideo = false,
}: {
    value: (string | ReferenceEntry)[];
    onChange: (refs: ReferenceEntry[]) => void;
    upstreamImageKeys: string[];
    /** Image questions on the flow's form, e.g. a visitor's product photo */
    formImageSources?: { label: string; src: string }[];
    assets: ReferenceAsset[];
    hasCharacters?: boolean;
    /** Video nodes can take one short clip to edit or continue. */
    allowVideo?: boolean;
}) {
    // Older flows stored plain strings.
    const value: ReferenceEntry[] = rawValue.map((ref) => (typeof ref === 'string' ? { src: ref, role: 'auto' } : ref));
    const fileInput = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [url, setUrl] = useState('');
    const [known, setKnown] = useState<ReferenceAsset[]>(assets);
    const full = value.length >= MAX;

    const isVideo = (src: string) => known.find((a) => `asset:${a.id}` === src)?.type === 'video';
    const hasClip = value.some((r) => isVideo(r.src));

    const add = (src: string, role: ReferenceRole = 'auto') => {
        if (!full && !value.some((r) => r.src === src)) onChange([...value, { src, role }]);
    };
    const update = (src: string, patch: Partial<ReferenceEntry>) => onChange(value.map((r) => (r.src === src ? { ...r, ...patch } : r)));

    const upload = async (file: File) => {
        setUploading(true);
        setError(null);
        try {
            if (file.type.startsWith('video/')) {
                if (hasClip) throw new Error('Only one video clip per Video node.');
                const seconds = await clipSeconds(file);
                if (seconds > 10.5) throw new Error(`This clip is ${Math.round(seconds)} seconds long. Video references can be up to 10 seconds.`);
            }
            const form = new FormData();
            form.append('file', file);
            const asset = await api<{ id: number; url: string; type: 'image' | 'video' }>('POST', '/assets/upload', form);
            setKnown((list) => [{ id: asset.id, url: asset.url, tags: ['reference'], type: asset.type }, ...list]);
            add(`asset:${asset.id}`, asset.type === 'video' ? 'edit_video' : 'auto');
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setUploading(false);
        }
    };

    const preview = (ref: string) => {
        const id = ref.match(/^asset:(\d+)$/)?.[1];
        if (id) return known.find((a) => a.id === Number(id))?.url;
        if (ref.startsWith('/storage/')) return ref;
        return ref.startsWith('https://') ? ref : undefined;
    };

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between">
                <Label>References</Label>
                <span className="text-muted-foreground text-xs">
                    {value.length}/{MAX}
                </span>
            </div>
            <p className="text-muted-foreground text-xs">
                Add your logo, people, products or any image{allowVideo ? ' (or one clip up to 10 s)' : ''}, choose what each one is, and explain how
                and where to use it.
            </p>

            {value.length > 0 && (
                <div className="space-y-2">
                    {value.map((ref, i) => {
                        const src = preview(ref.src);
                        const video = isVideo(ref.src);
                        const meta = REFERENCE_ROLES[ref.role] ?? REFERENCE_ROLES.auto;
                        return (
                            <div key={ref.src} className="flex gap-2 rounded-lg border p-2">
                                <div className="bg-muted size-16 shrink-0 overflow-hidden rounded-md border">
                                    {src && video ? (
                                        <video src={src} muted className="size-full object-cover" />
                                    ) : src ? (
                                        <img src={src} alt="" className="size-full object-cover" />
                                    ) : (
                                        <div className="flex size-full items-center justify-center p-1 text-center font-mono text-[9px] break-all">
                                            {ref.src}
                                        </div>
                                    )}
                                </div>
                                <div className="min-w-0 flex-1 space-y-1">
                                    <div className="flex items-center gap-1">
                                        <span className="text-muted-foreground shrink-0 text-[11px]">#{i + 1} Use as</span>
                                        <NativeSelect
                                            className="h-7 py-0 text-xs"
                                            value={ref.role}
                                            onChange={(e) => update(ref.src, { role: e.target.value as ReferenceRole })}
                                        >
                                            {Object.entries(REFERENCE_ROLES)
                                                .filter(([, m]) => Boolean(m.video) === video)
                                                .map(([role, m]) => (
                                                    <option key={role} value={role}>
                                                        {m.label}
                                                    </option>
                                                ))}
                                        </NativeSelect>
                                        <button
                                            type="button"
                                            title="Remove"
                                            onClick={() => onChange(value.filter((r) => r.src !== ref.src))}
                                            className="text-muted-foreground hover:text-foreground shrink-0 p-1"
                                        >
                                            <X className="size-3.5" />
                                        </button>
                                    </div>
                                    <p className="text-muted-foreground text-[11px] leading-snug">{meta.hint}</p>
                                    <Textarea
                                        className="min-h-12 font-sans text-xs"
                                        placeholder={`How & where to use it. ${meta.placeholder}`}
                                        value={ref.note ?? ''}
                                        onChange={(e) => update(ref.src, { note: e.target.value })}
                                    />
                                    {ref.role === 'color_theme' && <PaletteSwatches src={ref.src} />}
                                    {ref.role === 'swap_character' && !hasCharacters && (
                                        <p className="text-[11px] text-amber-600">
                                            Pick a character above, or describe the new character in your brief.
                                        </p>
                                    )}
                                    {ref.role === 'auto' && !ref.note?.trim() && (
                                        <p className="text-[11px] text-amber-600">
                                            Say what this image is and how to use it, so the AI doesn’t have to guess.
                                        </p>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            <div className="flex flex-wrap gap-2">
                <input
                    ref={fileInput}
                    type="file"
                    accept={allowVideo && !hasClip ? 'image/png,image/jpeg,image/webp,video/mp4,video/quicktime' : 'image/png,image/jpeg,image/webp'}
                    className="hidden"
                    onChange={(e) => {
                        const file = e.target.files?.[0];
                        if (file) upload(file);
                        e.target.value = '';
                    }}
                />
                <Button type="button" size="sm" variant="outline" disabled={full || uploading} onClick={() => fileInput.current?.click()}>
                    {uploading ? <Loader2 className="size-4 animate-spin" /> : <ImagePlus className="size-4" />} Upload
                </Button>
                {upstreamImageKeys.map((key) => (
                    <Button key={key} type="button" size="sm" variant="outline" disabled={full} onClick={() => add(`{{${key}.output.url}}`)}>
                        Use output of “{key}”
                    </Button>
                ))}
                {formImageSources.map(({ label, src }) => (
                    <Button key={src} type="button" size="sm" variant="outline" disabled={full} onClick={() => add(src)}>
                        Use form answer “{label}”
                    </Button>
                ))}
            </div>

            <div className="flex gap-2">
                <Input className="h-8 text-xs" placeholder="https://… image URL" value={url} onChange={(e) => setUrl(e.target.value)} />
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={full || !url.startsWith('https://')}
                    onClick={() => {
                        add(url.trim());
                        setUrl('');
                    }}
                >
                    <Link2 className="size-4" />
                </Button>
            </div>

            {known.length > 0 && !full && (
                <details className="text-xs">
                    <summary className="text-muted-foreground cursor-pointer">Pick from your library</summary>
                    <div className="mt-2 grid grid-cols-6 gap-1">
                        {known
                            .filter((asset) => asset.type !== 'video' || (allowVideo && !hasClip))
                            .map((asset) => (
                                <button
                                    key={asset.id}
                                    type="button"
                                    onClick={() => add(`asset:${asset.id}`, asset.type === 'video' ? 'edit_video' : 'auto')}
                                    className="aspect-square overflow-hidden rounded border"
                                >
                                    {asset.type === 'video' ? (
                                        <video src={asset.url} muted className="size-full object-cover" />
                                    ) : (
                                        <img src={asset.url} alt="" className="size-full object-cover" loading="lazy" />
                                    )}
                                </button>
                            ))}
                    </div>
                </details>
            )}
            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    );
}

/** Shows the colours a "Colour theme only" reference will contribute. */
function PaletteSwatches({ src }: { src: string }) {
    const id = src.match(/^asset:(\d+)$/)?.[1];
    const [colours, setColours] = useState<{ hex: string; name: string; share: number }[] | null>(null);

    useEffect(() => {
        if (!id) return;
        api<{ colours: { hex: string; name: string; share: number }[] }>('GET', `/assets/${id}/palette`)
            .then((r) => setColours(r.colours))
            .catch(() => setColours([]));
    }, [id]);

    if (!id) return <p className="text-muted-foreground text-[11px]">Colours are read from this image when the flow runs.</p>;
    if (!colours) return null;

    return (
        <div className="flex flex-wrap gap-1 pt-0.5">
            {colours.map((c) => (
                <span key={c.hex} title={`${c.hex} ${c.name}`} className="flex items-center gap-1 rounded border px-1 py-0.5 text-[10px]">
                    <span className="size-3 rounded-sm border" style={{ backgroundColor: c.hex }} />
                    {c.share}%
                </span>
            ))}
        </div>
    );
}

/** Reads a clip's length in the browser before uploading. */
function clipSeconds(file: File): Promise<number> {
    return new Promise((resolve) => {
        const video = document.createElement('video');
        video.preload = 'metadata';
        video.onloadedmetadata = () => {
            URL.revokeObjectURL(video.src);
            resolve(video.duration);
        };
        video.onerror = () => resolve(0); // let the server decide
        video.src = URL.createObjectURL(file);
    });
}
