<?php

namespace App\Support;

use App\Services\Engine\CharacterConsistency;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The fields of a flow's form. They are stored on the trigger node as data.form.fields and each
 * value reaches later nodes as {{trigger.output.<key>}}.
 */
class FormFields
{
    public const TYPES = ['text', 'textarea', 'select', 'number', 'email', 'url', 'image', 'character'];

    public const MAX_FIELDS = 15;

    /**
     * Clean a list of fields from the editor or the AI generator: valid keys, known types, sane lengths.
     *
     * @return list<array{key: string, label: string, type: string, required: bool, placeholder: string, help: string, options: list<string>}>
     */
    public static function normalize(array $fields): array
    {
        $clean = [];
        $seen = [];

        foreach (array_slice(array_values($fields), 0, self::MAX_FIELDS) as $field) {
            if (! is_array($field)) {
                continue;
            }
            $label = mb_substr(trim((string) ($field['label'] ?? '')), 0, 120);
            $key = self::key((string) ($field['key'] ?? '') ?: $label);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $type = in_array($field['type'] ?? null, self::TYPES, true) ? $field['type'] : 'text';
            $options = array_values(array_unique(array_filter(array_map(
                fn ($o) => mb_substr(trim((string) $o), 0, 100),
                array_slice((array) ($field['options'] ?? []), 0, 30),
            ), fn ($o) => $o !== '')));
            if ($type === 'select' && $options === []) {
                $type = 'text';
            }

            $clean[] = [
                'key' => $key,
                'label' => $label !== '' ? $label : Str::headline($key),
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'placeholder' => mb_substr(trim((string) ($field['placeholder'] ?? '')), 0, 200),
                'help' => mb_substr(trim((string) ($field['help'] ?? '')), 0, 300),
                'options' => $type === 'select' ? $options : [],
                // Character questions: let visitors pick up to CharacterConsistency::MAX_CHARACTERS
                'multiple' => $type === 'character' && (bool) ($field['multiple'] ?? false),
            ];
        }

        return $clean;
    }

    public static function key(string $value): string
    {
        $key = Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(40, '')->toString();

        return preg_match('/^[a-z]/', $key) ? $key : ($key === '' ? '' : 'f_'.$key);
    }

    /** The trigger node's fields, if any. */
    public static function fromGraph(array $graph): array
    {
        foreach ($graph['nodes'] ?? [] as $node) {
            if (($node['type'] ?? null) === 'trigger.manual') {
                return self::normalize($node['data']['form']['fields'] ?? []);
            }
        }

        return [];
    }

    /**
     * A Saved character answer is a character id, not a picture. When an Image/Video step lists
     * such an answer as a reference (e.g. "Use as Person"), use it as the visitor's character
     * instead: it then gets the full character treatment (sheet + identity block).
     */
    public static function characterReferencesToCast(array $graph): array
    {
        $trigger = collect($graph['nodes'] ?? [])->firstWhere('type', 'trigger.manual');
        $characterKeys = array_column(array_filter(self::fromGraph($graph), fn ($f) => $f['type'] === 'character'), 'key');
        if (! $trigger || ! $characterKeys) {
            return $graph;
        }
        $name = preg_quote($trigger['data']['key'] ?? $trigger['id'], '/');

        foreach ($graph['nodes'] as &$node) {
            if (! in_array($node['type'], ['ai.image', 'ai.video'], true) || empty($node['data']['references'])) {
                continue;
            }
            $kept = [];
            foreach ($node['data']['references'] as $ref) {
                $src = trim((string) (is_array($ref) ? ($ref['src'] ?? '') : $ref));
                if (preg_match('/^\{\{\s*'.$name.'\.output\.([A-Za-z0-9_\-]+)\s*\}\}$/', $src, $m) && in_array($m[1], $characterKeys, true)) {
                    $ids = $node['data']['character_ids'] ?? [];
                    if (! in_array($src, $ids, true)) {
                        $node['data']['character_ids'] = [...$ids, $src];
                    }

                    continue;
                }
                $kept[] = $ref;
            }
            $node['data']['references'] = $kept;
        }

        return $graph;
    }

    /**
     * Laravel validation rules for a submission, keyed by field key.
     *
     * @param  list<int>  $characterIds  the workspace's characters, the only ones a visitor may pick
     */
    public static function rules(array $fields, array $characterIds = []): array
    {
        $rules = [];
        foreach ($fields as $field) {
            $base = [$field['required'] ? 'required' : 'nullable'];
            if ($field['type'] === 'character') {
                if ($field['multiple']) {
                    $rules[$field['key']] = [...$base, 'array', 'max:'.CharacterConsistency::MAX_CHARACTERS];
                    $rules[$field['key'].'.*'] = ['integer', Rule::in($characterIds)];
                } else {
                    $rules[$field['key']] = [...$base, 'integer', Rule::in($characterIds)];
                }

                continue;
            }
            $rules[$field['key']] = [...$base, ...match ($field['type']) {
                'textarea' => ['string', 'max:5000'],
                'select' => ['string', Rule::in($field['options'])],
                'number' => ['numeric'],
                'email' => ['email', 'max:200'],
                'url' => ['url:https,http', 'max:500'],
                'image' => ['image', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
                default => ['string', 'max:500'],
            }];
        }

        return $rules;
    }

    /** Readable names in validation messages ("The Product name field is required."). */
    public static function attributes(array $fields): array
    {
        return array_column($fields, 'label', 'key');
    }
}
