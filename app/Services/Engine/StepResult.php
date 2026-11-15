<?php

namespace App\Services\Engine;

final class StepResult
{
    private function __construct(
        public readonly bool $waiting,
        public readonly array $output = [],
        public readonly array $state = [],
        public readonly int $resumeAfterSeconds = 0,
        public readonly ?array $tokens = null,
    ) {}

    public static function done(array $output, ?array $tokens = null): self
    {
        return new self(false, $output, tokens: $tokens);
    }

    /** Park the step (e.g. a video job is rendering); the run resumes later without holding a worker. */
    public static function wait(array $state, int $resumeAfterSeconds): self
    {
        return new self(true, state: $state, resumeAfterSeconds: $resumeAfterSeconds);
    }
}
