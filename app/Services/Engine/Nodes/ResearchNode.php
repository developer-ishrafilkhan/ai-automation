<?php

namespace App\Services\Engine\Nodes;

use App\Models\AiCredential;
use App\Models\ExecutionStep;
use App\Services\Ai\ProviderException;
use App\Services\Ai\ProviderRegistry;
use App\Services\Ai\Providers\Provider;
use App\Services\Ai\ResearchRequest;
use App\Services\Ai\ResearchResult;
use App\Services\Engine\StepResult;

/** Web research with citations, optionally reading given URLs and using connected MCP servers. */
class ResearchNode implements NodeHandler
{
    use UsesModels;

    private const POLL_SECONDS = 20;

    private const TIMEOUT_SECONDS = 3600;

    public function handle(ExecutionStep $step, array $data, ?array $state): StepResult
    {
        if ($state !== null) {
            return $this->poll($state);
        }

        $request = new ResearchRequest(
            question: (string) ($data['prompt'] ?? ''),
            deep: ($data['mode'] ?? 'quick') === 'deep',
            instructions: ($data['instructions'] ?? null) ?: null,
            urls: $this->urls($data['urls'] ?? ''),
            mcpServers: $this->connectors($step, $data['mcp_server_ids'] ?? []),
        );

        [$outcome, $credential, $model] = $this->withModel($step, 'research', $data,
            fn (Provider $provider, string $key, string $model) => $provider->startResearch($key, $model, $request));

        if ($outcome->jobId) {
            return StepResult::wait([
                'job' => $outcome->jobId,
                'credential_id' => $credential->id,
                'model' => $model,
                'submitted_at' => now()->timestamp,
            ], self::POLL_SECONDS);
        }

        return $this->done($outcome->result, $credential->provider, $model);
    }

    private function poll(array $state): StepResult
    {
        if (now()->timestamp - $state['submitted_at'] > self::TIMEOUT_SECONDS) {
            throw new ProviderException('Research timed out after 60 minutes.');
        }

        $credential = AiCredential::findOrFail($state['credential_id']);
        $result = app(ProviderRegistry::class)->for($credential->provider)->pollResearch($credential->secret(), $state['job']);

        return $result === null
            ? StepResult::wait($state, self::POLL_SECONDS)
            : $this->done($result, $credential->provider, $state['model']);
    }

    private function done(ResearchResult $result, string $provider, string $model): StepResult
    {
        return StepResult::done(
            ['text' => $result->text, 'citations' => $result->citations, 'provider' => $provider, 'model' => $model],
            ['input' => $result->inputTokens, 'output' => $result->outputTokens],
        );
    }

    /** @return list<string> */
    private function urls(string|array $urls): array
    {
        $list = is_array($urls) ? $urls : preg_split('/[\s,]+/', $urls);

        return array_values(array_filter(array_map('trim', $list), fn ($url) => str_starts_with($url, 'https://')));
    }

    /** @return list<array{name: string, url: string, token: ?string}> */
    private function connectors(ExecutionStep $step, array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return $step->execution->workflow->workspace->mcpServers()
            ->whereIn('id', $ids)->where('status', 'valid')->get()
            ->map(fn ($server) => ['name' => $server->slug(), 'url' => $server->url, 'token' => $server->token()])
            ->all();
    }
}
