<?php

namespace App\Services\Engine;

use App\Models\Character;
use App\Models\PromptGuide;
use App\Models\Workspace;
use App\Services\Ai\GeneratedFile;
use App\Services\Ai\ModelRouter;
use App\Services\Ai\ProviderRegistry;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Turns a short brief into a detailed, production-ready image or video prompt, following the
 * workspace's own training (instructions + liked examples) and looking at the reference images.
 */
class PromptWriter
{
    public function __construct(
        private readonly ModelRouter $router,
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * @param  'image'|'video'  $taskType
     * @param  iterable<Character>  $characters
     * @param  list<array{label: string, role: string, file: GeneratedFile}>  $references
     * @return array{prompt: string, provider: string, model: string}
     */
    public function write(Workspace $workspace, string $taskType, string $brief, iterable $characters, array $references, array $options = []): array
    {
        if (trim($brief) === '') {
            throw new InvalidArgumentException('Write a short brief for the AI prompt writer (what you want this '.$taskType.' to show).');
        }

        $guide = $workspace->promptGuides()->where('task_type', $taskType)->first();
        $result = $this->generate($workspace, $guide, $this->system($taskType, $guide), $this->request($brief, $characters, $references, $options), $references,
            fn (string $text) => ($prompt = $this->clean($text)) !== '' ? $prompt : null);

        return ['prompt' => $result['value'], 'provider' => $result['provider'], 'model' => $result['model']];
    }

    /**
     * Split a story into $count scene prompts of one continuous film, for a long video.
     *
     * @return array{scenes: list<string>, provider: string, model: string}
     */
    public function writeScenes(Workspace $workspace, string $story, iterable $characters, array $references, int $count, int $secondsPerScene, array $options = []): array
    {
        if (trim($story) === '') {
            throw new InvalidArgumentException('Write a short brief for the video: what happens from start to finish.');
        }

        $guide = $workspace->promptGuides()->where('task_type', 'video')->first();
        $system = $this->system('video', $guide)."\n\nLONG VIDEO\n"
            ."The video is made of {$count} separate clips of about {$secondsPerScene} seconds each, generated one by one and joined in order. "
            .'Plan ONE continuous story across them: a hook in scene 1, a clear progression, and a satisfying ending in the last scene. '
            .'Write each scene as a complete, self-contained prompt (the video model sees one scene at a time): name the characters, the location, '
            .'the action, camera and sound, and how it picks up exactly where the previous scene ended. Spread any dialogue or voice-over across the scenes; '
            .'each scene\'s spoken lines must fit in its '.$secondsPerScene.' seconds. Never repeat the same action twice.'
            ."\nReply with ONE JSON object and nothing else: {\"scenes\": [\"scene 1 prompt\", \"scene 2 prompt\", ...]} with exactly {$count} scenes.";
        $request = $this->request($story, $characters, $references, $options)."\n\nLENGTH: about ".($count * $secondsPerScene)." seconds = {$count} scenes.";

        $result = $this->generate($workspace, $guide, $system, $request, $references, function (string $text) use ($count) {
            $scenes = FlowGenerator::decode($text)['scenes'] ?? null;
            if (! is_array($scenes)) {
                return null;
            }
            $scenes = array_values(array_filter(array_map(fn ($s) => $this->clean(is_array($s) ? (string) ($s['prompt'] ?? '') : (string) $s), $scenes)));

            // A scene or two too many or too few is fine; the length adjusts slightly.
            return count($scenes) >= max(1, $count - 2) ? array_slice($scenes, 0, $count + 2) : null;
        });

        return ['scenes' => $result['value'], 'provider' => $result['provider'], 'model' => $result['model']];
    }

    /**
     * Ask the trained model first, then the workspace's text models, until $accept returns a value.
     *
     * @param  callable(string): mixed  $accept
     * @return array{value: mixed, provider: string, model: string}
     */
    private function generate(Workspace $workspace, ?PromptGuide $guide, string $system, string $request, array $references, callable $accept): array
    {
        // Only images are shown to the writer; text models can't take the video clip.
        $images = array_values(array_filter(array_column($references, 'file'), fn (GeneratedFile $f) => ! str_starts_with($f->mime, 'video/')));

        $candidates = $guide?->credential_id && $guide->model
            ? [['credential' => $guide->credential, 'model' => $guide->model], ...$this->router->candidates($workspace, 'text', [])]
            : $this->router->candidates($workspace, 'text', []);

        $error = null;
        foreach ($candidates as ['credential' => $credential, 'model' => $model]) {
            if (! $credential) {
                continue;
            }
            try {
                $result = $this->providers->for($credential->provider)->generateText($credential->secret(), $model, $request, $system, $images);
                $value = $accept($result->text);
                if ($value !== null) {
                    return ['value' => $value, 'provider' => $credential->provider, 'model' => $model];
                }
                $error = new RuntimeException('the model returned an unusable answer');
            } catch (Throwable $e) {
                $error = $e;
            }
        }

        throw new RuntimeException('The AI prompt writer failed'.($error ? ': '.$error->getMessage() : '.'));
    }

    private function system(string $taskType, ?PromptGuide $guide): string
    {
        $medium = $taskType === 'video' ? 'AI video generation model (shots, camera movement, action beats, dialogue, sound)' : 'AI image generation model (composition, subject, lighting, camera, colour, any on-image text)';

        $parts = [
            "You are an expert prompt writer for an {$medium}. Turn the user's brief into ONE detailed, production-ready prompt.",
            'Rules:
- Output only the prompt itself. No preamble, no explanations, no markdown headings, no quotes around it.
- Be concrete and visual. Never leave blanks, placeholders or square-bracket options: decide every detail yourself in line with the brief and the style guide.
- Any text that must appear on the image goes in double quotes, spelled exactly, and is kept short.
- Refer to recurring characters by name only. Do not describe or change their face, hair, body or outfit: their exact look is supplied to the model separately from their reference images.
- Respect what each reference is for (its role) and follow the user\'s "how and where to use it" notes exactly, e.g. "the logo from reference image 3, small, top-left".
- Every PERSON, PRODUCT and LOGO reference must appear in the result.
- When a colour theme is given, use those colours but invent a completely original layout and content from the brief. Never describe or reuse the layout, headline wording, icons or structure of any reference unless its role is "Match closely".
- Stay safe and brand-appropriate; no real public figures, logos or trademarks unless they are the user\'s own.',
        ];

        if ($guide?->instructions) {
            $parts[] = "STYLE GUIDE FROM THE USER (always follow it):\n".trim($guide->instructions);
        }

        if ($guide?->examples) {
            $examples = array_map(fn ($e, $i) => 'Example '.($i + 1).($e['note'] ? " ({$e['note']})" : '').":\n".$e['prompt'], $guide->examples, array_keys($guide->examples));
            $parts[] = "PROMPTS THE USER LIKED. Match their structure, level of detail and tone (not their content):\n\n".implode("\n\n", $examples);
        }

        return implode("\n\n", $parts);
    }

    private function request(string $brief, iterable $characters, array $references, array $options): string
    {
        $lines = ["BRIEF:\n".trim($brief)];

        $cast = [];
        foreach ($characters as $character) {
            $cast[] = "- {$character->name} ({$character->styleDescription()})";
        }
        if ($cast) {
            $lines[] = "CHARACTERS who must appear (refer to them by name):\n".implode("\n", $cast);
        }

        if ($references) {
            $lines[] = "ATTACHED REFERENCES, in order (images are shown to you; video clips are described only):\n".implode("\n", array_map(
                fn ($ref) => "- {$ref['label']}: ".($ref['role'] === 'character' ? 'character sheet / photo of a recurring character' : ReferenceRoles::label($ref['role']))
                    .(! empty($ref['note']) ? " — the user says how and where to use it: \"{$ref['note']}\"" : ''),
                $references,
            ));
        }

        foreach ($options['palettes'] ?? [] as $palette) {
            $lines[] = "COLOUR THEME to build the design around (from a reference you are deliberately not shown, so describe a new layout of your own): {$palette['palette']}"
                .(! empty($palette['note']) ? " — the user adds: \"{$palette['note']}\"" : '');
        }

        if (! empty($options['aspect_ratio'])) {
            $lines[] = "FORMAT: {$options['aspect_ratio']}";
        }

        return implode("\n\n", $lines);
    }

    private function clean(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text);
        $text = preg_replace('/^(final\s+)?prompt\s*:\s*/i', '', trim($text));

        return trim($text, " \n\"");
    }
}
