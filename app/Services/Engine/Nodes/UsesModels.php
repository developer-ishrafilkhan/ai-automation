<?php

namespace App\Services\Engine\Nodes;

use App\Models\AiCredential;
use App\Models\ExecutionStep;
use App\Services\Ai\ModelRouter;
use App\Services\Ai\ProviderRegistry;
use App\Services\Ai\Providers\Provider;
use Throwable;

trait UsesModels
{
    /**
     * Run $call against each routed model until one succeeds (primary, then fallback).
     *
     * @param  callable(Provider, string $key, string $model, AiCredential): mixed  $call
     */
    protected function withModel(ExecutionStep $step, string $taskType, array $data, callable $call): array
    {
        $workspace = $step->execution->workflow->workspace;
        $candidates = app(ModelRouter::class)->candidates($workspace, $taskType, $data);
        $lastError = null;

        foreach ($candidates as ['credential' => $credential, 'model' => $model]) {
            try {
                $provider = app(ProviderRegistry::class)->for($credential->provider);
                $result = $call($provider, $credential->secret(), $model, $credential);

                return [$result, $credential, $model];
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        throw $lastError;
    }
}
