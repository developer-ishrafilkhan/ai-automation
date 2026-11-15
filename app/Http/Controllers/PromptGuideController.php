<?php

namespace App\Http\Controllers;

use App\Models\ExecutionStep;
use App\Models\PromptGuide;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** "Training" for the AI prompt writer: house style instructions plus prompts the user liked. */
class PromptGuideController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request);

        return Inertia::render('prompt-writer/index', [
            'guides' => collect(PromptGuide::TASK_TYPES)->mapWithKeys(fn ($task) => [$task => $this->guide($workspace, $task)]),
            'credentials' => $workspace->credentials()->where('status', 'valid')->whereIn('provider', ['anthropic', 'openai', 'google', 'deepseek'])->get(['id', 'provider', 'label', 'models']),
            'maxExamples' => PromptGuide::MAX_EXAMPLES,
        ]);
    }

    public function update(Request $request, string $task): RedirectResponse
    {
        $workspace = $this->guard($request, write: true);
        abort_unless(in_array($task, PromptGuide::TASK_TYPES, true), 404);

        $validated = $request->validate([
            'instructions' => ['nullable', 'string', 'max:6000'],
            'credential_id' => ['nullable', Rule::exists('ai_credentials', 'id')->where('workspace_id', $workspace->id)],
            'model' => ['nullable', 'required_with:credential_id', 'string', 'max:150'],
            'examples' => ['array', 'max:'.PromptGuide::MAX_EXAMPLES],
            'examples.*.prompt' => ['required', 'string', 'max:8000'],
            'examples.*.note' => ['nullable', 'string', 'max:200'],
        ]);

        $guide = $this->guide($workspace, $task);
        $guide->fill([
            'instructions' => $validated['instructions'] ?? null,
            'credential_id' => $validated['credential_id'] ?? null,
            'model' => $validated['model'] ?? null,
            'examples' => array_map(fn ($e) => ['prompt' => $e['prompt'], 'note' => $e['note'] ?? null, 'saved_at' => $e['saved_at'] ?? now()->toIso8601String()], $request->input('examples', [])),
        ])->save();

        return back()->with('success', ucfirst($task).' prompt writer saved.');
    }

    /** "Use as example": teach the writer from a prompt that produced a result the user liked. */
    public function learnFromStep(Request $request, ExecutionStep $step): JsonResponse
    {
        $workspace = $this->guard($request, $step->execution->workflow->workspace_id, write: true);
        $task = ['ai.image' => 'image', 'ai.video' => 'video'][$step->node_type] ?? abort(422, 'Only image and video steps can be used as examples.');

        // Learn the scene the writer produced, not the fixed identity/reference blocks around it.
        $sent = (string) ($step->output['prompt_sent'] ?? '');
        $scene = str_contains($sent, "SCENE:\n") ? substr($sent, strrpos($sent, "SCENE:\n") + 7) : $sent;
        abort_if(trim($scene) === '', 422, 'This step has no prompt to learn from.');

        $guide = $this->guide($workspace, $task);
        $guide->addExample(trim($scene), $request->string('note')->limit(200)->toString() ?: null);
        $guide->save();

        return response()->json(['examples' => count($guide->examples)]);
    }

    private function guide(Workspace $workspace, string $task): PromptGuide
    {
        return $workspace->promptGuides()->firstOrNew(['task_type' => $task]);
    }
}
