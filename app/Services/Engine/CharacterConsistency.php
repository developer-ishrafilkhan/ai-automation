<?php

namespace App\Services\Engine;

use App\Models\Character;
use App\Services\Ai\GeneratedFile;
use InvalidArgumentException;

/**
 * Turns the characters picked on a node into (a) reference images, sent before any other
 * reference, and (b) an identity block for the top of the prompt, so the same character
 * looks the same in every image and video. PromptAssembler puts the pieces together.
 */
class CharacterConsistency
{
    public const MAX_CHARACTERS = 3;

    /**
     * A node's character list holds ids picked on the canvas and, for public forms, the visitor's
     * pick as "{{trigger.output.character}}", which arrives here rendered as "12" or "12,15".
     *
     * @return list<int>
     */
    public static function ids(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            preg_match_all('/\d+/', (string) $value, $m);
            array_push($ids, ...array_map('intval', $m[0]));
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param  list<int|string>  $characterIds
     * @param  string  $refStyle  "numbered" for image models, "omni" for Gemini Omni's <IMAGE_REF_n> tags
     * @return array{references: list<GeneratedFile>, block: string, names: list<string>}
     */
    public function apply(int $workspaceId, array $characterIds, string $refStyle = 'numbered'): array
    {
        $characterIds = self::ids($characterIds);
        if (! $characterIds) {
            return ['characters' => collect(), 'references' => [], 'block' => '', 'names' => []];
        }
        if (count($characterIds) > self::MAX_CHARACTERS) {
            throw new InvalidArgumentException('Use at most '.self::MAX_CHARACTERS.' characters per node.');
        }

        $characters = Character::where('workspace_id', $workspaceId)->whereIn('id', $characterIds)->get();
        if ($characters->count() !== count($characterIds)) {
            throw new InvalidArgumentException('A selected character no longer exists in this workspace.');
        }

        $references = [];
        $blocks = [];
        foreach ($characters as $character) {
            $images = $character->referenceImages();
            $labels = [];
            foreach ($images as $image) {
                $index = count($references);
                $references[] = $image;
                $labels[] = $refStyle === 'omni' ? "<IMAGE_REF_{$index}>" : 'reference image '.($index + 1);
            }

            $shown = $labels ? 'shown in '.implode(', ', $labels) : 'described below (no reference image yet)';
            $blocks[] = trim(implode("\n", array_filter([
                "• {$character->name}: {$shown}.",
                "  Appearance (never change): {$character->appearance}",
                "  Art style: {$character->styleDescription()}.",
                $refStyle === 'omni' && $character->voice ? "  Voice: {$character->voice}" : null,
            ])));
        }

        $header = <<<'TXT'
CHARACTER CONSISTENCY — HIGHEST PRIORITY
The following recurring character(s) must look exactly like their reference images in every detail: same face shape and facial features, eyes, nose, mouth, skin tone, hair colour, length and style, body type and proportions, age, outfit and accessories, and the same art style. Do not redesign, beautify, age or restyle them. Only pose, expression, camera angle and setting may change to fit the scene. If the scene description below conflicts with a character's appearance, keep the character's appearance.

TXT;

        $names = $characters->pluck('name')->all();
        $presence = count($names) === 1
            ? "{$names[0]} must appear in this image as a clearly visible main subject, even if the scene below doesn't mention them."
            : implode(' and ', $names).' must all appear in this image as clearly visible main subjects, even if the scene below doesn\'t mention them.';

        return [
            'characters' => $characters,
            'references' => $references,
            'block' => $header.implode("\n", $blocks)."\n".$presence,
            'names' => $characters->pluck('name')->all(),
        ];
    }
}
