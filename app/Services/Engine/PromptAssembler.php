<?php

namespace App\Services\Engine;

use App\Models\Workspace;
use App\Services\Ai\GeneratedFile;
use App\Support\Mp4Duration;
use InvalidArgumentException;

/**
 * Builds the final prompt and image list for Image and Video nodes:
 * character images first, then the node's reference images, each described by its role,
 * then the user's scene.
 */
class PromptAssembler
{
    public function __construct(
        private readonly CharacterConsistency $characters,
        private readonly ReferenceImages $referenceImages,
        private readonly ReferenceRoles $roles,
        private readonly PromptWriter $writer,
        private readonly ColorPalette $palette,
    ) {}

    /**
     * @param  string  $refStyle  "numbered" for image models, "omni" for Gemini Omni's <IMAGE_REF_n> tags
     * @return array{references: list<GeneratedFile>, prompt: string, written_by: ?string}
     */
    public function build(int $workspaceId, array $data, string $refStyle = 'numbered'): array
    {
        $parts = $this->prepare($workspaceId, $data, $refStyle);

        // AI mode: the prompt writer turns the brief into the scene. The identity and reference
        // blocks are still added verbatim, so character consistency never depends on it.
        $writtenBy = null;
        if (self::usesWriter($data)) {
            $written = $this->writer->write(
                Workspace::findOrFail($workspaceId),
                $parts['is_video'] ? 'video' : 'image',
                (string) ($data['brief'] ?? ''),
                $parts['cast']['characters'],
                $parts['seen'],
                ['aspect_ratio' => $data['aspect_ratio'] ?? null, 'palettes' => $parts['palettes']],
            );
            $scene = $written['prompt'];
            $writtenBy = "{$written['provider']} · {$written['model']}";
        } else {
            $scene = (string) ($data['prompt'] ?? '');
        }

        return ['references' => $parts['references'], 'prompt' => $this->wrap($parts['blocks'], $scene), 'written_by' => $writtenBy];
    }

    /**
     * A long video: the prompt writer splits the brief (or hand-written story) into $count scenes of
     * one continuous film. Every scene gets the same identity and reference blocks and references,
     * so the characters stay identical from clip to clip.
     *
     * @return array{references: list<GeneratedFile>, prompts: list<string>, written_by: string}
     */
    public function buildScenes(int $workspaceId, array $data, int $count, int $secondsPerScene): array
    {
        $parts = $this->prepare($workspaceId, $data, 'omni');
        if ($parts['clips']) {
            throw new InvalidArgumentException('Long videos are made from new scenes, so they can\'t edit or continue an uploaded clip. Remove the clip reference or set Length to "One clip".');
        }

        $story = self::usesWriter($data) ? (string) ($data['brief'] ?? '') : (string) ($data['prompt'] ?? '');
        $planned = $this->writer->writeScenes(
            Workspace::findOrFail($workspaceId),
            $story,
            $parts['cast']['characters'],
            $parts['seen'],
            $count,
            $secondsPerScene,
            ['aspect_ratio' => $data['aspect_ratio'] ?? null, 'palettes' => $parts['palettes']],
        );

        $prompts = [];
        foreach ($planned['scenes'] as $i => $scene) {
            $prompts[] = $this->wrap($parts['blocks'], 'This is scene '.($i + 1).' of '.count($planned['scenes']).' of one continuous film. '
                .'Keep every character, outfit, location, lighting, colour grade and art style identical to the other scenes. '
                ."In a single continuous shot.\n\n".$scene);
        }

        return ['references' => $parts['references'], 'prompts' => $prompts, 'written_by' => "{$planned['provider']} · {$planned['model']}"];
    }

    /** The reference files only (characters first), e.g. to submit the next scene of a long video. */
    public function references(int $workspaceId, array $data, string $refStyle = 'omni'): array
    {
        return $this->prepare($workspaceId, $data, $refStyle)['references'];
    }

    private function prepare(int $workspaceId, array $data, string $refStyle): array
    {
        $isVideo = $refStyle === 'omni';
        $cast = $this->characters->apply($workspaceId, $data['character_ids'] ?? [], $refStyle);
        $resolved = $this->referenceImages->resolve($workspaceId, $data['references'] ?? []);
        $this->validateMedia($resolved, $isVideo);

        // "Colour theme only" images are never shown to a model (they'd copy the layout); only their palette is.
        $palettes = [];
        $images = [];
        $clips = [];
        foreach ($resolved as $ref) {
            match (true) {
                $ref['kind'] === 'video' => $clips[] = $ref,
                $ref['role'] === 'color_theme' => $palettes[] = ['palette' => $this->palette->describe($ref['file']), 'note' => $ref['note']],
                default => $images[] = $ref,
            };
        }

        $offset = count($cast['references']);
        $imageLabels = array_map(fn ($i) => $isVideo ? '<IMAGE_REF_'.($offset + $i).'>' : 'reference image '.($offset + $i + 1), array_keys($images));
        $refs = [...$images, ...$clips];
        $labels = [...$imageLabels, ...array_map(fn () => 'the attached video clip', $clips)];
        $characterLabels = array_map(fn ($i) => $isVideo ? "<IMAGE_REF_{$i}>" : 'reference image '.($i + 1), array_keys($cast['references']));

        return [
            'is_video' => $isVideo,
            'cast' => $cast,
            'clips' => $clips,
            'palettes' => $palettes,
            'references' => [...$cast['references'], ...array_column($refs, 'file')],
            'blocks' => array_filter([$cast['block'], $this->roles->block($labels, $refs, $cast['names'], $palettes)]),
            // What the prompt writer is shown, in order.
            'seen' => [
                ...array_map(fn ($label, $file) => ['label' => $label, 'role' => 'character', 'note' => null, 'file' => $file], $characterLabels, $cast['references']),
                ...array_map(fn ($label, $ref) => ['label' => $label, 'role' => $ref['role'], 'note' => $ref['note'], 'file' => $ref['file']], $labels, $refs),
            ],
        ];
    }

    private function wrap(array $blocks, string $scene): string
    {
        return $blocks ? implode("\n\n", $blocks)."\n\nSCENE:\n".$scene : $scene;
    }

    /** Clips only work on Video nodes (Gemini Omni edits or continues one clip of up to 10 seconds). */
    private function validateMedia(array $resolved, bool $isVideo): void
    {
        $clips = array_values(array_filter($resolved, fn ($r) => $r['kind'] === 'video'));

        foreach ($resolved as $ref) {
            if ($ref['kind'] === 'image' && ReferenceRoles::isVideoRole($ref['role'])) {
                throw new InvalidArgumentException('"'.ReferenceRoles::label($ref['role']).'" needs a video clip, not an image.');
            }
        }
        if (! $clips) {
            return;
        }
        if (! $isVideo) {
            throw new InvalidArgumentException('Video clips can only be used as references on Video nodes.');
        }
        if (count($clips) > 1) {
            throw new InvalidArgumentException('Use one video clip per Video node (the video model takes a single clip).');
        }
        if (! ReferenceRoles::isVideoRole($clips[0]['role'])) {
            throw new InvalidArgumentException('Choose what to do with the video clip: "Edit this clip" or "Continue this clip".');
        }
        $seconds = Mp4Duration::seconds($clips[0]['file']->bytes);
        if ($seconds !== null && $seconds > 10.5) {
            throw new InvalidArgumentException(sprintf('The video clip is %.0f seconds long; the video model accepts clips of up to 10 seconds.', $seconds));
        }
    }

    /** New nodes use the AI writer; older nodes that only have a hand-written prompt keep it. */
    public static function usesWriter(array $data): bool
    {
        return ($data['prompt_mode'] ?? (isset($data['brief']) ? 'ai' : 'manual')) === 'ai';
    }
}
