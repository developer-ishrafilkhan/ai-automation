<?php

namespace App\Services\Engine\Nodes;

use App\Models\ExecutionStep;
use App\Services\Ai\Providers\Provider;
use App\Services\Engine\StepResult;

class TextNode implements NodeHandler
{
    use UsesModels;

    public function handle(ExecutionStep $step, array $data, ?array $state): StepResult
    {
        [$result, $credential, $model] = $this->withModel($step, 'text', $data,
            fn (Provider $provider, string $key, string $model) => $provider->generateText($key, $model, $data['prompt'] ?? '', $data['system'] ?? null));

        return StepResult::done(
            ['text' => $result->text, 'provider' => $credential->provider, 'model' => $model],
            ['input' => $result->inputTokens, 'output' => $result->outputTokens],
        );
    }
}
