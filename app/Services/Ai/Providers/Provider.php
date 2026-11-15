<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\GeneratedFile;
use App\Services\Ai\ResearchOutcome;
use App\Services\Ai\ResearchRequest;
use App\Services\Ai\ResearchResult;
use App\Services\Ai\TextResult;

interface Provider
{
    /** @return list<string> model ids, fetched live so new models need no release */
    public function listModels(string $key): array;

    /** @param  list<GeneratedFile>  $images  images the model should look at (ignored by text-only providers) */
    public function generateText(string $key, string $model, string $prompt, ?string $system = null, array $images = []): TextResult;

    /** @param  list<GeneratedFile>  $references  images to follow for product, style or character */
    public function generateImage(string $key, string $model, string $prompt, array $references = []): GeneratedFile;

    /**
     * Submit a long-running video job and return its operation id.
     *
     * @param  list<GeneratedFile>  $references  character / subject images to keep consistent
     */
    public function startVideo(string $key, string $model, string $prompt, array $references = [], ?string $aspectRatio = null): string;

    /** Returns the finished video, or null while the job is still running. */
    public function pollVideo(string $key, string $operation): ?GeneratedFile;

    /** Web research with citations; may hand back a job id when the provider runs it in the background. */
    public function startResearch(string $key, string $model, ResearchRequest $request): ResearchOutcome;

    /** Returns the finished report, or null while a background research job is still running. */
    public function pollResearch(string $key, string $jobId): ?ResearchResult;
}
