<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\GeneratedFile;
use App\Services\Ai\ProviderException;
use App\Services\Ai\ResearchOutcome;
use App\Services\Ai\ResearchRequest;
use App\Services\Ai\ResearchResult;
use App\Services\Ai\TextResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class AnthropicProvider implements Provider
{
    private const BASE = 'https://api.anthropic.com/v1';

    private const MCP_BETA = 'mcp-client-2025-11-20';

    private const MAX_CONTINUATIONS = 5;

    public function listModels(string $key): array
    {
        $response = $this->client($key)->get(self::BASE.'/models', ['limit' => 100]);
        if ($response->failed()) {
            throw ProviderException::fromResponse('Anthropic', $response);
        }

        return collect($response->json('data', []))->pluck('id')->values()->all();
    }

    public function generateText(string $key, string $model, string $prompt, ?string $system = null, array $images = []): TextResult
    {
        $content = $images
            ? [...array_map(fn (GeneratedFile $img) => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img->mime, 'data' => base64_encode($img->bytes)]], $images), ['type' => 'text', 'text' => $prompt]]
            : $prompt;
        $body = [
            'model' => $model,
            'max_tokens' => 8192,
            'messages' => [['role' => 'user', 'content' => $content]],
        ];
        if ($system) {
            $body['system'] = $system;
        }

        $response = $this->client($key)->timeout(300)->post(self::BASE.'/messages', $body);
        if ($response->failed()) {
            throw ProviderException::fromResponse('Anthropic', $response);
        }

        $text = collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode('');

        return new TextResult($text, (int) $response->json('usage.input_tokens'), (int) $response->json('usage.output_tokens'));
    }

    public function generateImage(string $key, string $model, string $prompt, array $references = []): GeneratedFile
    {
        throw ProviderException::unsupported('Anthropic', 'image generation');
    }

    public function startVideo(string $key, string $model, string $prompt, array $references = [], ?string $aspectRatio = null): string
    {
        throw ProviderException::unsupported('Anthropic', 'video generation');
    }

    public function pollVideo(string $key, string $operation): ?GeneratedFile
    {
        throw ProviderException::unsupported('Anthropic', 'video generation');
    }

    public function startResearch(string $key, string $model, ResearchRequest $request): ResearchOutcome
    {
        $modern = $this->supportsDynamicWebTools($model);
        $tools = [[
            'type' => $modern ? 'web_search_20260209' : 'web_search_20250305',
            'name' => 'web_search',
            'max_uses' => $request->deep ? 25 : 5,
        ]];
        if ($request->urls) {
            $tools[] = ['type' => $modern ? 'web_fetch_20260209' : 'web_fetch_20250910', 'name' => 'web_fetch', 'max_uses' => count($request->urls) + 5];
        }

        $body = ['model' => $model, 'max_tokens' => 16000, 'system' => $request->system()];
        $headers = [];
        if ($request->mcpServers) {
            $headers['anthropic-beta'] = self::MCP_BETA;
            $body['mcp_servers'] = array_map(fn ($server) => array_filter([
                'type' => 'url',
                'url' => $server['url'],
                'name' => $server['name'],
                'authorization_token' => $server['token'],
            ]), $request->mcpServers);
            foreach ($request->mcpServers as $server) {
                $tools[] = ['type' => 'mcp_toolset', 'mcp_server_name' => $server['name']];
            }
        }
        if ($request->deep && $modern) {
            $body['output_config'] = ['effort' => 'high'];
        }
        $body['tools'] = $tools;

        $messages = [['role' => 'user', 'content' => $request->userPrompt()]];
        $content = [];
        $usage = ['input' => 0, 'output' => 0];

        // Server-side tool loops pause after a set number of iterations; resend to let them continue.
        for ($turn = 0; $turn <= self::MAX_CONTINUATIONS; $turn++) {
            $response = $this->client($key)->withHeaders($headers)->timeout(840)->post(self::BASE.'/messages', $body + ['messages' => $messages]);
            if ($response->failed()) {
                throw ProviderException::fromResponse('Anthropic', $response);
            }

            $content = array_merge($content, $response->json('content', []));
            $usage['input'] += (int) $response->json('usage.input_tokens');
            $usage['output'] += (int) $response->json('usage.output_tokens');

            if ($response->json('stop_reason') === 'refusal') {
                throw new ProviderException('Claude declined this research request.');
            }
            if ($response->json('stop_reason') !== 'pause_turn') {
                break;
            }
            $messages = [$messages[0], ['role' => 'assistant', 'content' => $content]];
        }

        $text = collect($content)->where('type', 'text')->pluck('text')->implode('');
        $citations = collect($content)->where('type', 'text')->flatMap(fn ($block) => $block['citations'] ?? [])
            ->map(fn ($c) => ['url' => $c['url'] ?? null, 'title' => $c['title'] ?? null])->all();

        return ResearchOutcome::done(new ResearchResult($text, ResearchResult::uniqueCitations($citations), $usage['input'], $usage['output']));
    }

    public function pollResearch(string $key, string $jobId): ?ResearchResult
    {
        throw ProviderException::unsupported('Anthropic', 'background research jobs');
    }

    /** The dynamic-filtering web tools need Claude 4.6 or newer; older models get the basic versions. */
    private function supportsDynamicWebTools(string $model): bool
    {
        return (bool) preg_match('/^claude-(fable|mythos|opus-5|sonnet-5|opus-4-[6-9]|sonnet-4-[6-9])/', $model);
    }

    private function client(string $key): PendingRequest
    {
        return Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->acceptJson();
    }
}
