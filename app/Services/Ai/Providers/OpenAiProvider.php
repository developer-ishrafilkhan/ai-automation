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

/** OpenAI, and any OpenAI-compatible API (DeepSeek) via a different base URL. */
class OpenAiProvider implements Provider
{
    public function __construct(
        private readonly string $name = 'OpenAI',
        private readonly string $baseUrl = 'https://api.openai.com/v1',
        private readonly bool $supportsImages = true,
    ) {}

    public function listModels(string $key): array
    {
        $response = $this->client($key)->get($this->baseUrl.'/models');
        if ($response->failed()) {
            throw ProviderException::fromResponse($this->name, $response);
        }

        return collect($response->json('data', []))->pluck('id')->sort()->values()->all();
    }

    public function generateText(string $key, string $model, string $prompt, ?string $system = null, array $images = []): TextResult
    {
        $messages = $system ? [['role' => 'system', 'content' => $system]] : [];
        // DeepSeek's chat API is text-only, so images are only sent where vision is supported.
        $messages[] = ['role' => 'user', 'content' => $images && $this->supportsImages
            ? [...array_map(fn (GeneratedFile $img) => ['type' => 'image_url', 'image_url' => ['url' => "data:{$img->mime};base64,".base64_encode($img->bytes)]], $images), ['type' => 'text', 'text' => $prompt]]
            : $prompt];

        $response = $this->client($key)->timeout(300)->post($this->baseUrl.'/chat/completions', [
            'model' => $model,
            'messages' => $messages,
        ]);
        if ($response->failed()) {
            throw ProviderException::fromResponse($this->name, $response);
        }

        return new TextResult(
            (string) $response->json('choices.0.message.content'),
            (int) $response->json('usage.prompt_tokens'),
            (int) $response->json('usage.completion_tokens'),
        );
    }

    public function generateImage(string $key, string $model, string $prompt, array $references = []): GeneratedFile
    {
        if (! $this->supportsImages) {
            throw ProviderException::unsupported($this->name, 'image generation');
        }

        if ($references) {
            // Reference images go to the edits endpoint, which takes several input images.
            $request = $this->client($key)->timeout(300)->asMultipart();
            foreach (array_values($references) as $i => $reference) {
                $extension = str_contains($reference->mime, 'jpeg') ? 'jpg' : (explode('/', $reference->mime)[1] ?? 'png');
                $request = $request->attach('image[]', $reference->bytes, "reference-{$i}.{$extension}", ['Content-Type' => $reference->mime]);
            }
            $response = $request->post($this->baseUrl.'/images/edits', ['model' => $model, 'prompt' => $prompt]);
        } else {
            $body = ['model' => $model, 'prompt' => $prompt, 'n' => 1];
            // gpt-image models always return base64; older DALL·E models need asking.
            if (str_starts_with($model, 'dall-e')) {
                $body['response_format'] = 'b64_json';
            }
            $response = $this->client($key)->timeout(300)->post($this->baseUrl.'/images/generations', $body);
        }

        if ($response->failed()) {
            throw ProviderException::fromResponse($this->name, $response);
        }

        $b64 = $response->json('data.0.b64_json');
        if (! $b64) {
            throw new ProviderException("{$this->name} returned no image data.");
        }

        return new GeneratedFile(base64_decode($b64), 'image/png');
    }

    public function startVideo(string $key, string $model, string $prompt, array $references = [], ?string $aspectRatio = null): string
    {
        throw ProviderException::unsupported($this->name, 'video generation');
    }

    public function pollVideo(string $key, string $operation): ?GeneratedFile
    {
        throw ProviderException::unsupported($this->name, 'video generation');
    }

    public function startResearch(string $key, string $model, ResearchRequest $request): ResearchOutcome
    {
        if ($this->name !== 'OpenAI') {
            throw ProviderException::unsupported($this->name, 'web research');
        }

        $deepModel = str_contains($model, 'deep-research');
        $tools = [['type' => $deepModel ? 'web_search_preview' : 'web_search']];
        foreach ($request->mcpServers as $server) {
            $tools[] = array_filter([
                'type' => 'mcp',
                'server_label' => $server['name'],
                'server_url' => $server['url'],
                'headers' => $server['token'] ? ['Authorization' => 'Bearer '.$server['token']] : null,
                'require_approval' => 'never',
            ]);
        }

        // Deep research can run for many minutes: run it in the background and poll.
        $background = $request->deep || $deepModel;
        $response = $this->client($key)->timeout(840)->post($this->baseUrl.'/responses', [
            'model' => $model,
            'instructions' => $request->system(),
            'input' => $request->userPrompt(),
            'tools' => $tools,
            'background' => $background,
        ]);
        if ($response->failed()) {
            throw ProviderException::fromResponse($this->name, $response);
        }

        return $background && $response->json('status') !== 'completed'
            ? ResearchOutcome::pending((string) $response->json('id'))
            : ResearchOutcome::done($this->researchResult($response->json()));
    }

    public function pollResearch(string $key, string $jobId): ?ResearchResult
    {
        $response = $this->client($key)->get($this->baseUrl.'/responses/'.rawurlencode($jobId));
        if ($response->failed()) {
            throw ProviderException::fromResponse($this->name, $response);
        }

        return match ($response->json('status')) {
            'completed' => $this->researchResult($response->json()),
            'failed', 'cancelled', 'incomplete' => throw new ProviderException(
                "{$this->name} research job ended as {$response->json('status')}: ".($response->json('error.message') ?? $response->json('incomplete_details.reason') ?? 'no details')),
            default => null,
        };
    }

    private function researchResult(array $response): ResearchResult
    {
        $parts = collect($response['output'] ?? [])->where('type', 'message')->flatMap(fn ($item) => $item['content'] ?? [])->where('type', 'output_text');

        $citations = $parts->flatMap(fn ($part) => $part['annotations'] ?? [])->where('type', 'url_citation')
            ->map(fn ($a) => ['url' => $a['url'] ?? null, 'title' => $a['title'] ?? null])->all();

        return new ResearchResult(
            $parts->pluck('text')->implode("\n\n"),
            ResearchResult::uniqueCitations($citations),
            (int) ($response['usage']['input_tokens'] ?? 0),
            (int) ($response['usage']['output_tokens'] ?? 0),
        );
    }

    private function client(string $key): PendingRequest
    {
        return Http::withToken($key)->acceptJson();
    }
}
