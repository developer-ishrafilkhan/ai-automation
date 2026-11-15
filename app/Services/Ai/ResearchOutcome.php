<?php

namespace App\Services\Ai;

/** Either a finished report, or a background job id to poll (OpenAI deep research). */
final class ResearchOutcome
{
    private function __construct(public readonly ?ResearchResult $result, public readonly ?string $jobId) {}

    public static function done(ResearchResult $result): self
    {
        return new self($result, null);
    }

    public static function pending(string $jobId): self
    {
        return new self(null, $jobId);
    }
}
