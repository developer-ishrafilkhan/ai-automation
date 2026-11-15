import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type Character } from '@/types/automation';
import { Link } from '@inertiajs/react';
import { Check, UserRound } from 'lucide-react';

const MAX = 3;

/** Pick recurring characters for an Image or Video node. Their sheet and photos are sent as references automatically. */
export function CharacterPicker({ characters, value, onChange }: { characters: Character[]; value: number[]; onChange: (ids: number[]) => void }) {
    const toggle = (id: number) => onChange(value.includes(id) ? value.filter((v) => v !== id) : value.length < MAX ? [...value, id] : value);

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between">
                <Label className="flex items-center gap-1.5">
                    <UserRound className="size-4" /> Characters
                </Label>
                <Link href="/characters" className="text-muted-foreground text-xs underline">
                    Manage
                </Link>
            </div>
            {characters.length === 0 ? (
                <p className="text-muted-foreground text-xs">
                    Create a character once and it looks the same in every image and video.{' '}
                    <Link href="/characters" className="underline">
                        Create a character
                    </Link>
                </p>
            ) : (
                <>
                    <div className="grid grid-cols-3 gap-2">
                        {characters.map((c) => {
                            const selected = value.includes(c.id);
                            const thumb = c.sheet_url ?? c.reference_urls[0]?.url;
                            return (
                                <button
                                    key={c.id}
                                    type="button"
                                    onClick={() => toggle(c.id)}
                                    className={cn(
                                        'relative overflow-hidden rounded-lg border text-left',
                                        selected ? 'ring-primary ring-2' : 'hover:bg-muted',
                                    )}
                                >
                                    <div className="bg-muted aspect-square">
                                        {thumb ? (
                                            <img src={thumb} alt="" className="size-full object-cover" />
                                        ) : (
                                            <UserRound className="text-muted-foreground m-auto size-full p-4" />
                                        )}
                                    </div>
                                    <div className="truncate px-1.5 py-1 text-xs font-medium">{c.name}</div>
                                    {selected && (
                                        <span className="bg-primary text-primary-foreground absolute top-1 right-1 rounded-full p-0.5">
                                            <Check className="size-3" />
                                        </span>
                                    )}
                                    {c.sheet_status !== 'ready' && (
                                        <span className="absolute top-1 left-1 rounded bg-amber-500 px-1 text-[9px] text-white">no sheet</span>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                    {value.length > 0 && (
                        <p className="text-muted-foreground text-[11px]">
                            Their character sheet and photos are sent first as references, and the prompt starts with a strict "keep them identical"
                            block. Refer to them by name in your prompt.
                        </p>
                    )}
                </>
            )}
        </div>
    );
}
