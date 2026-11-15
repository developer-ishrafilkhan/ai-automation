<?php

namespace App\Services\Engine;

/** What the model should take from each reference, plus the user's own "how & where" note. */
class ReferenceRoles
{
    /** Roles for images. */
    public const ROLES = [
        'auto' => 'Other (explain below)',
        'person' => 'Person to include',
        'product' => 'Product (keep exactly)',
        'logo' => 'Logo',
        'match' => 'Match closely',
        'swap_character' => 'Change character',
        'color_theme' => 'Colour theme only',
        'style' => 'Style only',
    ];

    /** Roles for video clips (Video nodes only; Gemini Omni edits or continues one clip of up to 10 seconds). */
    public const VIDEO_ROLES = [
        'edit_video' => 'Edit this clip',
        'extend_video' => 'Continue this clip',
    ];

    public static function isVideoRole(string $role): bool
    {
        return array_key_exists($role, self::VIDEO_ROLES);
    }

    public static function label(string $role): string
    {
        return self::ROLES[$role] ?? self::VIDEO_ROLES[$role] ?? 'Other';
    }

    /**
     * @param  list<string>  $labels  how each reference is named in the prompt ("reference image 3", "<IMAGE_REF_2>", "the attached video clip")
     * @param  list<array{role: string, note?: ?string}>  $refs  same order as $labels
     * @param  list<string>  $characterNames  characters picked on the node, for "Change character"
     * @param  list<array{palette: string, note?: ?string}>  $palettes  "Colour theme only" references (not shown as images)
     */
    public function block(array $labels, array $refs, array $characterNames, array $palettes = []): string
    {
        $lines = [];

        // Only plain references without notes: send them with the prompt unchanged, as before roles existed.
        $plain = array_filter($refs, fn ($r) => ($r['role'] ?? 'auto') !== 'auto' || ! empty($r['note']));
        if ($labels && $plain) {
            $lines[] = 'REFERENCES — how to use each one:';
            foreach ($labels as $i => $label) {
                $lines[] = '• '.ucfirst($label).': '.$this->instruction($refs[$i]['role'] ?? 'auto', $characterNames, $refs[$i]['note'] ?? null);
            }
        }

        foreach ($palettes as $palette) {
            $lines[] = self::paletteInstruction($palette['palette'], $palette['note'] ?? null);
        }

        return implode("\n", $lines);
    }

    /** Colour themes are passed as colours only; the source image is never shown, so its layout can't be copied. */
    public static function paletteInstruction(string $palette, ?string $note = null): string
    {
        return "COLOUR THEME (taken from a reference image that is deliberately not shown): {$palette}. "
            .'Use the largest colours for backgrounds and big areas and the small ones only as accents, in roughly these proportions. '
            .'Match the overall mood these colours create. Layout, subject, text and every other detail come only from the scene.'
            .self::noteSentence($note);
    }

    private function instruction(string $role, array $characterNames, ?string $note): string
    {
        $newCharacter = $characterNames
            ? 'the character '.implode(' and ', $characterNames).' (exactly as in their own reference images)'
            : 'the character described in the scene below';

        $base = match ($role) {
            'person' => 'PERSON. This person must appear in the result. Keep their exact likeness: face shape and features, skin tone, hair and build. Pose, expression and clothing may change only to fit the scene.',
            'product' => 'PRODUCT. Show this exact product unchanged: same shape, proportions, colours, materials, label text and logo placement. Do not redesign it.',
            'logo' => 'LOGO. Place this exact logo in the design, unchanged: same shapes, colours, proportions and lettering. Do not redraw, restyle, recolour, crop or distort it. Keep it crisp and legible with clear space around it'.($note ? '.' : ', in a corner where it suits the layout.'),
            'match' => 'MATCH CLOSELY. Recreate this image as faithfully as possible: same composition and framing, subject placement, pose, camera angle, lighting, colour palette, background, textures and overall style. Change only what the scene below explicitly asks to change.',
            'swap_character' => "CHANGE CHARACTER. Keep everything about this image the same (composition, background, setting, lighting, colours, camera angle, pose and framing), but replace the person or character in it with {$newCharacter}. Do not keep any facial features, hair or identity of the original person.",
            'style' => 'STYLE ONLY. Take its visual style (rendering technique, medium, texture, line quality, typography feel and finish) but create new content and a new layout as described below. Do not copy its subject or composition.',
            'edit_video' => 'EDIT THIS CLIP. Keep everything in it exactly as it is, except the changes the scene below describes.',
            'extend_video' => 'CONTINUE THIS CLIP from its last frame: same people, setting, lighting and camera style, adding what the scene below describes.',
            default => $note ? 'Use it exactly as the user explains' : 'General reference: use it as guidance for the scene below.',
        };

        return $base.self::noteSentence($note, $role === 'auto' ? ': ' : null);
    }

    private static function noteSentence(?string $note, ?string $glue = null): string
    {
        $note = trim((string) $note);

        return $note === '' ? '' : ($glue ?? ' How and where to use it: ').'"'.$note.'"';
    }
}
