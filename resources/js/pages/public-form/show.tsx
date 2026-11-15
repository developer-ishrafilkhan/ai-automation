import { NativeSelect, Textarea } from '@/components/form-controls';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PublicFormLayout from '@/layouts/public-form-layout';
import { cn } from '@/lib/utils';
import { type FormField } from '@/types/automation';
import { Head, useForm } from '@inertiajs/react';
import { Check, ImagePlus, Loader2, Sparkles, UserRound } from 'lucide-react';
import { useState } from 'react';

interface Props {
    slug: string;
    title: string;
    description: string;
    submitLabel: string;
    fields: FormField[];
    /** The owner's saved characters, when the form has a Character question */
    characters: PublicCharacter[];
}

interface PublicCharacter {
    id: number;
    name: string;
    image: string | null;
}

type Value = string | string[] | File | null;

export default function PublicForm({ slug, title, description, submitLabel, fields, characters }: Props) {
    const form = useForm<Record<string, Value>>({
        website: '', // spam trap: hidden from people, filled in by bots
        ...Object.fromEntries(fields.map((f) => [f.key, f.type === 'image' ? null : f.type === 'character' && f.multiple ? [] : ''])),
    });
    const errors = form.errors as Record<string, string>;

    return (
        <PublicFormLayout>
            <Head title={title} />
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/f/${slug}`, { forceFormData: true });
                }}
                className="bg-background space-y-6 rounded-2xl border p-6 shadow-sm sm:p-8"
            >
                <header className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                    {description && <p className="text-muted-foreground whitespace-pre-line">{description}</p>}
                </header>

                {fields.map((field) => (
                    <div key={field.key} className="space-y-1.5">
                        <Label htmlFor={field.key}>
                            {field.label}
                            {field.required && <span className="text-red-500"> *</span>}
                        </Label>
                        {field.type === 'character' ? (
                            <CharacterChoice
                                characters={characters}
                                multiple={!!field.multiple}
                                value={form.data[field.key]}
                                onChange={(v) => form.setData(field.key, v)}
                            />
                        ) : (
                            <FieldInput field={field} value={form.data[field.key]} onChange={(v) => form.setData(field.key, v)} />
                        )}
                        {field.help && <p className="text-muted-foreground text-xs">{field.help}</p>}
                        {(errors[field.key] ?? errors[`${field.key}.0`]) && (
                            <p className="text-sm text-red-600">{errors[field.key] ?? errors[`${field.key}.0`]}</p>
                        )}
                    </div>
                ))}

                <input
                    type="text"
                    name="website"
                    tabIndex={-1}
                    autoComplete="off"
                    aria-hidden="true"
                    className="absolute -left-[9999px] h-0 w-0 opacity-0"
                    value={String(form.data.website ?? '')}
                    onChange={(e) => form.setData('website', e.target.value)}
                />

                {errors.form && <p className="rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-200">{errors.form}</p>}

                <Button type="submit" size="lg" className="w-full" disabled={form.processing}>
                    {form.processing ? <Loader2 className="size-4 animate-spin" /> : <Sparkles className="size-4" />} {submitLabel}
                </Button>
            </form>
        </PublicFormLayout>
    );
}

/** Pick one (or up to 3) of the owner's saved characters by picture and name. */
function CharacterChoice({
    characters,
    multiple,
    value,
    onChange,
}: {
    characters: PublicCharacter[];
    multiple: boolean;
    value: Value;
    onChange: (v: Value) => void;
}) {
    const picked = Array.isArray(value) ? value : value ? [String(value)] : [];
    const toggle = (id: string) => {
        if (!multiple) return onChange(picked.includes(id) ? '' : id);
        onChange(picked.includes(id) ? picked.filter((p) => p !== id) : picked.length < 3 ? [...picked, id] : picked);
    };

    if (characters.length === 0) return <p className="text-muted-foreground text-sm">No characters are available yet.</p>;

    return (
        <div className="space-y-1.5">
            <div className="grid grid-cols-3 gap-3 sm:grid-cols-4" role={multiple ? 'group' : 'radiogroup'}>
                {characters.map((c) => {
                    const selected = picked.includes(String(c.id));
                    return (
                        <button
                            key={c.id}
                            type="button"
                            role={multiple ? 'checkbox' : 'radio'}
                            aria-checked={selected}
                            onClick={() => toggle(String(c.id))}
                            className={cn(
                                'relative overflow-hidden rounded-xl border text-left transition',
                                selected ? 'ring-primary border-primary ring-2' : 'hover:border-foreground/30',
                            )}
                        >
                            <div className="bg-muted aspect-square">
                                {c.image ? (
                                    <img src={c.image} alt="" className="size-full object-cover" loading="lazy" />
                                ) : (
                                    <UserRound className="text-muted-foreground size-full p-6" />
                                )}
                            </div>
                            <div className="truncate px-2 py-1.5 text-sm font-medium">{c.name}</div>
                            {selected && (
                                <span className="bg-primary text-primary-foreground absolute top-1.5 right-1.5 rounded-full p-0.5">
                                    <Check className="size-3.5" />
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>
            {multiple && <p className="text-muted-foreground text-xs">Pick up to 3.</p>}
        </div>
    );
}

function FieldInput({ field, value, onChange }: { field: FormField; value: Value; onChange: (v: Value) => void }) {
    const common = { id: field.key, name: field.key, required: field.required, placeholder: field.placeholder || undefined };

    switch (field.type) {
        case 'textarea':
            return (
                <Textarea {...common} className="min-h-28 font-sans text-sm" value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} />
            );
        case 'select':
            return (
                <NativeSelect {...common} value={String(value ?? '')} onChange={(e) => onChange(e.target.value)}>
                    <option value="">Choose…</option>
                    {(field.options ?? []).map((o) => (
                        <option key={o} value={o}>
                            {o}
                        </option>
                    ))}
                </NativeSelect>
            );
        case 'image':
            return <ImageInput {...common} file={value instanceof File ? value : null} onChange={onChange} />;
        default:
            return (
                <Input
                    {...common}
                    type={field.type === 'number' ? 'number' : field.type === 'email' ? 'email' : field.type === 'url' ? 'url' : 'text'}
                    value={String(value ?? '')}
                    onChange={(e) => onChange(e.target.value)}
                />
            );
    }
}

function ImageInput({ id, required, file, onChange }: { id: string; required: boolean; file: File | null; onChange: (f: File | null) => void }) {
    const [preview, setPreview] = useState<string | null>(null);

    return (
        <label htmlFor={id} className="hover:bg-muted/50 flex cursor-pointer items-center gap-4 rounded-lg border border-dashed p-4 text-sm">
            {preview ? (
                <img src={preview} alt="" className="size-20 rounded-md border object-cover" />
            ) : (
                <div className="bg-muted flex size-20 items-center justify-center rounded-md">
                    <ImagePlus className="text-muted-foreground size-6" />
                </div>
            )}
            <div>
                <div className="font-medium">{file ? file.name : 'Choose an image'}</div>
                <div className="text-muted-foreground text-xs">PNG, JPG or WebP, up to 10 MB</div>
            </div>
            <input
                id={id}
                type="file"
                accept="image/png,image/jpeg,image/webp"
                required={required && !file}
                className="sr-only"
                onChange={(e) => {
                    const chosen = e.target.files?.[0] ?? null;
                    onChange(chosen);
                    setPreview(chosen ? URL.createObjectURL(chosen) : null);
                }}
            />
        </label>
    );
}
