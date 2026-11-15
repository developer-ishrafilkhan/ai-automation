import { CharacterPicker } from '@/components/flow/character-picker';
import { FormFieldsEditor } from '@/components/flow/form-fields-editor';
import { FormSharePanel } from '@/components/flow/form-share-panel';
import { type ReferenceAsset, ReferenceImagesField } from '@/components/flow/reference-images-field';
import { ResearchSettings } from '@/components/flow/research-settings';
import { NativeSelect, Textarea } from '@/components/form-controls';
import { RunStatusBadge } from '@/components/run-status';
import { StepDetail } from '@/components/step-detail';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { api } from '@/lib/api';
import { promptBlanks } from '@/lib/prompt-blanks';
import { cn } from '@/lib/utils';
import {
    type Character,
    type Credential,
    type Execution,
    type FormField,
    type FormState,
    type GraphNode,
    type McpServer,
    type NodeData,
    PROVIDER_LABELS,
    type RunStatus,
    type Step,
    TASK_PROVIDERS,
    type TaskType,
    type Workflow,
} from '@/types/automation';
import { Head, Link } from '@inertiajs/react';
import {
    addEdge,
    Background,
    type Connection,
    Controls,
    type Edge,
    Handle,
    MiniMap,
    type Node,
    type NodeProps,
    Position,
    ReactFlow,
    ReactFlowProvider,
    useEdgesState,
    useNodesState,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import {
    Clapperboard,
    ClipboardList,
    Globe,
    ImageIcon,
    Loader2,
    MousePointerClick,
    Play,
    RotateCcw,
    Telescope,
    Trash2,
    Type,
    Upload,
} from 'lucide-react';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';

interface Props {
    workflow: Workflow;
    credentials: Credential[];
    executions: Pick<Execution, 'id' | 'status' | 'created_at' | 'finished_at'>[];
    mcpServers: McpServer[];
    referenceAssets: ReferenceAsset[];
    characters: Character[];
    form: FormState;
}

const NODE_TYPES_META: Record<string, { title: string; icon: typeof Type; task?: TaskType; color: string; defaults: NodeData }> = {
    'trigger.manual': { title: 'Manual trigger', icon: MousePointerClick, color: 'border-l-neutral-500', defaults: { input: {} } },
    'ai.text': {
        title: 'Text / Chat',
        icon: Type,
        task: 'text',
        color: 'border-l-violet-500',
        defaults: { prompt: 'Write a 30-second reel script about {{trigger.output.topic}}.', system: '' },
    },
    'ai.research': {
        title: 'Research',
        icon: Telescope,
        task: 'research',
        color: 'border-l-amber-500',
        defaults: { mode: 'deep', prompt: 'Research {{trigger.output.topic}}: key facts, recent trends and what people are asking. Cite sources.' },
    },
    'ai.image': {
        title: 'Image',
        icon: ImageIcon,
        task: 'image',
        color: 'border-l-sky-500',
        defaults: { prompt_mode: 'ai', brief: '' },
    },
    'ai.video': {
        title: 'Video',
        icon: Clapperboard,
        task: 'video',
        color: 'border-l-rose-500',
        defaults: { prompt_mode: 'ai', brief: '', aspect_ratio: '9:16' },
    },
};

const RunContext = createContext<Record<string, Step>>({});

function FlowNode({ id, type, data, selected }: NodeProps<Node<NodeData>>) {
    const meta = NODE_TYPES_META[type];
    const step = useContext(RunContext)[id];
    const fieldCount = data.form?.fields?.length ?? 0;
    const Icon = fieldCount ? ClipboardList : meta.icon;

    return (
        <div className={cn('bg-card w-56 rounded-lg border border-l-4 shadow-sm', meta.color, selected && 'ring-primary ring-2')}>
            {type !== 'trigger.manual' && <Handle type="target" position={Position.Left} className="!size-3" />}
            <div className="flex items-center gap-2 px-3 py-2">
                <Icon className="text-muted-foreground size-4 shrink-0" />
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-medium">{data.label || meta.title}</div>
                    <div className="text-muted-foreground truncate font-mono text-[10px]">
                        {data.key || id}
                        {fieldCount > 0 && ` · ${fieldCount} question${fieldCount > 1 ? 's' : ''}`}
                        {data.hide_in_form && ' · hidden from form'}
                        {type === 'ai.video' && !!data.duration && ` · ~${data.duration < 60 ? `${data.duration} s` : `${data.duration / 60} min`}`}
                    </div>
                </div>
                {step?.progress && (
                    <span className="text-muted-foreground font-mono text-[10px]" title="Scenes ready">
                        {step.progress.done}/{step.progress.total}
                    </span>
                )}
                {step && <RunStatusBadge status={step.status} />}
            </div>
            {meta.task && (
                <div className="text-muted-foreground truncate border-t px-3 py-1 text-[11px]">{data.model || 'workspace default model'}</div>
            )}
            <Handle type="source" position={Position.Right} className="!size-3" />
        </div>
    );
}

const nodeTypes = Object.fromEntries(Object.keys(NODE_TYPES_META).map((t) => [t, FlowNode]));

export default function Editor(props: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Flows', href: '/workflows' },
                { title: props.workflow.name, href: `/workflows/${props.workflow.id}` },
            ]}
        >
            <Head title={props.workflow.name} />
            <ReactFlowProvider>
                <EditorCanvas {...props} />
            </ReactFlowProvider>
        </AppLayout>
    );
}

function EditorCanvas({ workflow, credentials, executions: initialExecutions, mcpServers, referenceAssets, characters, form: initialForm }: Props) {
    const [nodes, setNodes, onNodesChange] = useNodesState<Node<NodeData>>(workflow.draft_graph.nodes as Node<NodeData>[]);
    const [edges, setEdges, onEdgesChange] = useEdgesState<Edge>(
        workflow.draft_graph.edges.map((e) => ({ ...e, id: e.id ?? `${e.source}-${e.target}` })),
    );
    const [name, setName] = useState(workflow.name);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [saveState, setSaveState] = useState<'saved' | 'dirty' | 'saving' | 'error'>('saved');
    const [message, setMessage] = useState<string | null>(null);
    const [execution, setExecution] = useState<Execution | null>(null);
    const [executions, setExecutions] = useState(initialExecutions);
    const firstRender = useRef(true);
    const [form, setForm] = useState(initialForm);
    const [liveVersion, setLiveVersion] = useState(workflow.active_version?.version ?? null);
    const [showShare, setShowShare] = useState(false);
    const [suggesting, setSuggesting] = useState(false);

    const graph = useMemo(
        () => ({
            nodes: nodes.map(({ id, type, position, data }) => ({ id, type: type!, position, data })),
            edges: edges.map(({ id, source, target }) => ({ id, source, target })),
        }),
        [nodes, edges],
    );
    // Selecting or hovering a node changes `nodes` too; only save when saved content changes.
    const snapshot = JSON.stringify({ name, graph });

    const save = useCallback(async () => {
        setSaveState('saving');
        try {
            await api('PUT', `/workflows/${workflow.id}`, JSON.parse(snapshot));
            setSaveState('saved');
            // The public form runs the published version; a saved edit makes it out of date.
            setForm((f) => (f.enabled ? { ...f, outdated: true } : f));
        } catch (e) {
            setSaveState('error');
            setMessage((e as Error).message);
            throw e;
        }
    }, [workflow.id, snapshot]);

    // Autosave the draft shortly after any change (positions included).
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        setSaveState('dirty');
        const timer = setTimeout(() => save().catch(() => {}), 800);
        return () => clearTimeout(timer);
    }, [save]);

    // Poll the open run until it finishes.
    useEffect(() => {
        if (!execution || ['succeeded', 'failed'].includes(execution.status)) return;
        const timer = setTimeout(async () => {
            const fresh = await api<Execution>('GET', `/runs/${execution.id}`);
            setExecution(fresh);
            setExecutions((list) => list.map((e) => (e.id === fresh.id ? { ...e, status: fresh.status } : e)));
        }, 2000);
        return () => clearTimeout(timer);
    }, [execution]);

    const openRun = async (id: number) => setExecution(await api<Execution>('GET', `/runs/${id}`));

    const run = async () => {
        setMessage(null);
        try {
            await save();
            const { execution_id } = await api<{ execution_id: number }>('POST', `/workflows/${workflow.id}/run`);
            setExecutions((list) => [
                { id: execution_id, status: 'running' as RunStatus, created_at: new Date().toISOString(), finished_at: null },
                ...list,
            ]);
            await openRun(execution_id);
        } catch (e) {
            setMessage((e as Error).message);
        }
    };

    const publish = async () => {
        try {
            await save();
            const { version } = await api<{ version: number }>('POST', `/workflows/${workflow.id}/publish`);
            setMessage(`Published as version ${version}.${form.enabled ? ' The public form now uses it.' : ''}`);
            setForm((f) => ({ ...f, outdated: false }));
            setLiveVersion(version);
        } catch (e) {
            setMessage((e as Error).message);
        }
    };

    const retry = async () => {
        if (!execution) return;
        await api('POST', `/runs/${execution.id}/retry`);
        await openRun(execution.id);
    };

    const addNode = (type: string) => {
        const meta = NODE_TYPES_META[type];
        const count = nodes.filter((n) => n.type === type).length + 1;
        const id = `${type.split('.')[1]}_${Date.now().toString(36)}`;
        const firstKeys: Record<string, string> = { 'ai.text': 'script', 'ai.research': 'research', 'ai.image': 'cover', 'ai.video': 'clip' };
        const key = count === 1 && firstKeys[type] ? firstKeys[type] : `${type.split('.')[1]}${count}`;
        const rightmost = Math.max(0, ...nodes.map((n) => n.position.x));
        setNodes((list) => [
            ...list,
            { id, type, position: { x: rightmost + 300, y: 40 * (count - 1) }, data: { ...meta.defaults, key, label: meta.title } },
        ]);
        setSelectedId(id);
    };

    const trigger = nodes.find((n) => n.type === 'trigger.manual');
    const suggestForm = async () => {
        if (!trigger) return;
        setSuggesting(true);
        setMessage(null);
        try {
            await save();
            const result = await api<{ fields: FormField[]; title: string; description: string }>('POST', `/workflows/${workflow.id}/form/suggest`);
            updateNode(trigger.id, { form: { fields: result.fields }, label: trigger.data.label === 'Manual trigger' ? 'Form' : trigger.data.label });
            setForm((f) => ({
                ...f,
                settings: {
                    ...f.settings,
                    title: f.settings.title === workflow.name && result.title ? result.title : f.settings.title,
                    description: f.settings.description || result.description,
                },
            }));
        } catch (e) {
            setMessage((e as Error).message);
        } finally {
            setSuggesting(false);
        }
    };

    const updateNode = (id: string, patch: Partial<NodeData>) =>
        setNodes((list) => list.map((n) => (n.id === id ? { ...n, data: { ...n.data, ...patch } } : n)));

    const deleteNode = (id: string) => {
        setNodes((list) => list.filter((n) => n.id !== id));
        setEdges((list) => list.filter((e) => e.source !== id && e.target !== id));
        setSelectedId(null);
    };

    const onConnect = useCallback((connection: Connection) => setEdges((list) => addEdge(connection, list)), [setEdges]);

    const selected = nodes.find((n) => n.id === selectedId) ?? null;
    const stepsByNode = useMemo(() => Object.fromEntries((execution?.steps ?? []).map((s) => [s.node_id, s])), [execution]);

    return (
        <div className="flex h-[calc(100vh-4rem)] flex-col">
            <div className="flex flex-wrap items-center gap-2 border-b px-4 py-2">
                <Input className="h-8 w-72" value={name} onChange={(e) => setName(e.target.value)} />
                <span className="text-muted-foreground text-xs">
                    {{ saved: 'Draft saved', dirty: 'Unsaved changes', saving: 'Saving…', error: 'Save failed' }[saveState]}
                    {liveVersion && ` · live: v${liveVersion}`}
                </span>
                {form.enabled && form.outdated && (
                    <button
                        type="button"
                        onClick={publish}
                        className="rounded-full border border-amber-400 bg-amber-50 px-2.5 py-0.5 text-xs text-amber-800 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
                        title="The public form runs the published version"
                    >
                        Form visitors still get v{liveVersion}. <strong className="underline">Publish changes</strong>
                    </button>
                )}
                <div className="ml-auto flex gap-2">
                    {Object.entries(NODE_TYPES_META)
                        .filter(([type]) => type !== 'trigger.manual')
                        .map(([type, meta]) => (
                            <Button key={type} size="sm" variant="outline" onClick={() => addNode(type)}>
                                <meta.icon className="size-4" /> {meta.title}
                            </Button>
                        ))}
                    <Button
                        size="sm"
                        variant={form.enabled ? 'secondary' : 'outline'}
                        onClick={() => {
                            setSelectedId(null);
                            setShowShare(true);
                        }}
                    >
                        <Globe className="size-4" /> {form.enabled ? 'Form is live' : 'Share as form'}
                    </Button>
                    <Button size="sm" variant="outline" onClick={publish}>
                        <Upload className="size-4" /> Publish
                    </Button>
                    <Button size="sm" onClick={run}>
                        <Play className="size-4" /> Run
                    </Button>
                </div>
            </div>
            {message && (
                <div className="bg-muted flex items-center justify-between px-4 py-1.5 text-sm">
                    {message}
                    <button className="text-muted-foreground text-xs" onClick={() => setMessage(null)}>
                        dismiss
                    </button>
                </div>
            )}

            <div className="flex min-h-0 flex-1">
                <div className="relative flex-1">
                    <RunContext.Provider value={stepsByNode}>
                        <ReactFlow
                            nodes={nodes}
                            edges={edges}
                            nodeTypes={nodeTypes}
                            onNodesChange={onNodesChange}
                            onEdgesChange={onEdgesChange}
                            onConnect={onConnect}
                            onNodeClick={(_, node) => {
                                setSelectedId(node.id);
                                setShowShare(false);
                            }}
                            onPaneClick={() => setSelectedId(null)}
                            deleteKeyCode={['Backspace', 'Delete']}
                            colorMode="system"
                            fitView
                            fitViewOptions={{ maxZoom: 1 }}
                        >
                            <Background />
                            <Controls />
                            <MiniMap pannable zoomable />
                        </ReactFlow>
                    </RunContext.Provider>
                </div>

                <aside className="w-96 shrink-0 overflow-y-auto border-l">
                    {selected ? (
                        <NodeSettings
                            onSuggestForm={suggestForm}
                            suggestingForm={suggesting}
                            node={selected as GraphNode}
                            nodes={nodes as GraphNode[]}
                            edges={edges}
                            credentials={credentials}
                            mcpServers={mcpServers}
                            referenceAssets={referenceAssets}
                            characters={characters}
                            step={stepsByNode[selected.id]}
                            onChange={(patch) => updateNode(selected.id, patch)}
                            onDelete={() => deleteNode(selected.id)}
                        />
                    ) : showShare ? (
                        <FormSharePanel
                            key={String(form.enabled)}
                            workflowId={workflow.id}
                            form={form}
                            fieldCount={trigger?.data.form?.fields?.length ?? 0}
                            beforeSave={save}
                            onChange={(state) => {
                                setForm(state);
                                setLiveVersion(state.version);
                            }}
                            onClose={() => setShowShare(false)}
                        />
                    ) : (
                        <RunPanel execution={execution} executions={executions} onOpen={openRun} onRetry={retry} />
                    )}
                </aside>
            </div>
        </div>
    );
}

/** "{{trigger.output.x}}" entries: the character a form visitor picks. */
function formCharacterRefs(node: GraphNode): string[] {
    return ((node.data.character_ids ?? []) as unknown[]).filter((v): v is string => typeof v === 'string');
}

/** Let an Image/Video step use the character(s) a visitor picks on the form. */
function FormCharacterChoice({
    node,
    nodes,
    refs,
    onChange,
}: {
    node: GraphNode;
    nodes: GraphNode[];
    refs: string[];
    onChange: (patch: Partial<NodeData>) => void;
}) {
    const sources = nodes
        .filter((n) => n.type === 'trigger.manual' && refs.includes(n.data.key || n.id))
        .flatMap((n) =>
            (n.data.form?.fields ?? [])
                .filter((f) => f.type === 'character')
                .map((f) => ({ label: f.label || f.key, token: `{{${n.data.key || n.id}.output.${f.key}}}`, multiple: !!f.multiple })),
        );
    if (sources.length === 0) return null;

    const current = (node.data.character_ids ?? []) as (number | string)[];
    const fixed = current.filter((v) => typeof v === 'number').length;

    return (
        <div className="space-y-1 rounded-lg border border-dashed p-2">
            <div className="text-xs font-medium">Character chosen on the form</div>
            {sources.map((s) => (
                <label key={s.token} className="flex items-center gap-2 text-xs">
                    <input
                        type="checkbox"
                        checked={current.includes(s.token)}
                        onChange={(e) => onChange({ character_ids: e.target.checked ? [...current, s.token] : current.filter((v) => v !== s.token) })}
                    />
                    Use the visitor’s answer to “{s.label}”{s.multiple ? ' (up to 3)' : ''}
                </label>
            ))}
            {fixed > 0 && current.some((v) => typeof v === 'string') && (
                <p className="text-[11px] text-amber-600">
                    Fixed characters above are used too. Keep the total at 3 or fewer, or the run stops with an error.
                </p>
            )}
        </div>
    );
}

/** Form answers the flow's steps read, as {{trigger.output.<key>}}. */
function formKeysUsed(trigger: GraphNode, nodes: GraphNode[]): string[] {
    const name = (trigger.data.key || trigger.id).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const pattern = new RegExp(`\\{\\{\\s*${name}\\.output\\.([A-Za-z0-9_-]+)`, 'g');
    const keys = new Set<string>();
    nodes.filter((n) => n.id !== trigger.id).forEach((n) => [...JSON.stringify(n.data).matchAll(pattern)].forEach((m) => keys.add(m[1])));
    return [...keys];
}

/** Keys of every node upstream of `id`, i.e. what this node may reference. */
function upstreamKeys(id: string, nodes: GraphNode[], edges: Edge[]): string[] {
    const seen = new Set<string>();
    const walk = (target: string) =>
        edges
            .filter((e) => e.target === target)
            .forEach((e) => {
                if (!seen.has(e.source)) {
                    seen.add(e.source);
                    walk(e.source);
                }
            });
    walk(id);
    return nodes.filter((n) => seen.has(n.id)).map((n) => n.data.key || n.id);
}

function NodeSettings({
    node,
    nodes,
    edges,
    credentials,
    mcpServers,
    referenceAssets,
    characters,
    step,
    onChange,
    onDelete,
    onSuggestForm,
    suggestingForm,
}: {
    onSuggestForm: () => void;
    suggestingForm: boolean;
    node: GraphNode;
    nodes: GraphNode[];
    edges: Edge[];
    credentials: Credential[];
    mcpServers: McpServer[];
    referenceAssets: ReferenceAsset[];
    characters: Character[];
    step?: Step;
    onChange: (patch: Partial<NodeData>) => void;
    onDelete: () => void;
}) {
    const meta = NODE_TYPES_META[node.type];
    const task = meta.task;
    const usable = task ? credentials.filter((c) => TASK_PROVIDERS[task].includes(c.provider)) : [];
    const models = credentials.find((c) => c.id === node.data.credential_id)?.models ?? [];
    const refs = upstreamKeys(node.id, nodes, edges);
    const isMedia = node.type === 'ai.image' || node.type === 'ai.video';
    // Mirrors PromptAssembler::usesWriter(): older nodes with only a hand-written prompt stay manual.
    const promptMode = node.data.prompt_mode ?? (node.data.brief !== undefined ? 'ai' : 'manual');
    const usesBrief = isMedia && promptMode === 'ai';
    const [inputJson, setInputJson] = useState(JSON.stringify(node.data.input ?? {}, null, 2));
    const [inputError, setInputError] = useState<string | null>(null);

    useEffect(() => setInputJson(JSON.stringify(node.data.input ?? {}, null, 2)), [node.id]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <div className="space-y-4 p-4">
            <div className="flex items-center gap-2">
                <meta.icon className="size-4" />
                <h2 className="flex-1 font-semibold">{node.type === 'trigger.manual' && node.data.form?.fields?.length ? 'Form' : meta.title}</h2>
                {node.type !== 'trigger.manual' && (
                    <Button variant="ghost" size="icon" onClick={onDelete} title="Delete node">
                        <Trash2 className="size-4" />
                    </Button>
                )}
            </div>

            <div className="grid grid-cols-2 gap-2">
                <div className="space-y-1">
                    <Label>Name</Label>
                    <Input value={node.data.label ?? ''} onChange={(e) => onChange({ label: e.target.value })} />
                </div>
                <div className="space-y-1">
                    <Label>Reference key</Label>
                    <Input
                        className="font-mono"
                        value={node.data.key ?? ''}
                        placeholder={node.id}
                        onChange={(e) => onChange({ key: e.target.value.replace(/[^A-Za-z0-9_-]/g, '') })}
                    />
                </div>
            </div>

            {node.type === 'trigger.manual' && (
                <>
                    <FormFieldsEditor
                        fields={node.data.form?.fields ?? []}
                        onChange={(fields) => onChange({ form: { fields } })}
                        testValues={node.data.input ?? {}}
                        onTestValuesChange={(input) => {
                            onChange({ input });
                            setInputJson(JSON.stringify(input, null, 2));
                        }}
                        usedKeys={formKeysUsed(node, nodes)}
                        triggerKey={node.data.key || node.id}
                        onSuggest={onSuggestForm}
                        suggesting={suggestingForm}
                        characters={characters}
                    />
                    <details className="space-y-1">
                        <summary className="text-muted-foreground cursor-pointer text-xs">Test input as JSON</summary>
                        <Textarea
                            className="mt-1 min-h-32"
                            value={inputJson}
                            onChange={(e) => {
                                setInputJson(e.target.value);
                                try {
                                    onChange({ input: JSON.parse(e.target.value) });
                                    setInputError(null);
                                } catch {
                                    setInputError('Not valid JSON yet');
                                }
                            }}
                        />
                        <p className="text-muted-foreground text-xs">
                            {inputError ?? (
                                <>
                                    Used when you press Run. Later nodes read these as <code>{`{{${node.data.key || node.id}.output.<field>}}`}</code>
                                </>
                            )}
                        </p>
                    </details>
                </>
            )}

            {task && (
                <>
                    <label className="flex items-center gap-2 text-xs">
                        <input
                            type="checkbox"
                            checked={!!node.data.hide_in_form}
                            onChange={(e) => onChange({ hide_in_form: e.target.checked || undefined })}
                        />
                        Hide from public form results (a helper step visitors don’t need to see)
                    </label>

                    <div className="space-y-1">
                        <Label>Model</Label>
                        <NativeSelect
                            value={node.data.credential_id ?? ''}
                            onChange={(e) => onChange({ credential_id: e.target.value ? Number(e.target.value) : null, model: '' })}
                        >
                            <option value="">Workspace default for {task}</option>
                            {usable.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.label} ({PROVIDER_LABELS[c.provider]})
                                </option>
                            ))}
                        </NativeSelect>
                        {node.data.credential_id ? (
                            <>
                                <Input
                                    list={`models-${node.id}`}
                                    placeholder="model id"
                                    value={node.data.model ?? ''}
                                    onChange={(e) => onChange({ model: e.target.value })}
                                />
                                <datalist id={`models-${node.id}`}>
                                    {models.map((m) => (
                                        <option key={m} value={m} />
                                    ))}
                                </datalist>
                            </>
                        ) : null}
                        {usable.length === 0 && (
                            <p className="text-xs text-amber-600">
                                No working key for {task} yet ({TASK_PROVIDERS[task].map((p) => PROVIDER_LABELS[p]).join(', ')}).{' '}
                                <Link href="/keys" className="underline">
                                    Add one
                                </Link>
                            </p>
                        )}
                    </div>

                    {node.type === 'ai.text' && (
                        <div className="space-y-1">
                            <Label>System instructions (optional)</Label>
                            <Textarea className="min-h-16" value={node.data.system ?? ''} onChange={(e) => onChange({ system: e.target.value })} />
                        </div>
                    )}

                    <div className="space-y-1">
                        {isMedia && (
                            <div className="bg-muted mb-2 grid grid-cols-2 gap-1 rounded-lg p-1 text-xs">
                                {(
                                    [
                                        ['ai', 'AI writes the prompt'],
                                        ['manual', "I'll write it"],
                                    ] as const
                                ).map(([mode, label]) => (
                                    <button
                                        key={mode}
                                        type="button"
                                        onClick={() => onChange({ prompt_mode: mode })}
                                        className={cn(
                                            'rounded-md px-2 py-1.5 font-medium',
                                            promptMode === mode ? 'bg-background shadow-sm' : 'text-muted-foreground',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        )}
                        <Label>
                            {node.type === 'ai.research'
                                ? 'What should it research?'
                                : usesBrief
                                  ? `What do you want this ${node.type === 'ai.video' ? 'video' : 'image'} to show?`
                                  : 'Prompt'}
                        </Label>
                        <Textarea
                            className={usesBrief ? 'min-h-24 font-sans text-sm' : 'min-h-40'}
                            placeholder={
                                usesBrief
                                    ? node.type === 'ai.video'
                                        ? 'e.g. Ishrafil unboxes our green tea, smiles and says “calm focus, no jitters”'
                                        : 'e.g. Tip #2 post: Ishrafil with our green tea, headline “Calm Focus”, 30% off this week'
                                    : undefined
                            }
                            value={(usesBrief ? node.data.brief : node.data.prompt) ?? ''}
                            onChange={(e) => onChange(usesBrief ? { brief: e.target.value } : { prompt: e.target.value })}
                        />
                        {usesBrief && (
                            <p className="text-muted-foreground text-[11px]">
                                A short brief is enough. The AI writes the detailed prompt in your house style (
                                <Link href="/prompt-writer" className="underline">
                                    train it
                                </Link>
                                ), looking at your references and characters.
                            </p>
                        )}
                        {promptBlanks(usesBrief ? node.data.brief : node.data.prompt).length > 0 && (
                            <div className="rounded-md bg-amber-500/10 p-2 text-[11px] text-amber-700 dark:text-amber-300">
                                <strong>Fill in before running</strong> (otherwise the AI copies the example text):{' '}
                                {promptBlanks(usesBrief ? node.data.brief : node.data.prompt).map((blank) => (
                                    <code key={blank} className="mr-1 rounded bg-amber-500/15 px-1">
                                        {blank}
                                    </code>
                                ))}
                            </div>
                        )}
                        <div className="flex flex-wrap gap-1">
                            {refs.length === 0 && <p className="text-muted-foreground text-xs">Connect an earlier node to use its output here.</p>}
                            {refs.flatMap((key) => {
                                const ref = nodes.find((n) => (n.data.key || n.id) === key);
                                const fields =
                                    ref?.type === 'ai.text' || ref?.type === 'ai.research'
                                        ? ['text']
                                        : ref?.type === 'trigger.manual'
                                          ? ref.data.form?.fields?.length
                                              ? ref.data.form.fields
                                                    .filter((f) => f.type !== 'image')
                                                    .map((f) => (f.type === 'character' ? `${f.key}_name` : f.key))
                                              : [Object.keys(ref.data.input ?? {})[0] ?? 'field']
                                          : ['url'];
                                return fields.map((field) => {
                                    const token = `{{${key}.output.${field}}}`;
                                    return (
                                        <button
                                            key={token}
                                            type="button"
                                            className="bg-muted hover:bg-muted/70 rounded px-1.5 py-0.5 font-mono text-[11px]"
                                            onClick={() =>
                                                onChange(
                                                    usesBrief
                                                        ? { brief: `${node.data.brief ?? ''}${token}` }
                                                        : { prompt: `${node.data.prompt ?? ''}${token}` },
                                                )
                                            }
                                            title="Insert"
                                        >
                                            {token}
                                        </button>
                                    );
                                });
                            })}
                        </div>
                    </div>

                    {(node.type === 'ai.image' || node.type === 'ai.video') && (
                        <>
                            <CharacterPicker
                                characters={characters}
                                value={(node.data.character_ids ?? []).filter((v): v is number => typeof v === 'number')}
                                onChange={(ids) => onChange({ character_ids: [...ids, ...formCharacterRefs(node)] })}
                            />
                            <FormCharacterChoice node={node} nodes={nodes} refs={refs} onChange={onChange} />
                        </>
                    )}

                    {node.type === 'ai.video' && (
                        <div className="space-y-1">
                            <Label>Format</Label>
                            <NativeSelect
                                value={node.data.aspect_ratio ?? '9:16'}
                                onChange={(e) => onChange({ aspect_ratio: e.target.value as '9:16' | '16:9' })}
                            >
                                <option value="9:16">Vertical 9:16 (Reels, Shorts, Stories)</option>
                                <option value="16:9">Landscape 16:9 (YouTube)</option>
                            </NativeSelect>
                            <Label className="pt-2">Length</Label>
                            <NativeSelect
                                value={String(node.data.duration ?? 0)}
                                onChange={(e) => onChange({ duration: Number(e.target.value) || undefined })}
                            >
                                <option value="0">One clip (up to about 10 s)</option>
                                {[30, 60, 90, 120].map((seconds) => (
                                    <option key={seconds} value={seconds}>
                                        About {seconds < 60 ? `${seconds} s` : `${seconds / 60} min`} ({Math.ceil(seconds / 8)} scenes)
                                    </option>
                                ))}
                            </NativeSelect>
                            {!!node.data.duration && (
                                <p className="text-muted-foreground text-[11px]">
                                    The AI splits your brief into {Math.ceil(node.data.duration / 8)} scenes of about 8 s that tell one story, makes
                                    each one with the same characters and references (3 at a time), and joins them into one video. That is{' '}
                                    <strong>{Math.ceil(node.data.duration / 8)} video generations</strong> on your key, and takes roughly{' '}
                                    {Math.ceil(node.data.duration / 8 / 3) * 2}–{Math.ceil(node.data.duration / 8 / 3) * 5} minutes.
                                </p>
                            )}
                        </div>
                    )}

                    {(node.type === 'ai.image' || node.type === 'ai.video') && (
                        <ReferenceImagesField
                            value={node.data.references ?? []}
                            onChange={(references) => onChange({ references })}
                            upstreamImageKeys={refs.filter((key) => nodes.find((n) => (n.data.key || n.id) === key)?.type === 'ai.image')}
                            formImageSources={nodes
                                .filter((n) => n.type === 'trigger.manual' && refs.includes(n.data.key || n.id))
                                .flatMap((n) =>
                                    (n.data.form?.fields ?? [])
                                        .filter((f) => f.type === 'image')
                                        .map((f) => ({ label: f.label || f.key, src: `{{${n.data.key || n.id}.output.${f.key}}}` })),
                                )}
                            assets={referenceAssets}
                            hasCharacters={(node.data.character_ids ?? []).length > 0}
                            allowVideo={node.type === 'ai.video'}
                        />
                    )}

                    {node.type === 'ai.research' && (
                        <ResearchSettings
                            data={node.data}
                            onChange={onChange}
                            servers={mcpServers}
                            credential={credentials.find((c) => c.id === node.data.credential_id)}
                        />
                    )}
                </>
            )}

            {step && (
                <div className="space-y-2 border-t pt-4">
                    <div className="flex items-center gap-2 text-sm font-medium">
                        Last run <RunStatusBadge status={step.status} />
                    </div>
                    <StepDetail step={step} />
                </div>
            )}
        </div>
    );
}

function RunPanel({
    execution,
    executions,
    onOpen,
    onRetry,
}: {
    execution: Execution | null;
    executions: Props['executions'];
    onOpen: (id: number) => void;
    onRetry: () => void;
}) {
    return (
        <div className="space-y-4 p-4">
            {execution ? (
                <div className="space-y-3">
                    <div className="flex items-center gap-2">
                        <h2 className="font-semibold">Run #{execution.id}</h2>
                        <RunStatusBadge status={execution.status} />
                        {!['succeeded', 'failed'].includes(execution.status) && <Loader2 className="size-4 animate-spin" />}
                        {execution.status === 'failed' && (
                            <Button size="sm" variant="outline" className="ml-auto" onClick={onRetry}>
                                <RotateCcw className="size-4" /> Retry failed step
                            </Button>
                        )}
                    </div>
                    {execution.error && <p className="text-sm text-red-600">{execution.error}</p>}
                    {(execution.steps ?? []).map((step) => (
                        <div key={step.id} className="space-y-1 rounded-lg border p-3">
                            <div className="flex items-center gap-2 text-sm">
                                <span className="flex-1 truncate font-mono text-xs">{step.node_id}</span>
                                <RunStatusBadge status={step.status} />
                            </div>
                            <StepDetail step={step} />
                        </div>
                    ))}
                    <Link href={`/runs/${execution.id}`} className="text-muted-foreground text-xs underline">
                        Open full run log
                    </Link>
                </div>
            ) : (
                <p className="text-muted-foreground text-sm">
                    Select a node to edit it, or press Run to test the flow. Drag from a node's right dot to connect it.
                </p>
            )}

            {executions.length > 0 && (
                <div className="space-y-1 border-t pt-4">
                    <h3 className="text-sm font-medium">Recent runs</h3>
                    {executions.map((e) => (
                        <button
                            key={e.id}
                            onClick={() => onOpen(e.id)}
                            className="hover:bg-muted flex w-full items-center gap-2 rounded px-2 py-1 text-left text-sm"
                        >
                            <span className="flex-1">
                                #{e.id} · {new Date(e.created_at).toLocaleString()}
                            </span>
                            <RunStatusBadge status={e.status} />
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
