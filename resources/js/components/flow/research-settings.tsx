import { Textarea } from '@/components/form-controls';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { CONNECTOR_PROVIDERS, type Credential, type McpServer, type NodeData } from '@/types/automation';
import { Link } from '@inertiajs/react';
import { Plug, Telescope, Zap } from 'lucide-react';

export function ResearchSettings({
    data,
    onChange,
    servers,
    credential,
}: {
    data: NodeData;
    onChange: (patch: Partial<NodeData>) => void;
    servers: McpServer[];
    credential?: Credential;
}) {
    const mode = data.mode ?? 'quick';
    const selected = data.mcp_server_ids ?? [];
    const connectorsUnsupported = credential && !CONNECTOR_PROVIDERS.includes(credential.provider);

    return (
        <div className="space-y-4">
            <div className="space-y-1">
                <Label>Depth</Label>
                <div className="grid grid-cols-2 gap-2">
                    {(
                        [
                            ['quick', Zap, 'Quick', 'A few sources, about a minute'],
                            ['deep', Telescope, 'Deep research', 'Many sources, cross-checked. Can take 5-30 min'],
                        ] as const
                    ).map(([value, Icon, title, hint]) => (
                        <button
                            key={value}
                            type="button"
                            onClick={() => onChange({ mode: value })}
                            className={cn('rounded-lg border p-2 text-left', mode === value ? 'border-primary bg-primary/5' : 'hover:bg-muted')}
                        >
                            <div className="flex items-center gap-1.5 text-sm font-medium">
                                <Icon className="size-4" /> {title}
                            </div>
                            <div className="text-muted-foreground mt-0.5 text-[11px] leading-snug">{hint}</div>
                        </button>
                    ))}
                </div>
            </div>

            <div className="space-y-1">
                <Label>Pages to read (optional)</Label>
                <Textarea
                    className="min-h-16"
                    placeholder={'https://competitor.com/pricing\nhttps://example.com/report'}
                    value={data.urls ?? ''}
                    onChange={(e) => onChange({ urls: e.target.value })}
                />
                <p className="text-muted-foreground text-[11px]">One https:// link per line. Web search is always on.</p>
            </div>

            <div className="space-y-1">
                <Label className="flex items-center gap-1.5">
                    <Plug className="size-4" /> Connectors
                </Label>
                {servers.length === 0 ? (
                    <p className="text-muted-foreground text-xs">
                        Connect Notion, GitHub, Drive and other MCP servers to research your own data too.{' '}
                        <Link href="/connectors" className="underline">
                            Add a connector
                        </Link>
                    </p>
                ) : (
                    <div className="space-y-1">
                        {servers.map((server) => (
                            <label key={server.id} className="hover:bg-muted flex cursor-pointer items-start gap-2 rounded-md border p-2 text-sm">
                                <input
                                    type="checkbox"
                                    className="mt-0.5"
                                    checked={selected.includes(server.id)}
                                    onChange={(e) =>
                                        onChange({
                                            mcp_server_ids: e.target.checked ? [...selected, server.id] : selected.filter((id) => id !== server.id),
                                        })
                                    }
                                />
                                <span className="min-w-0">
                                    <span className="font-medium">{server.name}</span>
                                    <span className="text-muted-foreground block truncate text-[11px]">
                                        {server.tools_cache?.length ?? 0} tools · {server.url}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>
                )}
                {connectorsUnsupported && selected.length > 0 && (
                    <p className="text-xs text-amber-600">Gemini can't use connectors. Pick a Claude or OpenAI key for this node.</p>
                )}
            </div>

            <div className="space-y-1">
                <Label>Extra instructions (optional)</Label>
                <Textarea
                    className="min-h-16"
                    placeholder="e.g. Focus on India. Prefer sources from the last 12 months. Answer in a table."
                    value={data.instructions ?? ''}
                    onChange={(e) => onChange({ instructions: e.target.value })}
                />
            </div>
        </div>
    );
}
