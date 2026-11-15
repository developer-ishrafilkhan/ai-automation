import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    CheckCircle2,
    Clapperboard,
    Clock,
    Globe,
    ImageIcon,
    KeyRound,
    Laptop,
    Layers,
    Lock,
    MousePointerClick,
    Plug,
    Route,
    Send,
    Server,
    ShieldCheck,
    Sparkles,
    Telescope,
    Type,
    Workflow,
} from 'lucide-react';
import { type ReactNode } from 'react';

const FEATURES: { icon: typeof Lock; title: string; body: string; soon?: boolean }[] = [
    {
        icon: Route,
        title: 'The right model for each task',
        body: 'Set a default per task: Claude for research, DeepSeek for bulk tagging, OpenAI for images, Gemini for video. Override on any node, with an automatic fallback if a provider fails or rate-limits.',
    },
    {
        icon: Clapperboard,
        title: 'Images and video are first-class',
        body: 'Generation nodes handle slow jobs for you: a video step waits for the render, then the flow carries on. Every file lands in your asset library with a preview.',
    },
    {
        icon: KeyRound,
        title: 'Bring your own keys',
        body: 'Use your own OpenAI, Anthropic, Google and DeepSeek accounts. You pay providers at cost; we never mark up tokens. Model lists load live from each provider.',
    },
    {
        icon: Telescope,
        title: 'Deep research with sources',
        body: 'A Research node searches the web, reads the pages you give it and cross-checks sources, then hands a cited report to the next step. Quick mode for speed, deep mode for depth.',
    },
    {
        icon: Plug,
        title: 'Your own data, via MCP connectors',
        body: 'Connect Notion, GitHub, Google Drive or any remote MCP server. Research nodes use those tools alongside the web, and you see every tool before it is enabled.',
    },
    {
        icon: ImageIcon,
        title: 'Reference images and pro prompts',
        body: 'Upload a product photo or reuse an earlier image so every generation keeps the same product, character or style. Start from a library of detailed, ready-made prompts.',
    },
];

const STEPS = [
    { icon: KeyRound, title: 'Add your keys', body: 'Paste an API key. We test it, encrypt it, and only ever show the last four characters.' },
    {
        icon: Workflow,
        title: 'Drag a flow together',
        body: 'Connect nodes on the canvas. Use any earlier output in a prompt, like {{script.output.text}}.',
    },
    {
        icon: Sparkles,
        title: 'Run it and watch',
        body: 'Each step lights up as it runs. Inspect inputs, outputs and tokens, and re-run from any failed step.',
    },
];

const ROUTING = [
    { task: 'Research & long content', model: 'Claude', fallback: 'Gemini' },
    { task: 'Short copy & captions', model: 'Claude or DeepSeek', fallback: 'OpenAI' },
    { task: 'Image generation', model: 'OpenAI', fallback: 'Gemini' },
    { task: 'Video generation', model: 'Gemini Omni Flash', fallback: 'Veo' },
    { task: 'Cheap bulk tasks', model: 'DeepSeek', fallback: 'Gemini Flash' },
];

const OPEN_SOURCE_PILLARS = [
    {
        icon: Laptop,
        title: 'Run Locally',
        badge: 'Localhost',
        description: 'Install in under 5 minutes on your Mac, Windows, or Linux machine with SQLite or MySQL.',
        bullets: ['Zero subscription or license fees', 'Full offline canvas & local state', 'Complete data privacy on your machine'],
    },
    {
        icon: Server,
        title: 'Self-Host on Any Server',
        badge: 'Production VPS',
        description: 'Deploy to your own Ubuntu, Debian, Docker, or cloud VPS with full control over workers and assets.',
        bullets: ['High-throughput queue workers', 'Scalable media storage & FFmpeg joins', 'Public web forms for clients'],
        featured: true,
    },
    {
        icon: Globe,
        title: 'Publish as a SaaS',
        badge: 'SaaS Ready',
        description: 'Use the built-in multi-tenant architecture and auth system to offer AI automation to your team or clients.',
        bullets: ['Workspace isolation & RBAC', 'Per-workspace AES-256-GCM vault', 'Customizable branding & UI'],
    },
    {
        icon: KeyRound,
        title: 'Bring Your Own Keys',
        badge: 'Direct Pricing',
        description: 'Pay AI providers directly at wholesale cost with no middleman markup or token surcharges.',
        bullets: ['OpenAI, Claude, Gemini & DeepSeek', 'Automatic fallback on rate limits', 'Real-time live model discovery'],
    },
];

const FAQ = [
    {
        q: 'Why do I need my own API keys?',
        a: 'This platform is 100% free and open-source. By using your own API keys for OpenAI, Anthropic, Google, and DeepSeek, you only pay the AI providers directly at raw cost for what you use, with zero platform markup or subscription fees.',
    },
    {
        q: 'Can I install this on localhost or run it on my own server?',
        a: 'Yes! Anyone can clone the repository and run it locally with SQLite or MySQL, or host it on any VPS or cloud server. It is built on modern Laravel and React, making setup quick and standard.',
    },
    {
        q: 'Can I publish this as my own SaaS platform?',
        a: 'Absolutely. The platform includes multi-tenant workspace architecture, role management, per-workspace AES-256-GCM vault encryption, and background job queues, making it completely ready to be customized and deployed as a SaaS.',
    },
    {
        q: 'How are my keys protected?',
        a: 'Each workspace has its own encryption key (AES-256-GCM). Keys are decrypted only in memory at the moment of a call, never sent back to your browser, and scrubbed from logs and error messages.',
    },
    {
        q: 'How is this different from n8n, Make or Zapier?',
        a: 'Those are general automation tools with AI added on. Here AI generation is the core: per-task model routing, built-in image and video jobs, an asset library, and MCP tools as nodes.',
    },
    {
        q: 'What happens when a video takes minutes to render?',
        a: 'The step goes into a waiting state and is checked in the background, so nothing times out. When the video is ready, the flow continues and the file is saved to your library.',
    },
    {
        q: 'Which apps can I publish to?',
        a: 'Google Drive, Sheets, Gmail, Slack, Telegram, Instagram, WhatsApp, LinkedIn, WordPress, Notion and more via our native MCP connectors or custom OAuth integrations.',
    },
];

export default function Welcome() {
    const { auth, name } = usePage<SharedData>().props;
    const primaryCta = auth.user ? { href: route('workflows.index'), label: 'Open your flows' } : { href: route('register'), label: 'Start free' };

    return (
        <>
            <Head title="AI pipelines that research, write and make media">
                <meta
                    name="description"
                    content="Drag AI nodes onto a canvas to research, write, generate images and videos, and publish. Bring your own OpenAI, Claude, Gemini and DeepSeek keys."
                />
            </Head>

            <div className="bg-background text-foreground min-h-screen">
                <header className="bg-background/80 sticky top-0 z-30 border-b backdrop-blur">
                    <nav className="mx-auto flex h-16 max-w-6xl items-center gap-8 px-4 sm:px-6">
                        <Link href="/" className="flex items-center gap-2 font-semibold whitespace-nowrap">
                            <span className="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-violet-500 to-rose-500 text-white">
                                <AppLogoIcon className="size-5 fill-white text-white" />
                            </span>
                            {name}
                        </Link>
                        <div className="text-muted-foreground hidden gap-6 text-sm md:flex">
                            <a href="#features" className="hover:text-foreground">
                                Features
                            </a>
                            <a href="#how" className="hover:text-foreground">
                                How it works
                            </a>
                            <a href="#open-source" className="hover:text-foreground">
                                Open Source
                            </a>
                            <a href="#faq" className="hover:text-foreground">
                                FAQ
                            </a>
                        </div>
                        <div className="ml-auto flex items-center gap-1 text-sm whitespace-nowrap sm:gap-2">
                            {!auth.user && (
                                <Link href={route('login')} className="hover:bg-muted rounded-md px-3 py-2">
                                    Log in
                                </Link>
                            )}
                            <CtaLink href={primaryCta.href}>{primaryCta.label}</CtaLink>
                        </div>
                    </nav>
                </header>

                <main>
                    {/* Hero */}
                    <section className="relative overflow-hidden">
                        <div className="pointer-events-none absolute inset-0 [background-image:radial-gradient(circle_at_1px_1px,var(--color-border)_1px,transparent_0)] [background-size:24px_24px] [mask-image:linear-gradient(to_bottom,black,transparent)]" />
                        <div className="pointer-events-none absolute -top-40 left-1/2 h-96 w-[48rem] -translate-x-1/2 rounded-full bg-gradient-to-r from-violet-500/20 via-sky-500/20 to-rose-500/20 blur-3xl" />

                        <div className="relative mx-auto max-w-6xl px-6 pt-20 pb-16 text-center md:pt-28">
                            <span className="text-muted-foreground bg-background inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs">
                                <Sparkles className="size-3.5 text-violet-500" /> AI-first automation · bring your own keys
                            </span>
                            <h1 className="mx-auto mt-6 max-w-3xl text-4xl font-semibold tracking-tight text-balance md:text-6xl">
                                Automations that research, write and{' '}
                                <span className="bg-gradient-to-r from-violet-500 via-sky-500 to-rose-500 bg-clip-text text-transparent">
                                    make the media
                                </span>
                            </h1>
                            <p className="text-muted-foreground mx-auto mt-6 max-w-2xl text-lg text-balance">
                                Drag nodes onto a canvas to build pipelines that turn one idea into a script, a cover image and a video, using the
                                best model for each step and your own API keys.
                            </p>
                            <div className="mt-8 flex flex-wrap justify-center gap-3">
                                <CtaLink href={primaryCta.href} size="lg">
                                    {primaryCta.label} <ArrowRight className="size-4" />
                                </CtaLink>
                                <a href="#how" className="hover:bg-muted rounded-lg border px-5 py-3 text-sm font-medium">
                                    See how it works
                                </a>
                            </div>
                            <p className="text-muted-foreground mt-4 text-xs">Free plan · no card needed · you pay AI providers directly</p>

                            <PipelinePreview />

                            <p className="text-muted-foreground mt-10 text-sm">
                                Works with your keys for <strong className="text-foreground font-medium">OpenAI</strong> ·{' '}
                                <strong className="text-foreground font-medium">Anthropic Claude</strong> ·{' '}
                                <strong className="text-foreground font-medium">Google Gemini</strong> ·{' '}
                                <strong className="text-foreground font-medium">DeepSeek</strong>
                            </p>
                        </div>
                    </section>

                    {/* Features */}
                    <Section id="features" eyebrow="Why it's different" title="Built for AI generation, not bolted on">
                        <div className="grid gap-4 md:grid-cols-2">
                            {FEATURES.map((f) => (
                                <div key={f.title} className="bg-card rounded-2xl border p-6">
                                    <div className="flex items-center gap-3">
                                        <span className="bg-muted flex size-10 items-center justify-center rounded-xl">
                                            <f.icon className="size-5" />
                                        </span>
                                        <h3 className="font-semibold">{f.title}</h3>
                                        {f.soon && <SoonBadge />}
                                    </div>
                                    <p className="text-muted-foreground mt-3 text-sm leading-relaxed">{f.body}</p>
                                </div>
                            ))}
                        </div>
                    </Section>

                    {/* How it works */}
                    <Section id="how" eyebrow="How it works" title="From API key to finished reel in minutes">
                        <div className="grid gap-4 md:grid-cols-3">
                            {STEPS.map((s, i) => (
                                <div key={s.title} className="relative rounded-2xl border p-6">
                                    <span className="text-muted-foreground absolute top-6 right-6 font-mono text-xs">0{i + 1}</span>
                                    <s.icon className="size-6 text-violet-500" />
                                    <h3 className="mt-4 font-semibold">{s.title}</h3>
                                    <p className="text-muted-foreground mt-2 text-sm leading-relaxed">{s.body}</p>
                                </div>
                            ))}
                        </div>
                    </Section>

                    {/* Routing */}
                    <Section eyebrow="Model routing" title="One default per task, with a fallback">
                        <div className="grid items-center gap-10 md:grid-cols-[1fr_1.2fr]">
                            <div className="text-muted-foreground space-y-4 text-sm leading-relaxed">
                                <p>
                                    Different models are good at different jobs. Pick a default for each kind of task once, and every new node uses
                                    it.
                                </p>
                                <p>
                                    If the first provider errors or hits a rate limit, the step retries with your fallback, so a busy API doesn't stop
                                    the run.
                                </p>
                                <p>New models appear on their own: the list is fetched from each provider when you add a key.</p>
                            </div>
                            <div className="overflow-hidden rounded-2xl border">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/60 text-muted-foreground text-left text-xs">
                                        <tr>
                                            <th className="px-4 py-3 font-medium">Task</th>
                                            <th className="px-4 py-3 font-medium">Default</th>
                                            <th className="px-4 py-3 font-medium">Fallback</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {ROUTING.map((r) => (
                                            <tr key={r.task}>
                                                <td className="px-4 py-3">{r.task}</td>
                                                <td className="px-4 py-3 font-medium">{r.model}</td>
                                                <td className="text-muted-foreground px-4 py-3">{r.fallback}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                <p className="text-muted-foreground border-t px-4 py-2 text-xs">Suggested defaults. You can change all of them.</p>
                            </div>
                        </div>
                    </Section>

                    {/* Security */}
                    <Section eyebrow="Security" title="Your keys are handled like passwords">
                        <div className="grid gap-4 md:grid-cols-3">
                            <TrustCard icon={Lock} title="Encrypted per workspace">
                                AES-256-GCM with a separate data key for every workspace. Keys are decrypted only in memory, at the moment of the
                                call.
                            </TrustCard>
                            <TrustCard icon={ShieldCheck} title="Never shown again">
                                After saving, you see only the last four characters. Keys are stripped from logs, errors and step outputs.
                            </TrustCard>
                            <TrustCard icon={Layers} title="Limits that protect your credits">
                                Retries are capped, and safeguards on steps, loops and run time stop a runaway flow from burning through your provider
                                balance.
                            </TrustCard>
                        </div>
                    </Section>

                    {/* Open Source / Free Platform */}
                    <Section id="open-source" eyebrow="100% Free & Open Source" title="Free for everyone — self-host or use as SaaS">
                        <p className="text-muted-foreground mx-auto -mt-6 mb-10 max-w-2xl text-center text-sm">
                            No paywalls, no recurring subscriptions, and no hidden token markups. Install on your localhost, self-host on a private
                            server, or deploy your own AI SaaS.
                        </p>
                        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                            {OPEN_SOURCE_PILLARS.map((p) => (
                                <div
                                    key={p.title}
                                    className={cn(
                                        'bg-card flex flex-col rounded-2xl border p-6',
                                        p.featured && 'border-violet-500 shadow-lg shadow-violet-500/10',
                                    )}
                                >
                                    <div className="flex items-center justify-between">
                                        <div className="flex size-10 items-center justify-center rounded-xl bg-violet-500/10 text-violet-500">
                                            <p.icon className="size-5" />
                                        </div>
                                        <span
                                            className={cn(
                                                'rounded-full px-2.5 py-0.5 text-xs font-medium',
                                                p.featured ? 'bg-violet-500 text-white' : 'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            {p.badge}
                                        </span>
                                    </div>
                                    <h3 className="mt-4 text-base font-semibold">{p.title}</h3>
                                    <p className="text-muted-foreground mt-1 text-xs leading-relaxed">{p.description}</p>
                                    <ul className="mt-6 flex-1 space-y-2 text-sm">
                                        {p.bullets.map((b) => (
                                            <li key={b} className="flex gap-2 text-xs">
                                                <Check className="mt-0.5 size-3.5 shrink-0 text-emerald-500" /> <span>{b}</span>
                                            </li>
                                        ))}
                                    </ul>
                                    <CtaLink href={primaryCta.href} variant={p.featured ? 'solid' : 'outline'} className="mt-6 justify-center">
                                        Get Started Free
                                    </CtaLink>
                                </div>
                            ))}
                        </div>
                    </Section>

                    {/* FAQ */}
                    <Section id="faq" eyebrow="FAQ" title="Questions people ask">
                        <div className="mx-auto max-w-3xl divide-y rounded-2xl border">
                            {FAQ.map((item) => (
                                <details key={item.q} className="group p-5">
                                    <summary className="flex cursor-pointer list-none items-center justify-between font-medium">
                                        {item.q}
                                        <span className="text-muted-foreground transition group-open:rotate-45">+</span>
                                    </summary>
                                    <p className="text-muted-foreground mt-3 text-sm leading-relaxed">{item.a}</p>
                                </details>
                            ))}
                        </div>
                    </Section>

                    {/* Final CTA */}
                    <section className="px-6 pb-24">
                        <div className="mx-auto max-w-6xl overflow-hidden rounded-3xl bg-gradient-to-br from-violet-600 via-indigo-600 to-rose-500 p-10 text-center text-white md:p-16">
                            <h2 className="text-3xl font-semibold tracking-tight md:text-4xl">Build your first AI pipeline today</h2>
                            <p className="mx-auto mt-4 max-w-xl text-white/80">
                                Add a key, drop in three nodes, press Run. Script, cover image and video in one flow.
                            </p>
                            <Link
                                href={primaryCta.href}
                                className="mt-8 inline-flex items-center gap-2 rounded-lg bg-white px-6 py-3 text-sm font-semibold text-neutral-900 hover:bg-white/90"
                            >
                                {primaryCta.label} <ArrowRight className="size-4" />
                            </Link>
                        </div>
                    </section>
                </main>

                <footer className="border-t">
                    <div className="text-muted-foreground mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-8 text-sm">
                        <span>
                            © {new Date().getFullYear()} {name}. Built by{' '}
                            <a
                                href="https://larwell.com"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="hover:text-foreground font-medium underline underline-offset-4"
                            >
                                Larwell
                            </a>
                        </span>
                        <div className="flex gap-6">
                            <a href="#features" className="hover:text-foreground">
                                Features
                            </a>
                            <a href="#open-source" className="hover:text-foreground">
                                Open Source
                            </a>
                            <a href="https://larwell.com" target="_blank" rel="noopener noreferrer" className="hover:text-foreground">
                                Larwell
                            </a>
                            <Link href={route('login')} className="hover:text-foreground">
                                Log in
                            </Link>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}

function Section({ id, eyebrow, title, children }: { id?: string; eyebrow: string; title: string; children: ReactNode }) {
    return (
        <section id={id} className="scroll-mt-20 px-6 py-20">
            <div className="mx-auto max-w-6xl">
                <p className="text-center text-xs font-semibold tracking-widest text-violet-500 uppercase">{eyebrow}</p>
                <h2 className="mx-auto mt-3 mb-12 max-w-2xl text-center text-3xl font-semibold tracking-tight text-balance md:text-4xl">{title}</h2>
                {children}
            </div>
        </section>
    );
}

function CtaLink({
    href,
    children,
    size = 'md',
    variant = 'solid',
    className,
}: {
    href: string;
    children: ReactNode;
    size?: 'md' | 'lg';
    variant?: 'solid' | 'outline';
    className?: string;
}) {
    return (
        <Link
            href={href}
            className={cn(
                'inline-flex items-center gap-2 rounded-lg font-medium transition',
                size === 'lg' ? 'px-5 py-3 text-sm' : 'px-4 py-2 text-sm',
                variant === 'solid' ? 'bg-foreground text-background hover:opacity-90' : 'hover:bg-muted border',
                className,
            )}
        >
            {children}
        </Link>
    );
}

function SoonBadge() {
    return <span className="text-muted-foreground ml-auto rounded-full border px-2 py-0.5 text-[10px] font-medium uppercase">Coming soon</span>;
}

function TrustCard({ icon: Icon, title, children }: { icon: typeof Lock; title: string; children: ReactNode }) {
    return (
        <div className="rounded-2xl border p-6">
            <Icon className="size-6 text-emerald-500" />
            <h3 className="mt-4 font-semibold">{title}</h3>
            <p className="text-muted-foreground mt-2 text-sm leading-relaxed">{children}</p>
        </div>
    );
}

/** A static picture of the canvas running the example flow. */
function PipelinePreview() {
    const node = (icon: typeof Type, title: string, sub: string, accent: string, status: 'done' | 'running' | 'soon', delay: number) => {
        const Icon = icon;
        return (
            <div
                className={cn(
                    'bg-card relative w-full max-w-60 shrink-0 rounded-xl border border-l-4 p-3 text-left shadow-sm motion-safe:animate-[fadeUp_0.6s_ease-out_both] md:w-44',
                    accent,
                    status === 'soon' && 'opacity-60',
                )}
                style={{ animationDelay: `${delay}ms` }}
            >
                <div className="flex items-center gap-2">
                    <Icon className="text-muted-foreground size-4" />
                    <span className="truncate text-sm font-medium">{title}</span>
                </div>
                <div className="text-muted-foreground mt-1 truncate text-[11px]">{sub}</div>
                <div className="mt-2 text-[10px]">
                    {status === 'done' && (
                        <span className="inline-flex items-center gap-1 text-emerald-600">
                            <CheckCircle2 className="size-3" /> succeeded
                        </span>
                    )}
                    {status === 'running' && (
                        <span className="inline-flex items-center gap-1 text-amber-600">
                            <Clock className="size-3 animate-pulse" /> rendering video…
                        </span>
                    )}
                    {status === 'soon' && <span className="text-muted-foreground">coming soon</span>}
                </div>
            </div>
        );
    };
    const wire = <div className="h-5 w-px shrink-0 bg-neutral-300 md:h-px md:w-10 dark:bg-neutral-700" />;

    return (
        <div className="bg-background/60 mx-auto mt-14 max-w-5xl rounded-2xl border p-2 shadow-2xl shadow-violet-500/10 backdrop-blur">
            <style>{`@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}`}</style>
            <div className="text-muted-foreground flex items-center gap-2 border-b px-3 py-2 text-xs">
                <span className="size-2.5 rounded-full bg-red-400" />
                <span className="size-2.5 rounded-full bg-amber-400" />
                <span className="size-2.5 rounded-full bg-emerald-400" />
                <span className="ml-3">Weekly reel from a topic</span>
            </div>
            <div className="overflow-x-auto [background-image:radial-gradient(circle_at_1px_1px,var(--color-border)_1px,transparent_0)] [background-size:18px_18px] p-6 md:p-10">
                <div className="flex flex-col items-center md:mx-auto md:w-max md:flex-row">
                    {node(MousePointerClick, 'Trigger', 'topic: “monsoon tea”', 'border-l-neutral-500', 'done', 0)}
                    {wire}
                    {node(Type, 'Write script', 'Claude', 'border-l-violet-500', 'done', 150)}
                    {wire}
                    <div className="flex w-full flex-col items-center gap-3 md:w-auto">
                        {node(ImageIcon, 'Cover image', 'OpenAI image model', 'border-l-sky-500', 'done', 300)}
                        {node(Clapperboard, 'Reel video', 'Gemini Omni Flash', 'border-l-rose-500', 'running', 450)}
                    </div>
                    {wire}
                    {node(Send, 'Approve & post', 'Instagram · Drive', 'border-l-emerald-500', 'soon', 600)}
                </div>
            </div>
        </div>
    );
}
