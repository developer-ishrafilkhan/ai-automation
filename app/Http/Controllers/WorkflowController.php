<?php

namespace App\Http\Controllers;

use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\Engine\FlowGenerator;
use App\Services\Engine\NodeRegistry;
use App\Services\Engine\WorkflowRunner;
use App\Support\FormFields;
use App\Support\PromptBlanks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

class WorkflowController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('workflows/index', [
            'workflows' => $this->workspace($request)->workflows()
                ->withCount('executions')
                ->with(['executions' => fn ($q) => $q->latest()->limit(1)])
                ->latest('updated_at')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->guard($request, write: true);
        $validated = $request->validate(['name' => ['required', 'string', 'max:150']]);

        $workflow = $workspace->workflows()->create(['name' => $validated['name'], 'draft_graph' => Workflow::emptyGraph()]);

        return to_route('workflows.edit', $workflow);
    }

    /** Describe an automation in plain words; the workspace's text model designs the steps and the form. */
    public function generate(Request $request, FlowGenerator $generator): JsonResponse
    {
        $workspace = $this->guard($request, write: true);
        $validated = $request->validate(['description' => ['required', 'string', 'min:15', 'max:3000']]);

        try {
            $flow = $generator->generate($workspace, $validated['description']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 502);
        }

        $workflow = $workspace->workflows()->create([
            'name' => $flow['name'],
            'draft_graph' => $flow['graph'],
            'form_settings' => $flow['form'] + ['daily_limit' => Workflow::DEFAULT_FORM_DAILY_LIMIT],
        ]);

        return response()->json(['id' => $workflow->id, 'url' => route('workflows.edit', $workflow, false)]);
    }

    public function edit(Request $request, Workflow $workflow): Response
    {
        $workspace = $this->guard($request, $workflow->workspace_id);

        return Inertia::render('workflows/editor', [
            'workflow' => $workflow->load('activeVersion:id,version,published_at'),
            'credentials' => $workspace->credentials()->where('status', 'valid')->get(['id', 'provider', 'label', 'models']),
            'executions' => $workflow->executions()->latest()->limit(10)->get(['id', 'status', 'created_at', 'finished_at']),
            'mcpServers' => $workspace->mcpServers()->where('status', 'valid')->get(['id', 'name', 'url', 'tools_cache']),
            'characters' => $workspace->characters()->orderBy('name')->get(),
            'form' => $this->formState($workflow),
            'referenceAssets' => $workspace->assets()->whereIn('type', ['image', 'video'])->latest()->limit(24)->get(['id', 'type', 'storage_path', 'tags']),
        ]);
    }

    public function update(Request $request, Workflow $workflow): JsonResponse
    {
        $this->guard($request, $workflow->workspace_id, write: true);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'graph' => ['sometimes', 'array'],
            'graph.nodes' => ['required_with:graph', 'array', 'max:200'],
            'graph.nodes.*.id' => ['required', 'string', 'max:64'],
            'graph.nodes.*.type' => ['required', Rule::in(array_keys(NodeRegistry::HANDLERS))],
            'graph.nodes.*.data.key' => ['nullable', 'regex:/^[A-Za-z0-9_\-]+$/', 'max:64'],
            'graph.edges' => ['present_with:graph', 'array'],
            'graph.edges.*.source' => ['required', 'string'],
            'graph.edges.*.target' => ['required', 'string'],
        ]);

        $workflow->update(array_filter([
            'name' => $validated['name'] ?? null,
            'draft_graph' => isset($validated['graph']) ? $this->cleanGraph($request->input('graph')) : null,
        ]));

        return response()->json(['saved_at' => $workflow->updated_at]);
    }

    public function publish(Request $request, Workflow $workflow): JsonResponse
    {
        $this->guard($request, $workflow->workspace_id, write: true);

        return response()->json(['version' => $this->publishDraft($workflow)->version]);
    }

    public function run(Request $request, Workflow $workflow, WorkflowRunner $runner): JsonResponse
    {
        $this->guard($request, $workflow->workspace_id, write: true);

        if ($problem = $this->setupProblem($workflow->draft_graph)) {
            return response()->json($problem, 422);
        }

        $execution = $runner->start($workflow->snapshotDraft(), (array) $request->input('input', []));

        return response()->json(['execution_id' => $execution->id]);
    }

    /** Switch the public form on or off and change its texts. Switching on publishes the current draft. */
    public function updateForm(Request $request, Workflow $workflow): JsonResponse
    {
        $this->guard($request, $workflow->workspace_id, write: true);
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'title' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'submit_label' => ['nullable', 'string', 'max:40'],
            'daily_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'publish' => ['sometimes', 'boolean'],
        ]);

        if ($validated['enabled'] && ($validated['publish'] ?? true)) {
            if (FormFields::fromGraph($workflow->draft_graph) === []) {
                return response()->json(['message' => 'Add at least one form field to the Form step first.'], 422);
            }
            if ($problem = $this->setupProblem($workflow->draft_graph)) {
                return response()->json($problem, 422);
            }
            $this->publishDraft($workflow);
        }

        $workflow->update([
            'form_enabled' => $validated['enabled'],
            'form_slug' => $workflow->form_slug ?? Str::lower(Str::random(12)),
            'form_settings' => [
                'title' => trim((string) ($validated['title'] ?? '')) ?: $workflow->name,
                'description' => trim((string) ($validated['description'] ?? '')),
                'submit_label' => trim((string) ($validated['submit_label'] ?? '')) ?: 'Generate',
                'daily_limit' => $validated['daily_limit'],
            ],
        ]);

        return response()->json($this->formState($workflow->fresh('activeVersion')));
    }

    /** AI-written form fields for the {{trigger.output.x}} values this flow already uses. */
    public function suggestForm(Request $request, Workflow $workflow, FlowGenerator $generator): JsonResponse
    {
        $workspace = $this->guard($request, $workflow->workspace_id, write: true);

        try {
            return response()->json($generator->suggestForm($workspace, $workflow->draft_graph));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function destroy(Request $request, Workflow $workflow): RedirectResponse
    {
        $this->guard($request, $workflow->workspace_id, write: true);
        $workflow->delete();

        return to_route('workflows.index');
    }

    private function publishDraft(Workflow $workflow): WorkflowVersion
    {
        $version = $workflow->snapshotDraft();
        $version->update(['published_at' => $version->published_at ?? now()]);
        $workflow->update(['active_version_id' => $version->id, 'status' => 'active']);

        return $version;
    }

    /** Why this graph can't run yet (a missing brief or [BRACKETED] blanks), as a 422 body. */
    private function setupProblem(array $graph): ?array
    {
        if ($missing = PromptBlanks::missingBriefs($graph)) {
            $kind = collect($graph['nodes'])->firstWhere(fn ($n) => ($n['data']['label'] ?? $n['id']) === $missing[0])['type'] === 'ai.video' ? 'video' : 'image';

            return [
                'message' => "\"{$missing[0]}\" needs a brief: write in one line what this {$kind} should show.",
                'missing_briefs' => $missing,
            ];
        }

        if ($blanks = PromptBlanks::inGraph($graph)) {
            $first = $blanks[0];

            return [
                'message' => "Fill in the [BRACKETED] blanks before running. \"{$first['node']}\" still has: ".implode(', ', array_slice($first['blanks'], 0, 3)).(count($first['blanks']) > 3 ? ', …' : ''),
                'blanks' => $blanks,
            ];
        }

        return null;
    }

    private function formState(Workflow $workflow): array
    {
        $workflow->loadMissing('activeVersion');
        $live = $workflow->activeVersion?->graph;

        return [
            'enabled' => $workflow->form_enabled,
            'url' => $workflow->formUrl(),
            'settings' => $workflow->formSettings(),
            'version' => $workflow->activeVersion?->version,
            'runs_today' => $workflow->executions()->where('source', 'form')->where('created_at', '>=', now()->startOfDay())->count(),
            // The public form runs the published version; flag drafts that differ from it.
            'outdated' => $workflow->form_enabled && $live !== null
                && hash('sha256', json_encode($workflow->draft_graph)) !== $workflow->activeVersion->graph_hash,
        ];
    }

    /** Keep only the fields the engine and canvas need. */
    private function cleanGraph(array $graph): array
    {
        // A reference to a Saved character answer becomes "Character chosen on the form".
        return FormFields::characterReferencesToCast([
            'nodes' => array_map(fn ($node) => [
                'id' => $node['id'],
                'type' => $node['type'],
                'position' => ['x' => (float) ($node['position']['x'] ?? 0), 'y' => (float) ($node['position']['y'] ?? 0)],
                'data' => $node['type'] === 'trigger.manual' && isset($node['data']['form'])
                    ? ['form' => ['fields' => FormFields::normalize((array) ($node['data']['form']['fields'] ?? []))]] + ($node['data'] ?? [])
                    : $node['data'] ?? [],
            ], $graph['nodes']),
            'edges' => array_map(fn ($edge) => [
                'id' => $edge['id'] ?? "{$edge['source']}-{$edge['target']}",
                'source' => $edge['source'],
                'target' => $edge['target'],
            ], $graph['edges'] ?? []),
        ]);
    }
}
