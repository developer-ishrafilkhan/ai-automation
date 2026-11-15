<?php

namespace App\Services\Ai;

final class ResearchResult
{
    /** @param  list<array{url: string, title: string}>  $citations */
    public function __construct(
        public readonly string $text,
        public readonly array $citations = [],
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    /** @param  list<array{url: ?string, title: ?string}>  $raw */
    public static function uniqueCitations(array $raw): array
    {
        $seen = [];
        foreach ($raw as $citation) {
            if (! empty($citation['url']) && ! isset($seen[$citation['url']])) {
                $seen[$citation['url']] = ['url' => $citation['url'], 'title' => $citation['title'] ?: $citation['url']];
            }
        }

        return array_values($seen);
    }
}
