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

/** Gemini API. The key goes in a header, never the URL, so it can't leak into logs. */
class GoogleProvider implements Provider
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    private const INTERACTIONS_REVISION = '2026-05-20';

    public function listModels(string $key): array
    {
        $response = $this->client($key)->get(self::BASE.'/models', ['pageSize' => 1000]);
        if ($response->failed()) {
            throw ProviderException::fromResponse('Gemini', $response);
        }

        return collect($response->json('models', []))
            ->pluck('name')
            ->map(fn (string $name) => str_replace('models/', '', $name))
            ->values()->all();
    }

    public function generateText(string $key, string $model, string $prompt, ?string $system = null, array $images = []): TextResult
    {
        $parts = array_map(fn (GeneratedFile $img) => ['inlineData' => ['mimeType' => $img->mime, 'data' => base64_encode($img->bytes)]], $images);
        $parts[] = ['text' => $prompt];
        $body = ['contents' => [['role' => 'user', 'parts' => $parts]]];
        if ($system) {
            $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $response = $this->generateContent($key, $model, $body);
        $text = collect($response->json('candidates.0.content.parts', []))->pluck('text')->filter()->implode('');

        return new TextResult($text, (int) $response->json('usageMetadata.promptTokenCount'), (int) $response->json('usageMetadata.candidatesTokenCount'));
    }

    public function generateImage(string $key, string $model, string $prompt, array $references = []): GeneratedFile
    {
        $parts = array_map(fn (GeneratedFile $ref) => ['inlineData' => ['mimeType' => $ref->mime, 'data' => base64_encode($ref->bytes)]], $references);
        $parts[] = ['text' => $prompt];

        $response = $this->generateContent($key, $model, [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
        ]);

        $image = collect($response->json('candidates.0.content.parts', []))->first(fn ($part) => isset($part['inlineData']));
        if (! $image) {
            throw new ProviderException('Gemini returned no image. Check that the model supports image output.');
        }

        return new GeneratedFile(base64_decode($image['inlineData']['data']), $image['inlineData']['mimeType'] ?? 'image/png');
    }

    public function startVideo(string $key, string $model, string $prompt, array $references = [], ?string $aspectRatio = null): string
    {
        // Gemini Omni models use the Interactions API, run in the background and polled.
        if (str_starts_with($model, 'gemini-')) {
            $input = array_map(fn (GeneratedFile $ref) => [
                'type' => str_starts_with($ref->mime, 'video/') ? 'video' : 'image',
                'data' => base64_encode($ref->bytes),
                'mime_type' => $ref->mime,
            ], $references);
            $input[] = ['type' => 'text', 'text' => $prompt];

            $response = $this->interactions($key)->timeout(120)->post(self::BASE.'/interactions', [
                'model' => $model,
                'input' => $input,
                'background' => true,
                'response_format' => array_filter(['type' => 'video', 'aspect_ratio' => $aspectRatio]),
            ]);
            if ($response->failed() || ! $response->json('id')) {
                throw ProviderException::fromResponse('Gemini', $response);
            }

            return 'interaction:'.$response->json('id');
        }

        // Older Veo-style models: long-running prediction, text prompt only.
        if ($references) {
            throw ProviderException::unsupported('Gemini', "character/reference images with {$model} (use gemini-omni-1.1-flash)");
        }
        $response = $this->client($key)->timeout(120)->post(self::BASE."/models/{$model}:predictLongRunning", [
            'instances' => [['prompt' => $prompt]],
            'parameters' => array_filter(['aspectRatio' => $aspectRatio]),
        ]);
        if ($response->failed()) {
            throw ProviderException::fromResponse('Gemini', $response);
        }

        return (string) $response->json('name');
    }

    public function pollVideo(string $key, string $operation): ?GeneratedFile
    {
        if (str_starts_with($operation, 'interaction:')) {
            return $this->pollInteraction($key, substr($operation, strlen('interaction:')));
        }

        $response = $this->client($key)->get(self::BASE.'/'.$operation);
        if ($response->failed()) {
            throw ProviderException::fromResponse('Gemini', $response);
        }

        if (! $response->json('done')) {
            return null;
        }
        if ($error = $response->json('error.message')) {
            throw new ProviderException("Gemini video job failed: {$error}");
        }

        $uri = $response->json('response.generateVideoResponse.generatedSamples.0.video.uri');
        if (! $uri) {
            throw new ProviderException('Gemini video job finished without a video (it may have been filtered).');
        }

        return $this->download($key, $uri);
    }

    private function pollInteraction(string $key, string $id): ?GeneratedFile
    {
        $response = $this->interactions($key)->timeout(300)->get(self::BASE.'/interactions/'.rawurlencode($id));
        if ($response->failed()) {
            throw ProviderException::fromResponse('Gemini', $response);
        }

        // A finished reply carries the whole video as base64: decode it once and keep only the video.
        $body = json_decode($response->body(), true) ?? [];
        unset($response);
        $status = $body['status'] ?? null;
        if ($status === 'in_progress') {
            return null;
        }
        if ($status !== 'completed') {
            throw new ProviderException("Gemini video job ended as {$status}.".(isset($body['error']['message']) ? ' '.$body['error']['message'] : ''));
        }

        $video = null;
        foreach ($body['steps'] ?? [] as $step) {
            foreach (($step['type'] ?? null) === 'model_output' ? $step['content'] ?? [] : [] as $content) {
                if (($content['type'] ?? null) === 'video') {
                    $video = $content;
                    break 2;
                }
            }
        }
        unset($body);
        if (! $video) {
            throw new ProviderException('Gemini finished without a video (it may have been filtered).');
        }
        if (! isset($video['data'])) {
            return $this->download($key, $video['uri']);
        }

        $bytes = base64_decode($video['data']);
        $mime = $video['mime_type'] ?? 'video/mp4';
        unset($video);

        return new GeneratedFile($bytes, $mime);
    }

    private function download(string $key, string $uri): GeneratedFile
    {
        $download = $this->client($key)->timeout(300)->withOptions(['allow_redirects' => true])->get($uri);
        if ($download->failed()) {
            throw ProviderException::fromResponse('Gemini', $download);
        }

        return new GeneratedFile($download->body(), $download->header('Content-Type') ?: 'video/mp4');
    }

    public function startResearch(string $key, string $model, ResearchRequest $request): ResearchOutcome
    {
        if ($request->mcpServers) {
            throw ProviderException::unsupported('Gemini', 'MCP connectors (use Claude or OpenAI for research that needs connectors)');
        }

        $tools = [['google_search' => (object) []]];
        if ($request->urls) {
            $tools[] = ['url_context' => (object) []];
        }

        $response = $this->generateContent($key, $model, [
            'systemInstruction' => ['parts' => [['text' => $request->system()]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $request->userPrompt()]]]],
            'tools' => $tools,
        ]);

        $citations = collect($response->json('candidates.0.groundingMetadata.groundingChunks', []))
            ->map(fn ($chunk) => ['url' => $chunk['web']['uri'] ?? null, 'title' => $chunk['web']['title'] ?? null])->all();

        return ResearchOutcome::done(new ResearchResult(
            collect($response->json('candidates.0.content.parts', []))->pluck('text')->filter()->implode(''),
            ResearchResult::uniqueCitations($citations),
            (int) $response->json('usageMetadata.promptTokenCount'),
            (int) $response->json('usageMetadata.candidatesTokenCount'),
        ));
    }

    public function pollResearch(string $key, string $jobId): ?ResearchResult
    {
        throw ProviderException::unsupported('Gemini', 'background research jobs');
    }

    private function generateContent(string $key, string $model, array $body)
    {
        $response = $this->client($key)->timeout(300)->post(self::BASE."/models/{$model}:generateContent", $body);
        if ($response->failed()) {
            throw ProviderException::fromResponse('Gemini', $response);
        }

        return $response;
    }

    private function client(string $key): PendingRequest
    {
        return Http::withHeaders(['x-goog-api-key' => $key])->acceptJson();
    }

    /** The Interactions API is versioned by an Api-Revision header. */
    private function interactions(string $key): PendingRequest
    {
        return $this->client($key)->withHeaders(['Api-Revision' => self::INTERACTIONS_REVISION]);
    }
}
