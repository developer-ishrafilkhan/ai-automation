<?php

namespace App\Services\Engine\Nodes;

use App\Models\ExecutionStep;
use App\Services\Ai\Providers\Provider;
use App\Services\Engine\CharacterConsistency;
use App\Services\Engine\PromptAssembler;
use App\Services\Engine\StepResult;

class ImageNode implements NodeHandler
{
    use StoresAssets, UsesModels;

    public function __construct(private readonly PromptAssembler $assembler) {}

    public function handle(ExecutionStep $step, array $data, ?array $state): StepResult
    {
        ['references' => $references, 'prompt' => $prompt, 'written_by' => $writtenBy] = $this->assembler->build($step->execution->workflow->workspace_id, $data);

        [$file, $credential, $model] = $this->withModel($step, 'image', $data,
            fn (Provider $provider, string $key, string $model) => $provider->generateImage($key, $model, $prompt, $references));

        $asset = $this->storeAsset($step, 'image', $file);

        return StepResult::done([
            'asset_id' => $asset->id,
            'url' => $asset->url,
            'provider' => $credential->provider,
            'model' => $model,
            'reference_count' => count($references),
            'prompt_sent' => $prompt, // the full text the model received, for checking results
            'prompt_written_by' => $writtenBy,
            'character_ids' => CharacterConsistency::ids($data['character_ids'] ?? []),
        ]);
    }
}
