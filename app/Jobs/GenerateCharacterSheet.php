<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\Character;
use App\Services\Ai\GeneratedFile;
use App\Services\Ai\ModelRouter;
use App\Services\Ai\ProviderRegistry;
use App\Services\Ai\Redactor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generates a model sheet (turnaround + expressions) that becomes the character's main
 * identity reference for every later image and video.
 */
class GenerateCharacterSheet implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $characterId, public array $modelChoice = []) {}

    public function handle(ModelRouter $router, ProviderRegistry $providers): void
    {
        $character = Character::with('workspace')->find($this->characterId);
        if (! $character) {
            return;
        }

        // Only the user's own photos guide the sheet; reusing an old sheet would let drift compound.
        $photos = Asset::where('workspace_id', $character->workspace_id)->whereIn('id', $character->reference_asset_ids ?? [])->take(4)->get()
            ->map(fn (Asset $a) => new GeneratedFile(Storage::disk('public')->get($a->storage_path), $a->mime))->all();

        $error = null;
        foreach ($router->candidates($character->workspace, 'image', $this->modelChoice) as ['credential' => $credential, 'model' => $model]) {
            try {
                $file = $providers->for($credential->provider)->generateImage($credential->secret(), $model, $this->prompt($character, count($photos)), $photos);
                $path = "assets/{$character->workspace_id}/".Str::uuid().'.'.(Str::after($file->mime, '/') ?: 'png');
                Storage::disk('public')->put($path, $file->bytes);

                $asset = Asset::create([
                    'workspace_id' => $character->workspace_id,
                    'type' => 'image',
                    'storage_path' => $path,
                    'mime' => $file->mime,
                    'size' => strlen($file->bytes),
                    'tags' => ['character', 'character-sheet', Str::slug($character->name)],
                ]);
                $character->update(['sheet_asset_id' => $asset->id, 'sheet_status' => 'ready', 'sheet_error' => null]);

                return;
            } catch (Throwable $e) {
                $error = Redactor::clean($e->getMessage());
            }
        }

        $character->update(['sheet_status' => 'failed', 'sheet_error' => mb_substr((string) $error, 0, 250)]);
    }

    public function failed(Throwable $e): void
    {
        Character::whereKey($this->characterId)->update(['sheet_status' => 'failed', 'sheet_error' => mb_substr(Redactor::clean($e->getMessage()), 0, 250)]);
    }

    private function prompt(Character $character, int $photoCount): string
    {
        $fromPhotos = $photoCount
            ? "Base the character's identity strictly on the {$photoCount} reference photo(s): same face, features, skin tone, hair and build. Do not idealise or change them."
            : 'Design the character from the description below.';

        return <<<TXT
Character model sheet for "{$character->name}", used as the master reference to keep this character identical across many future images and videos.
{$fromPhotos}

Character appearance (must match exactly in every view): {$character->appearance}
Art style: {$character->styleDescription()}.

Layout on one wide landscape canvas with a plain light-grey studio background and even, soft lighting:
- Top row, full body, same neutral standing pose: front view, three-quarter view, side profile, back view.
- Bottom row, head-and-shoulders close-ups: neutral, smiling, surprised, determined.
Identical outfit, hairstyle, colours and proportions in every panel. Clean separation between panels.
No text, labels, captions, logos or watermarks. No extra characters or props.
TXT;
    }
}
