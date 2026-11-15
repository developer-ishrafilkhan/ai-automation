export type Provider = 'anthropic' | 'openai' | 'google' | 'deepseek';
export type TaskType = 'text' | 'research' | 'image' | 'video';
export type RunStatus = 'queued' | 'running' | 'waiting' | 'succeeded' | 'failed';

export interface Credential {
    id: number;
    provider: Provider;
    label: string;
    key_last4?: string;
    status?: 'untested' | 'valid' | 'invalid';
    status_message?: string | null;
    models: string[] | null;
    checked_at?: string | null;
}

export interface ModelDefault {
    task_type: TaskType;
    credential_id: number | null;
    model: string | null;
    fallback_credential_id: number | null;
    fallback_model: string | null;
}

export interface NodeData {
    label?: string;
    key?: string;
    credential_id?: number | null;
    model?: string;
    prompt?: string;
    system?: string;
    input?: Record<string, unknown>;
    /** Image/video nodes: src is "asset:12", {{node.output.url}} or an https URL. Plain strings are legacy (role "auto"). */
    references?: (string | ReferenceEntry)[];
    /** Research nodes */
    mode?: 'quick' | 'deep';
    urls?: string;
    instructions?: string;
    mcp_server_ids?: number[];
    /** Image and video nodes: recurring characters to keep consistent */
    /** Saved character ids, plus "{{trigger.output.x}}" for the character a form visitor picks */
    character_ids?: (number | string)[];
    aspect_ratio?: '9:16' | '16:9';
    /** Image/video nodes: "ai" = the prompt writer turns `brief` into the prompt; "manual" = use `prompt` as written */
    prompt_mode?: 'ai' | 'manual';
    brief?: string;
    /** Video nodes: total length in seconds (30–120) made of joined ~8 s scenes; unset = one clip */
    duration?: number;
    /** Trigger node: the public form's questions; answers arrive as {{trigger.output.<key>}} */
    form?: { fields: FormField[] };
    /** Steps the public form's result page leaves out (helper steps) */
    hide_in_form?: boolean;
    [key: string]: unknown;
}

export type FormFieldType = 'text' | 'textarea' | 'select' | 'number' | 'email' | 'url' | 'image' | 'character';

export interface FormField {
    key: string;
    label: string;
    type: FormFieldType;
    required: boolean;
    placeholder?: string;
    help?: string;
    options?: string[];
    /** Character questions: visitors may pick up to 3 */
    multiple?: boolean;
}

export const FORM_FIELD_TYPES: Record<FormFieldType, string> = {
    text: 'Short text',
    textarea: 'Long text',
    select: 'Choice',
    number: 'Number',
    email: 'Email',
    url: 'Link',
    image: 'Image upload',
    character: 'Saved character',
};

export interface FormState {
    enabled: boolean;
    url: string | null;
    settings: { title: string; description: string; submit_label: string; daily_limit: number };
    runs_today: number;
    /** Published version the form runs */
    version: number | null;
    /** The draft has changes the public form (which runs the published version) doesn't have yet */
    outdated: boolean;
}

/** One deliverable on the public result page. */
export interface PublicResultItem {
    id: string;
    label: string;
    kind: 'text' | 'research' | 'image' | 'video';
    status: RunStatus | 'pending';
    /** Long videos: scenes ready so far */
    progress: { done: number; total: number } | null;
    text: string | null;
    url: string | null;
    citations: { title: string; url: string }[];
}

export interface PublicResult {
    status: RunStatus;
    failed: boolean;
    items: PublicResultItem[];
}

export interface GraphNode {
    id: string;
    type: string;
    position: { x: number; y: number };
    data: NodeData;
}

export interface Graph {
    nodes: GraphNode[];
    edges: { id?: string; source: string; target: string }[];
}

export interface Workflow {
    id: number;
    name: string;
    status: string;
    draft_graph: Graph;
    active_version?: { id: number; version: number; published_at: string } | null;
    updated_at: string;
}

export interface Step {
    id: number;
    /** Long videos: scenes ready so far */
    progress?: { done: number; total: number } | null;
    node_id: string;
    node_type: string;
    status: RunStatus;
    input: Record<string, unknown> | null;
    output: Record<string, unknown> | null;
    error: string | null;
    attempts: number;
    tokens: { input: number; output: number } | null;
    started_at: string | null;
    finished_at: string | null;
}

export interface Asset {
    id: number;
    type: 'image' | 'video' | 'audio';
    url: string;
    mime: string;
    size: number;
    tags: string[] | null;
    created_at: string;
}

export interface Execution {
    id: number;
    status: RunStatus;
    error: string | null;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
    workflow?: { id: number; name: string };
    version?: { id: number; version: number; graph?: Graph };
    steps?: Step[];
    assets?: Asset[];
}

export type ImageReferenceRole = 'auto' | 'person' | 'product' | 'logo' | 'match' | 'swap_character' | 'color_theme' | 'style';
export type VideoReferenceRole = 'edit_video' | 'extend_video';
export type ReferenceRole = ImageReferenceRole | VideoReferenceRole;

export interface ReferenceEntry {
    src: string;
    role: ReferenceRole;
    /** The user's own "how & where to use it" instruction */
    note?: string;
}

/** Order here is the order of the "Use as" menu. */
export const REFERENCE_ROLES: Record<ReferenceRole, { label: string; hint: string; placeholder: string; video?: boolean }> = {
    auto: {
        label: 'Other (explain below)',
        hint: 'Tell the AI what this image is and how to use it.',
        placeholder: 'e.g. Our payment QR code: bottom-right corner, about 15% of the width',
    },
    person: {
        label: 'Person to include',
        hint: 'This person appears in the result with their exact likeness.',
        placeholder: 'e.g. This is Rahul. Standing on the left, smiling, in a navy suit',
    },
    product: {
        label: 'Product (keep exactly)',
        hint: 'Shown unchanged: shape, colours, label and logo.',
        placeholder: 'e.g. Hero product, centre front, slightly angled',
    },
    logo: {
        label: 'Logo',
        hint: 'Placed unchanged, never redrawn or recoloured.',
        placeholder: 'e.g. Top-left corner, small, white version on dark background',
    },
    match: {
        label: 'Match closely',
        hint: 'Recreate this image: same layout, pose, lighting, colours and style. Only what your brief asks changes.',
        placeholder: 'e.g. Same layout, but swap the headline and the product',
    },
    swap_character: {
        label: 'Change character',
        hint: 'Keep this scene exactly, but replace the person with your selected character (or the one described in the brief).',
        placeholder: 'e.g. Replace the man with Ishrafil, keep the pose',
    },
    color_theme: {
        label: 'Colour theme only',
        hint: 'Only these colours are used. The AI never sees the image itself, so its layout and content can’t be copied.',
        placeholder: 'e.g. Use the orange only for the headline and buttons',
    },
    style: {
        label: 'Style only',
        hint: 'Copy the visual style (medium, texture, finish), not the subject or layout.',
        placeholder: 'e.g. Same watercolour look',
    },
    edit_video: {
        label: 'Edit this clip',
        hint: 'Keeps the clip and changes only what your brief describes.',
        placeholder: 'e.g. Make the sky golden-hour, keep everything else',
        video: true,
    },
    extend_video: {
        label: 'Continue this clip',
        hint: 'Continues from the clip’s last frame in the same style.',
        placeholder: 'e.g. Continue: she turns to camera and waves',
        video: true,
    },
};

export interface McpServer {
    id: number;
    name: string;
    url: string;
    status?: 'untested' | 'valid' | 'invalid';
    status_message?: string | null;
    tools_cache: { name: string; description: string }[] | null;
    has_token?: boolean;
    checked_at?: string | null;
}

export interface PromptGuide {
    task_type: 'image' | 'video';
    instructions: string | null;
    examples: { prompt: string; note: string | null; saved_at?: string }[] | null;
    credential_id: number | null;
    model: string | null;
}

export interface Character {
    id: number;
    name: string;
    appearance: string;
    style: string;
    voice: string | null;
    reference_asset_ids: number[] | null;
    reference_urls: { id: number; url: string }[];
    sheet_asset_id: number | null;
    sheet_url: string | null;
    sheet_status: 'none' | 'generating' | 'ready' | 'failed';
    sheet_error: string | null;
}

export const CHARACTER_STYLE_LABELS: Record<string, string> = {
    photorealistic: 'Photorealistic',
    '3d-animation': '3D animation',
    anime: 'Anime',
    illustration: 'Flat illustration',
    comic: 'Comic book',
    claymation: 'Claymation',
};

export interface Citation {
    url: string;
    title: string;
}

export const PROVIDER_LABELS: Record<Provider, string> = {
    anthropic: 'Anthropic (Claude)',
    openai: 'OpenAI',
    google: 'Google (Gemini)',
    deepseek: 'DeepSeek',
};

/** Providers whose research can use MCP connectors (Gemini's API can't call remote MCP servers). */
export const CONNECTOR_PROVIDERS: Provider[] = ['anthropic', 'openai'];

/** Which providers can do which task in this build. */
export const TASK_PROVIDERS: Record<TaskType, Provider[]> = {
    text: ['anthropic', 'openai', 'google', 'deepseek'],
    research: ['anthropic', 'openai', 'google'],
    image: ['openai', 'google'],
    video: ['google'],
};
