<?php

namespace App\Http\Controllers;

use App\Models\AiCredential;
use App\Models\ModelDefault;
use App\Services\Ai\ProviderRegistry;
use App\Services\Ai\Redactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CredentialController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request);

        return Inertia::render('credentials/index', [
            'credentials' => $workspace->credentials()->latest()->get(),
            'defaults' => $workspace->modelDefaults()->get()->keyBy('task_type'),
            'providers' => AiCredential::PROVIDERS,
            'taskTypes' => ModelDefault::TASK_TYPES,
        ]);
    }

    public function store(Request $request, ProviderRegistry $providers): RedirectResponse
    {
        $workspace = $this->guard($request, write: true);
        $validated = $request->validate([
            'provider' => ['required', Rule::in(AiCredential::PROVIDERS)],
            'label' => ['required', 'string', 'max:100'],
            'key' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $credential = new AiCredential(['provider' => $validated['provider'], 'label' => $validated['label']]);
        $credential->workspace()->associate($workspace);
        $credential->setSecret(trim($validated['key']));
        $this->check($credential, $providers, trim($validated['key']));

        return back()->with('success', $credential->status === 'valid' ? 'Key saved and verified.' : 'Key saved, but the test call failed.');
    }

    public function test(Request $request, AiCredential $credential, ProviderRegistry $providers): RedirectResponse
    {
        $this->guard($request, $credential->workspace_id, write: true);
        $this->check($credential, $providers, $credential->secret());

        return back();
    }

    public function destroy(Request $request, AiCredential $credential): RedirectResponse
    {
        $this->guard($request, $credential->workspace_id, write: true);
        $credential->delete();

        return back();
    }

    public function updateDefaults(Request $request): RedirectResponse
    {
        $workspace = $this->guard($request, write: true);
        $ownCredential = Rule::exists('ai_credentials', 'id')->where('workspace_id', $workspace->id);
        $validated = $request->validate([
            'defaults' => ['array'],
            'defaults.*.credential_id' => ['nullable', $ownCredential],
            'defaults.*.model' => ['nullable', 'string', 'max:150'],
            'defaults.*.fallback_credential_id' => ['nullable', $ownCredential],
            'defaults.*.fallback_model' => ['nullable', 'string', 'max:150'],
        ]);

        foreach (ModelDefault::TASK_TYPES as $task) {
            $workspace->modelDefaults()->updateOrCreate(['task_type' => $task], $validated['defaults'][$task] ?? []);
        }

        return back()->with('success', 'Model defaults saved.');
    }

    /** Cheap verification call (list models); also caches the live model list. */
    private function check(AiCredential $credential, ProviderRegistry $providers, string $key): void
    {
        try {
            $credential->models = $providers->for($credential->provider)->listModels($key);
            $credential->status = 'valid';
            $credential->status_message = null;
        } catch (Throwable $e) {
            $credential->status = 'invalid';
            $credential->status_message = mb_substr(Redactor::clean($e->getMessage(), [$key]), 0, 250);
        }

        $credential->checked_at = now();
        $credential->save();
    }
}
