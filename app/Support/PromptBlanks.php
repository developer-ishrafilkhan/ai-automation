<?php

namespace App\Support;

use App\Services\Engine\PromptAssembler;

/**
 * Finds [FILL-IN] blanks left over from ready-made prompts, e.g. "[NEW HEADLINE]",
 * "[e.g. Tip #4]" or "[1:1 / 4:5 / 9:16]". Sent as-is, the model treats the example
 * text as the brief, so runs are stopped until they are filled in.
 */
class PromptBlanks
{
    private const PATTERN = '/\[(?:[^\[\]\n]*[A-Z]{2,}[^\[\]\n]*|e\.g\.[^\[\]\n]*|[^\[\]\n\s]+(?:\s*\/\s*[^\[\]\n\s]+)+)\](?!\()/';

    /** Fields of a node that are sent to a model as text. */
    private const FIELDS = ['prompt', 'system', 'instructions'];

    /** @return list<string> */
    public static function in(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /** @return list<array{node: string, blanks: list<string>}> */
    public static function inGraph(array $graph): array
    {
        $found = [];
        foreach ($graph['nodes'] ?? [] as $node) {
            $text = implode("\n", array_filter(array_map(fn ($f) => is_string($node['data'][$f] ?? null) ? $node['data'][$f] : null, self::fieldsInUse($node))));
            if ($blanks = self::in($text)) {
                $found[] = ['node' => $node['data']['label'] ?? $node['id'], 'blanks' => $blanks];
            }
        }

        return $found;
    }

    /**
     * Only check text that will actually be sent: in AI mode an Image/Video node uses its brief,
     * and a hand-written prompt left over from manual mode is ignored.
     */
    private static function fieldsInUse(array $node): array
    {
        if (in_array($node['type'] ?? null, ['ai.image', 'ai.video'], true)) {
            return PromptAssembler::usesWriter($node['data'] ?? []) ? ['brief'] : ['prompt'];
        }

        return self::FIELDS;
    }

    /** @return list<string> labels of AI-mode Image/Video nodes that have no brief yet */
    public static function missingBriefs(array $graph): array
    {
        $missing = [];
        foreach ($graph['nodes'] ?? [] as $node) {
            $data = $node['data'] ?? [];
            if (in_array($node['type'] ?? null, ['ai.image', 'ai.video'], true) && PromptAssembler::usesWriter($data) && trim((string) ($data['brief'] ?? '')) === '') {
                $missing[] = $data['label'] ?? $node['id'];
            }
        }

        return $missing;
    }
}
