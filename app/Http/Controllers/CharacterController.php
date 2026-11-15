<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateCharacterSheet;
use App\Models\Character;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CharacterController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request);

        // A sheet job that was killed (e.g. the queue worker stopped) never reports back.
        // Longer than the job's own timeout means it is not coming back: let the user retry.
        $workspace->characters()->where('sheet_status', 'generating')->where('updated_at', '<', now()->subMinutes(15))
            ->update(['sheet_status' => 'failed', 'sheet_error' => 'Generation was interrupted (the background worker stopped or timed out). Click Generate to try again.']);

        return Inertia::render('characters/index', [
            'characters' => $workspace->characters()->latest()->get(),
            'styles' => collect(Character::STYLES)->keys(),
            'credentials' => $workspace->credentials()->where('status', 'valid')->whereIn('provider', ['openai', 'google'])->get(['id', 'provider', 'label', 'models']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->guard($request, write: true);
        $workspace->characters()->create($this->validated($request));

        return back()->with('success', 'Character created. Generate a character sheet to lock in their look.');
    }

    public function update(Request $request, Character $character): RedirectResponse
    {
        $this->guard($request, $character->workspace_id, write: true);
        $character->update($this->validated($request));

        return back()->with('success', 'Character saved.');
    }

    public function destroy(Request $request, Character $character): RedirectResponse
    {
        $this->guard($request, $character->workspace_id, write: true);
        $character->delete();

        return back();
    }

    public function generateSheet(Request $request, Character $character): RedirectResponse
    {
        $workspace = $this->guard($request, $character->workspace_id, write: true);
        $choice = $request->validate([
            'credential_id' => ['nullable', Rule::exists('ai_credentials', 'id')->where('workspace_id', $workspace->id)],
            'model' => ['nullable', 'required_with:credential_id', 'string', 'max:150'],
        ]);

        $character->update(['sheet_status' => 'generating', 'sheet_error' => null]);
        GenerateCharacterSheet::dispatch($character->id, array_filter($choice));

        return back();
    }

    private function validated(Request $request): array
    {
        $workspace = $this->workspace($request);

        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'appearance' => ['required', 'string', 'min:20', 'max:3000'],
            'style' => ['required', 'string', 'max:100'],
            'voice' => ['nullable', 'string', 'max:500'],
            'reference_asset_ids' => ['array', 'max:6'],
            'reference_asset_ids.*' => ['integer', Rule::exists('assets', 'id')->where('workspace_id', $workspace->id)->where('type', 'image')],
        ]);
    }
}
