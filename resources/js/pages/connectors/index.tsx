import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type McpServer } from '@/types/automation';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, Info, Loader2, Lock, LogIn, Plug, RefreshCw, Trash2, XCircle } from 'lucide-react';
import { useState } from 'react';

interface CatalogApp {
    id: string;
    name: string;
    category: string;
    color: string;
    description: string;
    note: string | null;
    ready: boolean;
}

interface Props {
    servers: (McpServer & { catalog_id: string | null; auth_type: 'token' | 'oauth' })[];
    catalog: CatalogApp[];
    callbackUrl: string;
}

export default function Connectors({ servers, catalog, callbackUrl }: Props) {
    const { flash } = usePage<SharedData & { flash: { success?: string | null; error?: string | null } }>().props;
    const [connecting, setConnecting] = useState<string | null>(null);
    const form = useForm({ name: '', url: '', token: '' });

    const connect = (url: string, key: string, data = {}) => {
        setConnecting(key);
        // The server answers with a full-page redirect to the app's sign-in screen.
        router.post(url, data, { preserveScroll: true, onFinish: () => setConnecting(null) });
    };

    const categories = [...new Set(catalog.map((app) => app.category))];

    return (
        <AppLayout breadcrumbs={[{ title: 'Connectors', href: '/connectors' }]}>
            <Head title="Connectors" />
            <div className="mx-auto w-full max-w-5xl space-y-10 p-6">
                {flash?.success && (
                    <div className="rounded-lg border border-emerald-500/40 bg-emerald-500/10 p-3 text-sm text-emerald-700 dark:text-emerald-300">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-700 dark:text-red-300">{flash.error}</div>
                )}

                <section className="space-y-4">
                    <HeadingSmall
                        title="Connect your apps"
                        description="One click to sign in. Research nodes can then search and read your email, sheets, docs and tools alongside the web. You can connect several accounts of the same app."
                    />
                    {categories.map((category) => (
                        <div key={category} className="space-y-2">
                            <h3 className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">{category}</h3>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {catalog
                                    .filter((app) => app.category === category)
                                    .map((app) => {
                                        const connected = servers.filter((s) => s.catalog_id === app.id && s.status === 'valid').length;
                                        return (
                                            <div key={app.id} className="bg-card flex flex-col gap-3 rounded-xl border p-4">
                                                <div className="flex items-center gap-3">
                                                    <span
                                                        className="flex size-10 shrink-0 items-center justify-center rounded-lg text-sm font-bold text-white"
                                                        style={{ backgroundColor: app.color }}
                                                    >
                                                        {app.name
                                                            .split(/[\s&]+/)
                                                            .map((w) => w[0])
                                                            .join('')
                                                            .slice(0, 2)}
                                                    </span>
                                                    <div className="min-w-0">
                                                        <div className="font-medium">{app.name}</div>
                                                        {connected > 0 && (
                                                            <div className="flex items-center gap-1 text-xs text-emerald-600">
                                                                <CheckCircle2 className="size-3" /> {connected} connected
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                                <p className="text-muted-foreground flex-1 text-xs leading-relaxed">{app.description}</p>
                                                {app.ready ? (
                                                    <Button
                                                        size="sm"
                                                        variant={connected ? 'outline' : 'default'}
                                                        disabled={connecting !== null}
                                                        onClick={() => connect(`/connectors/apps/${app.id}`, app.id)}
                                                    >
                                                        {connecting === app.id ? (
                                                            <Loader2 className="size-4 animate-spin" />
                                                        ) : (
                                                            <LogIn className="size-4" />
                                                        )}
                                                        {connected ? 'Connect another account' : 'Connect'}
                                                    </Button>
                                                ) : (
                                                    <div className="rounded-md bg-amber-500/10 p-2 text-[11px] text-amber-700 dark:text-amber-300">
                                                        Needs a one-time admin setup (OAuth app in .env).
                                                    </div>
                                                )}
                                                {app.note && (
                                                    <p className="text-muted-foreground flex gap-1 text-[11px] leading-snug">
                                                        <Info className="mt-0.5 size-3 shrink-0" /> {app.note}
                                                    </p>
                                                )}
                                            </div>
                                        );
                                    })}
                            </div>
                        </div>
                    ))}
                </section>

                <section className="space-y-3">
                    <HeadingSmall title="Your connections" description="Everything this workspace can use in Research nodes." />
                    <div className="divide-y rounded-xl border">
                        {servers.length === 0 && (
                            <div className="text-muted-foreground flex flex-col items-center gap-2 p-8 text-center text-sm">
                                <Plug className="size-8" /> Nothing connected yet. Pick an app above.
                            </div>
                        )}
                        {servers.map((server) => (
                            <div key={server.id} className="space-y-2 p-4">
                                <div className="flex items-center gap-3">
                                    <Plug className="text-muted-foreground size-5 shrink-0" />
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2 font-medium">
                                            {server.name}
                                            {server.has_token && <Lock className="text-muted-foreground size-3.5" aria-label="Signed in" />}
                                            <span className="text-muted-foreground text-[10px] font-normal uppercase">
                                                {server.auth_type === 'oauth' ? 'sign-in' : 'token'}
                                            </span>
                                        </div>
                                        <div className="text-muted-foreground truncate text-xs">{server.url}</div>
                                    </div>
                                    {server.status === 'valid' ? (
                                        <span className="flex items-center gap-1 text-xs text-emerald-600">
                                            <CheckCircle2 className="size-4" /> {server.tools_cache?.length ?? 0} tools
                                        </span>
                                    ) : (
                                        <span className="flex items-center gap-1 text-xs text-red-600">
                                            <XCircle className="size-4" /> Not working
                                        </span>
                                    )}
                                    {server.auth_type === 'oauth' && server.status !== 'valid' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={connecting !== null}
                                            onClick={() => connect(`/connectors/${server.id}/reconnect`, `re-${server.id}`)}
                                        >
                                            {connecting === `re-${server.id}` ? (
                                                <Loader2 className="size-4 animate-spin" />
                                            ) : (
                                                <LogIn className="size-4" />
                                            )}{' '}
                                            Reconnect
                                        </Button>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        title="Test again"
                                        onClick={() => router.post(`/connectors/${server.id}/test`, {}, { preserveScroll: true })}
                                    >
                                        <RefreshCw className="size-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        title="Remove"
                                        onClick={() =>
                                            confirm(`Remove "${server.name}"?`) && router.delete(`/connectors/${server.id}`, { preserveScroll: true })
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                                {server.status === 'invalid' && <p className="text-xs text-red-600">{server.status_message}</p>}
                                {server.tools_cache && server.tools_cache.length > 0 && (
                                    <details className="text-xs">
                                        <summary className="text-muted-foreground cursor-pointer">Tools the AI can use</summary>
                                        <ul className="mt-2 grid gap-1 sm:grid-cols-2">
                                            {server.tools_cache.map((tool) => (
                                                <li key={tool.name} className="bg-muted/50 rounded p-2">
                                                    <div className="font-mono">{tool.name}</div>
                                                    {tool.description && (
                                                        <div className="text-muted-foreground mt-0.5 line-clamp-2">{tool.description}</div>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    </details>
                                )}
                            </div>
                        ))}
                    </div>
                </section>

                <section className="space-y-3 rounded-xl border p-4">
                    <h3 className="font-medium">Custom MCP server</h3>
                    <p className="text-muted-foreground text-xs">
                        Any remote MCP server. Use sign-in if it supports OAuth, or paste an access token.
                    </p>
                    <div className="grid gap-3 md:grid-cols-[1fr_2fr]">
                        <div className="space-y-1">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                placeholder="e.g. Company wiki"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="url">MCP server URL</Label>
                            <Input
                                id="url"
                                placeholder="https://mcp.example.com/mcp"
                                value={form.data.url}
                                onChange={(e) => form.setData('url', e.target.value)}
                            />
                            <InputError message={form.errors.url} />
                        </div>
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="token">Access token (only for token-based servers)</Label>
                        <Input
                            id="token"
                            type="password"
                            autoComplete="off"
                            value={form.data.token}
                            onChange={(e) => form.setData('token', e.target.value)}
                        />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            disabled={connecting !== null || !form.data.name || !form.data.url}
                            onClick={() => connect('/connectors/oauth', 'custom', { name: form.data.name, url: form.data.url })}
                        >
                            {connecting === 'custom' ? <Loader2 className="size-4 animate-spin" /> : <LogIn className="size-4" />} Sign in with OAuth
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing || !form.data.token}
                            onClick={() => form.post('/connectors', { preserveScroll: true, onSuccess: () => form.reset() })}
                        >
                            {form.processing ? 'Connecting…' : 'Add with token'}
                        </Button>
                    </div>
                </section>

                <p className={cn('text-muted-foreground text-xs')}>
                    Research nodes using Claude or OpenAI can call these tools; Gemini's API can't use connectors. Content from connected apps is
                    treated as untrusted data. Admins: apps that need an OAuth app use the redirect URI{' '}
                    <code className="bg-muted rounded px-1">{callbackUrl}</code>.
                </p>
            </div>
        </AppLayout>
    );
}
