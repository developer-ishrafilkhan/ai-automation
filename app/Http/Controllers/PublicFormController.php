<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Character;
use App\Models\Execution;
use App\Models\Workflow;
use App\Services\Engine\PublicResult;
use App\Services\Engine\WorkflowRunner;
use App\Support\FormFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public side of a flow: anyone with the link fills in the form and gets the flow's results.
 * Runs use the flow's published version and the owner's keys, so they are rate limited per visitor
 * and capped per day.
 */
class PublicFormController extends Controller
{
    public function show(string $slug): Response
    {
        $workflow = $this->live($slug);
        $settings = $workflow->formSettings();

        return Inertia::render('public-form/show', [
            'slug' => $slug,
            'title' => $settings['title'],
            'description' => $settings['description'],
            'submitLabel' => $settings['submit_label'],
            'fields' => $fields = FormFields::fromGraph($workflow->activeVersion->graph),
            // Only the name and one picture of each character, and only if the form asks for one.
            'characters' => in_array('character', array_column($fields, 'type'), true)
                ? $workflow->workspace->characters()->orderBy('name')->get()->map(fn (Character $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'image' => $c->sheet_url ?? ($c->reference_urls[0]['url'] ?? null),
                ])->values()
                : [],
        ]);
    }

    public function submit(Request $request, string $slug, WorkflowRunner $runner): RedirectResponse
    {
        $workflow = $this->live($slug);

        // Bots fill in every input, including this hidden one.
        if (filled($request->input('website'))) {
            abort(422);
        }

        $runsToday = $workflow->executions()->where('source', 'form')->where('created_at', '>=', now()->startOfDay())->count();
        if ($runsToday >= (int) $workflow->formSettings()['daily_limit']) {
            return back()->withErrors(['form' => 'This form has reached its limit for today. Please try again tomorrow.']);
        }

        $fields = FormFields::fromGraph($workflow->activeVersion->graph);
        $characterIds = $workflow->workspace->characters()->pluck('id')->all();
        $values = $request->validate(FormFields::rules($fields, $characterIds), [], FormFields::attributes($fields));

        $input = [];
        foreach ($fields as $field) {
            $value = $values[$field['key']] ?? null;
            $input[$field['key']] = match ($field['type']) {
                'image' => $value ? $this->storeUpload($workflow, $value) : '',
                'character' => implode(',', array_map('intval', (array) $value)),
                default => trim((string) $value),
            };
        }

        $token = Str::random(40);
        $runner->start($workflow->activeVersion, $input, ['source' => 'form', 'public_token' => $token]);

        return redirect("/f/{$slug}/r/{$token}");
    }

    public function result(string $slug, string $token): Response
    {
        $workflow = $this->live($slug, requireEnabled: false);
        $execution = $this->execution($workflow, $token);

        return Inertia::render('public-form/result', [
            'slug' => $slug,
            'token' => $token,
            'title' => $workflow->formSettings()['title'],
            'formOpen' => $workflow->form_enabled,
            'result' => PublicResult::of($execution),
        ]);
    }

    public function status(string $slug, string $token): JsonResponse
    {
        $workflow = $this->live($slug, requireEnabled: false);

        return response()->json(PublicResult::of($this->execution($workflow, $token)));
    }

    /** A form that exists and is switched on; results stay viewable after it is switched off. */
    private function live(string $slug, bool $requireEnabled = true): Workflow
    {
        $workflow = Workflow::with('activeVersion', 'workspace')->where('form_slug', $slug)->first();
        abort_if(! $workflow || ! $workflow->activeVersion || ($requireEnabled && ! $workflow->form_enabled), 404);

        return $workflow;
    }

    private function execution(Workflow $workflow, string $token): Execution
    {
        return $workflow->executions()->with('version')->where('source', 'form')->where('public_token', $token)->firstOrFail();
    }

    /** Uploaded images become workspace assets, so Image/Video steps can use them as references. */
    private function storeUpload(Workflow $workflow, $file): string
    {
        $path = $file->storeAs("assets/{$workflow->workspace_id}", Str::uuid().'.'.$file->extension(), 'public');
        $asset = Asset::create([
            'workspace_id' => $workflow->workspace_id,
            'type' => 'image',
            'storage_path' => $path,
            'mime' => (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()),
            'size' => $file->getSize(),
            'tags' => ['form-upload'],
        ]);

        return $asset->url;
    }
}
