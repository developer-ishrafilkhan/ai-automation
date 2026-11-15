import { NativeSelect, Textarea } from '@/components/form-controls';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Character, FORM_FIELD_TYPES, type FormField, type FormFieldType } from '@/types/automation';
import { ArrowDown, ArrowUp, Loader2, Plus, Sparkles, X } from 'lucide-react';

export function fieldKey(label: string): string {
    const key = label
        .normalize('NFKD')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 40);
    return key && !/^[a-z]/.test(key) ? `f_${key}` : key;
}

/**
 * The trigger node's form: the questions a visitor (or you, when testing) answers. Each answer
 * reaches later steps as {{trigger.output.<key>}}.
 */
export function FormFieldsEditor({
    fields,
    onChange,
    testValues,
    onTestValuesChange,
    usedKeys,
    triggerKey,
    onSuggest,
    suggesting,
    characters,
}: {
    /** The workspace's saved characters, offered by Character questions */
    characters: Character[];
    fields: FormField[];
    onChange: (fields: FormField[]) => void;
    testValues: Record<string, unknown>;
    onTestValuesChange: (values: Record<string, unknown>) => void;
    /** Keys the flow's steps read from the form */
    usedKeys: string[];
    triggerKey: string;
    onSuggest: () => void;
    suggesting: boolean;
}) {
    const keys = fields.map((f) => f.key);
    // {{trigger.output.<character key>_name}} is filled in automatically, not asked.
    const characterKeys = fields.filter((f) => f.type === 'character').map((f) => f.key);
    const missing = usedKeys.filter((k) => !keys.includes(k) && !(k.endsWith('_name') && characterKeys.includes(k.slice(0, -5))));
    const update = (i: number, patch: Partial<FormField>) => onChange(fields.map((f, j) => (j === i ? { ...f, ...patch } : f)));
    const move = (i: number, by: number) => {
        const list = [...fields];
        const [item] = list.splice(i, 1);
        list.splice(i + by, 0, item);
        onChange(list);
    };
    const add = (key = '') => {
        let unique = key || `field_${fields.length + 1}`;
        for (let n = 2; keys.includes(unique); n++) unique = `${key || 'field'}_${n}`;
        onChange([
            ...fields,
            { key: unique, label: key ? key.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()) : '', type: 'text', required: true },
        ]);
    };

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-2">
                <Label>Form questions</Label>
                <Button type="button" size="sm" variant="outline" onClick={onSuggest} disabled={suggesting}>
                    {suggesting ? <Loader2 className="size-4 animate-spin" /> : <Sparkles className="size-4" />} Write with AI
                </Button>
            </div>
            <p className="text-muted-foreground text-xs">
                Visitors answer these on the public form. Steps read each answer as{' '}
                <code className="text-[11px]">{`{{${triggerKey}.output.key}}`}</code>.
            </p>

            {missing.length > 0 && (
                <div className="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    Your steps read {missing.map((k) => `“${k}”`).join(', ')} but there is no question for {missing.length > 1 ? 'them' : 'it'}.{' '}
                    <button type="button" className="font-medium underline" onClick={() => missing.forEach((k) => add(k))}>
                        Add {missing.length > 1 ? 'them' : 'it'}
                    </button>
                </div>
            )}

            {fields.map((field, i) => {
                const unused = usedKeys.length > 0 && !usedKeys.includes(field.key);
                return (
                    <div key={i} className="space-y-2 rounded-lg border p-2">
                        <div className="flex items-center gap-1">
                            <Input
                                className="h-8 flex-1 text-sm"
                                placeholder="Question, e.g. What is your product called?"
                                value={field.label}
                                onChange={(e) => {
                                    // Keep the key in step with the label until the user names it themselves.
                                    const followsLabel = !field.key || field.key === fieldKey(field.label) || /^field_\d+$/.test(field.key);
                                    update(i, { label: e.target.value, ...(followsLabel ? { key: fieldKey(e.target.value) } : {}) });
                                }}
                            />
                            <button
                                type="button"
                                title="Move up"
                                disabled={i === 0}
                                onClick={() => move(i, -1)}
                                className="text-muted-foreground p-1 disabled:opacity-30"
                            >
                                <ArrowUp className="size-3.5" />
                            </button>
                            <button
                                type="button"
                                title="Move down"
                                disabled={i === fields.length - 1}
                                onClick={() => move(i, 1)}
                                className="text-muted-foreground p-1 disabled:opacity-30"
                            >
                                <ArrowDown className="size-3.5" />
                            </button>
                            <button
                                type="button"
                                title="Remove"
                                onClick={() => onChange(fields.filter((_, j) => j !== i))}
                                className="text-muted-foreground p-1"
                            >
                                <X className="size-3.5" />
                            </button>
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <NativeSelect
                                className="h-8 text-xs"
                                value={field.type}
                                onChange={(e) => update(i, { type: e.target.value as FormFieldType })}
                            >
                                {Object.entries(FORM_FIELD_TYPES).map(([type, label]) => (
                                    <option key={type} value={type}>
                                        {label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <Input
                                className="h-8 font-mono text-xs"
                                value={field.key}
                                title="Key used in prompts"
                                onChange={(e) => update(i, { key: e.target.value.replace(/[^a-z0-9_]/gi, '').toLowerCase() })}
                            />
                        </div>
                        {field.type === 'select' && (
                            <Textarea
                                className="min-h-14 font-sans text-xs"
                                placeholder="One choice per line"
                                value={(field.options ?? []).join('\n')}
                                onChange={(e) => update(i, { options: e.target.value.split('\n') })}
                            />
                        )}
                        {field.type === 'character' && (
                            <div className="space-y-1">
                                <p className="text-muted-foreground text-[11px]">
                                    Visitors pick from your {characters.length} saved character{characters.length === 1 ? '' : 's'} (picture and
                                    name); nothing is uploaded. On Image/Video steps choose “Character chosen on the form”. Text steps can use the
                                    name as <code>{`{{${triggerKey}.output.${field.key}_name}}`}</code>.
                                </p>
                                {characters.length === 0 && (
                                    <p className="text-[11px] text-amber-600">
                                        You have no characters yet.{' '}
                                        <a href="/characters" className="underline">
                                            Create one
                                        </a>
                                    </p>
                                )}
                                <label className="flex items-center gap-1.5 text-xs">
                                    <input type="checkbox" checked={!!field.multiple} onChange={(e) => update(i, { multiple: e.target.checked })} />
                                    Visitors can pick more than one (up to 3)
                                </label>
                            </div>
                        )}
                        {field.type !== 'image' && field.type !== 'select' && field.type !== 'character' && (
                            <Input
                                className="h-8 text-xs"
                                placeholder="Placeholder (an example answer)"
                                value={field.placeholder ?? ''}
                                onChange={(e) => update(i, { placeholder: e.target.value })}
                            />
                        )}
                        <Input
                            className="h-8 text-xs"
                            placeholder="Help text (optional)"
                            value={field.help ?? ''}
                            onChange={(e) => update(i, { help: e.target.value })}
                        />
                        <div className="flex items-center justify-between">
                            <label className="flex items-center gap-1.5 text-xs">
                                <input type="checkbox" checked={field.required} onChange={(e) => update(i, { required: e.target.checked })} />{' '}
                                Required
                            </label>
                            {unused && <span className="text-[11px] text-amber-600">No step uses this answer yet</span>}
                        </div>
                        {field.type === 'character' ? (
                            <select
                                className="border-input bg-background h-8 w-full rounded-md border px-2 text-xs"
                                value={String(testValues[field.key] ?? '')}
                                onChange={(e) => onTestValuesChange({ ...testValues, [field.key]: e.target.value })}
                            >
                                <option value="">Test pick for Run…</option>
                                {characters.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <Input
                                className="h-8 text-xs"
                                placeholder={field.type === 'image' ? 'Test image link for Run (https://…)' : 'Test answer for Run'}
                                value={String(testValues[field.key] ?? '')}
                                onChange={(e) => onTestValuesChange({ ...testValues, [field.key]: e.target.value })}
                            />
                        )}
                    </div>
                );
            })}

            <Button type="button" size="sm" variant="outline" onClick={() => add()} disabled={fields.length >= 15}>
                <Plus className="size-4" /> Add question
            </Button>
        </div>
    );
}
