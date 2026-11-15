<?php

namespace App\Services\Engine;

use App\Models\Workspace;
use App\Services\Ai\ModelRouter;
use App\Services\Ai\ProviderRegistry;
use App\Services\Engine\Nodes\VideoNode;
use App\Support\FormFields;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Designs a whole flow (steps, links and the public form) from a plain-language description,
 * using the workspace's own text model. Whatever the model returns is cleaned up into a graph
 * the engine can run: unknown node types, broken links and loops are dropped, missing links
 * are added, and every {{trigger.output.x}} the steps use gets a form field.
 */
class FlowGenerator
{
    public const MAX_NODES = 10;

    private const AI_TYPES = ['ai.text', 'ai.research', 'ai.image', 'ai.video'];

    private const LABELS = ['ai.text' => 'Text / Chat', 'ai.research' => 'Research', 'ai.image' => 'Image', 'ai.video' => 'Video'];

    public function __construct(
        private readonly ModelRouter $router,
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * @return array{name: string, graph: array, form: array{title: string, description: string, submit_label: string}}
     */
    public function generate(Workspace $workspace, string $description): array
    {
        $context = $this->workspaceContext($workspace);
        $spec = $this->askJson($workspace, $this->system($context), "Design a flow for this request:\n\n".trim($description));

        return $this->build($spec, $context);
    }

    /**
     * Form fields for an existing flow: one for every {{trigger.output.x}} its steps read, with
     * labels, types and placeholders written by the AI.
     */
    public function suggestForm(Workspace $workspace, array $graph): array
    {
        $characterKeys = $this->characterKeysUsed($graph['nodes'] ?? []);
        $used = array_values(array_filter($this->triggerKeysUsed($graph['nodes'] ?? []),
            fn ($k) => ! (str_ends_with($k, '_name') && in_array(substr($k, 0, -5), $characterKeys, true))));
        if ($used === []) {
            throw new InvalidArgumentException('No step reads anything from the form yet. Use {{trigger.output.your_field}} in a prompt or brief first.');
        }

        $steps = collect($graph['nodes'] ?? [])->where('type', '!=', 'trigger.manual')->map(fn ($n) => '- '.($n['data']['label'] ?? $n['id']).': '
            .Str::limit((string) ($n['data']['brief'] ?? $n['data']['prompt'] ?? ''), 400))->implode("\n");

        $spec = $this->askJson($workspace, $this->formSystem(), "The flow's steps:\n{$steps}\n\nFields needed (use exactly these keys, in a sensible order): ".implode(', ', $used));
        $fields = FormFields::normalize($spec['fields'] ?? []);

        // Keep only the keys the flow uses, and never lose one.
        $byKey = array_column($fields, null, 'key');

        return [
            'fields' => array_map(function ($key) use ($byKey, $characterKeys) {
                $field = $byKey[$key] ?? $this->fallbackField($key);

                return in_array($key, $characterKeys, true) ? ['type' => 'character', 'options' => []] + $field : $field;
            }, $used),
            'title' => mb_substr(trim((string) ($spec['title'] ?? '')), 0, 120),
            'description' => mb_substr(trim((string) ($spec['description'] ?? '')), 0, 500),
        ];
    }

    /**
     * Turn the model's plan into a runnable graph. Public so it can be tested without a model.
     *
     * @param  array{characters: array<int, string>, connectors: array<int, string>}  $context
     */
    public function build(array $spec, array $context = ['characters' => [], 'connectors' => []]): array
    {
        $fields = FormFields::normalize($spec['form']['fields'] ?? []);
        $examples = [];
        foreach ($spec['form']['fields'] ?? [] as $raw) {
            $key = FormFields::key((string) ($raw['key'] ?? $raw['label'] ?? ''));
            if ($key !== '' && is_scalar($raw['example'] ?? null)) {
                $examples[$key] = mb_substr((string) $raw['example'], 0, 500);
            }
        }

        // 1. Steps, keyed by their reference key.
        $nodes = [];
        foreach (array_slice((array) ($spec['nodes'] ?? []), 0, self::MAX_NODES) as $raw) {
            $type = $raw['type'] ?? null;
            if (! is_array($raw) || ! in_array($type, self::AI_TYPES, true)) {
                continue;
            }
            $key = $this->uniqueKey((string) ($raw['key'] ?? $raw['label'] ?? 'step'), array_keys($nodes));
            $nodes[$key] = [
                'type' => $type,
                'data' => ['key' => $key, 'label' => mb_substr(trim((string) ($raw['label'] ?? '')), 0, 80) ?: self::LABELS[$type]]
                    + $this->nodeData($type, (array) ($raw['data'] ?? []), $context)
                    + (($raw['show_in_result'] ?? true) === false ? ['hide_in_form' => true] : []),
            ];
        }
        if ($nodes === []) {
            throw new RuntimeException('The AI did not return any usable steps. Try describing the result you want in more detail.');
        }

        // 2. Links. What a step reads ({{key.output...}}) is the real dependency, so those come first:
        //    reads of earlier steps, then of later ones. The model's own edge list fills in the rest.
        //    Anything that would create a loop is dropped.
        $edges = [];
        $order = array_flip(array_keys($nodes));
        foreach ([true, false] as $earlierOnly) {
            foreach ($nodes as $key => &$node) {
                // A closure, not fn(): $edges and $fields must be updated by reference.
                $node['data'] = $this->mapStrings($node['data'], function (string $text) use ($key, $earlierOnly, $order, &$edges, $nodes, &$fields) {
                    return $this->resolveReferences($text, $key, $earlierOnly, $order, $edges, $nodes, $fields);
                });
            }
            unset($node);
        }
        foreach ((array) ($spec['edges'] ?? []) as $edge) {
            if (is_array($edge)) {
                $this->link($edges, (string) ($edge['from'] ?? $edge['source'] ?? $edge[0] ?? ''), (string) ($edge['to'] ?? $edge['target'] ?? $edge[1] ?? ''), $nodes);
            }
        }

        // 3. Anything with no input starts from the form.
        $targets = array_column($edges, 1);
        foreach (array_keys($nodes) as $key) {
            if (! in_array($key, $targets, true)) {
                array_unshift($edges, ['trigger', $key]);
            }
        }

        $characterKeys = [];
        foreach ($nodes as $node) {
            foreach ($node['data']['character_ids'] ?? [] as $value) {
                if (is_string($value) && preg_match('/trigger\.output\.([a-z0-9_]+)/', $value, $m)) {
                    $characterKeys[] = $m[1];
                }
            }
        }
        $fields = array_map(fn ($f) => in_array($f['key'], $characterKeys, true)
            ? ['type' => 'character', 'options' => [], 'multiple' => $f['multiple'] ?? false] + $f
            : $f, array_slice($fields, 0, FormFields::MAX_FIELDS));
        // {{trigger.output.<character key>_name}} is filled in automatically; it is not a question.
        $fields = array_values(array_filter($fields, fn ($f) => ! in_array(preg_replace('/_name$/', '', $f['key']), $characterKeys, true) || ! str_ends_with($f['key'], '_name')));
        $input = [];
        foreach ($fields as $field) {
            $input[$field['key']] = match ($field['type']) {
                'image' => '',
                'character' => (string) (array_key_first($context['characters']) ?? ''),
                default => $examples[$field['key']] ?? ($field['options'][0] ?? ''),
            };
        }

        $positions = $this->layout(array_keys($nodes), $edges);
        $graphNodes = [[
            'id' => 'trigger',
            'type' => 'trigger.manual',
            'position' => $positions['trigger'],
            'data' => ['label' => 'Form', 'input' => $input, 'form' => ['fields' => $fields]],
        ]];
        foreach ($nodes as $key => $node) {
            $graphNodes[] = ['id' => $key, 'type' => $node['type'], 'position' => $positions[$key], 'data' => $node['data']];
        }

        $name = mb_substr(trim((string) ($spec['name'] ?? '')), 0, 150) ?: 'AI-generated flow';

        return [
            'name' => $name,
            'graph' => [
                'nodes' => $graphNodes,
                'edges' => array_map(fn ($e) => ['id' => "{$e[0]}-{$e[1]}", 'source' => $e[0], 'target' => $e[1]], $edges),
            ],
            'form' => [
                'title' => mb_substr(trim((string) ($spec['form']['title'] ?? '')), 0, 120) ?: $name,
                'description' => mb_substr(trim((string) ($spec['form']['description'] ?? '')), 0, 500),
                'submit_label' => mb_substr(trim((string) ($spec['form']['submit_label'] ?? '')), 0, 40) ?: 'Generate',
            ],
        ];
    }

    private function nodeData(string $type, array $data, array $context): array
    {
        $str = fn (string $field, int $max = 6000) => mb_substr(trim((string) ($data[$field] ?? '')), 0, $max);

        return match ($type) {
            'ai.text' => array_filter(['prompt' => $str('prompt') ?: 'Write a helpful answer.', 'system' => $str('system', 3000)]),
            'ai.research' => array_filter([
                'prompt' => $str('prompt') ?: 'Research the topic and cite sources.',
                'mode' => ($data['mode'] ?? 'quick') === 'deep' ? 'deep' : 'quick',
                'instructions' => $str('instructions', 3000),
                'mcp_server_ids' => array_values(array_intersect(array_map('intval', (array) ($data['mcp_server_ids'] ?? [])), array_keys($context['connectors']))),
            ]),
            default => array_filter([
                'prompt_mode' => 'ai',
                'brief' => $str('brief', 3000) ?: $str('prompt', 3000) ?: 'An eye-catching visual for the request.',
                'aspect_ratio' => $type === 'ai.video'
                    ? (in_array($data['aspect_ratio'] ?? null, ['9:16', '16:9'], true) ? $data['aspect_ratio'] : '9:16')
                    : (in_array($data['aspect_ratio'] ?? null, ['1:1', '4:5', '9:16', '16:9'], true) ? $data['aspect_ratio'] : null),
                'duration' => $type === 'ai.video' && in_array((int) ($data['duration'] ?? 0), VideoNode::DURATIONS, true) ? (int) $data['duration'] : null,
                'character_ids' => $this->characterIds((array) ($data['character_ids'] ?? []), $context),
                'references' => $this->references((array) ($data['references'] ?? [])),
            ], fn ($v) => $v !== null && $v !== [] && $v !== ''),
        };
    }

    /** Saved characters by id, plus "{{trigger.output.x}}" for a character the visitor picks on the form. */
    private function characterIds(array $values, array $context): array
    {
        $ids = [];
        foreach (array_slice($values, 0, CharacterConsistency::MAX_CHARACTERS) as $value) {
            if (is_string($value) && preg_match('/^\{\{\s*trigger\.output\.([A-Za-z0-9_\-]+)\s*\}\}$/', trim($value), $m)) {
                $ids[] = '{{trigger.output.'.FormFields::key($m[1]).'}}';
            } elseif (is_numeric($value) && isset($context['characters'][(int) $value])) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids, SORT_REGULAR));
    }

    /** Only references to other steps' or the form's files; the AI can't know the user's library. */
    private function references(array $refs): array
    {
        $clean = [];
        foreach (array_slice($refs, 0, ReferenceImages::MAX) as $ref) {
            $src = trim((string) (is_array($ref) ? ($ref['src'] ?? '') : $ref));
            if (! preg_match('/^\{\{\s*[A-Za-z0-9_\-]+\.output\.[A-Za-z0-9_\-]+\s*\}\}$/', $src)) {
                continue;
            }
            $role = is_array($ref) ? (string) ($ref['role'] ?? 'auto') : 'auto';
            $clean[] = array_filter([
                'src' => $src,
                'role' => array_key_exists($role, ReferenceRoles::ROLES) ? $role : 'auto',
                'note' => is_array($ref) ? mb_substr(trim((string) ($ref['note'] ?? '')), 0, 500) : '',
            ]);
        }

        return $clean;
    }

    /** Link (or remove) one step's {{x.output.y}} references; form references get a field if missing. */
    private function resolveReferences(string $text, string $key, bool $earlierOnly, array $order, array &$edges, array $nodes, array &$fields): string
    {
        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_\-]+)\.output\.([A-Za-z0-9_\-\.]+)\s*\}\}/', function ($m) use ($key, $earlierOnly, $order, &$edges, $nodes, &$fields) {
            [$whole, $source, $path] = $m;
            if ($source === 'trigger') {
                $field = FormFields::key($path);
                if ($field !== '' && ! in_array($field, array_column($fields, 'key'), true)) {
                    $fields[] = $this->fallbackField($field);
                }

                return '{{trigger.output.'.$field.'}}';
            }
            if ($earlierOnly && isset($order[$source]) && $order[$source] > $order[$key]) {
                return $whole; // decided in the second pass
            }

            // Unknown steps, the step itself, or a link that would loop can't be filled in.
            return isset($nodes[$source]) && $this->link($edges, $source, $key, $nodes) ? $whole : '';
        }, $text);
    }

    /** Add $from -> $to unless it is invalid or would create a loop. Returns whether the link exists now. */
    private function link(array &$edges, string $from, string $to, array $nodes): bool
    {
        if (! isset($nodes[$to]) || ($from !== 'trigger' && ! isset($nodes[$from])) || $from === $to) {
            return false;
        }
        if (in_array([$from, $to], $edges, true)) {
            return true;
        }
        if ($from !== 'trigger' && $this->reaches($edges, $to, $from)) {
            return false;
        }
        $edges[] = [$from, $to];

        return true;
    }

    private function reaches(array $edges, string $start, string $goal): bool
    {
        $stack = [$start];
        $seen = [];
        while ($stack) {
            $current = array_pop($stack);
            if ($current === $goal) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach ($edges as [$a, $b]) {
                if ($a === $current) {
                    $stack[] = $b;
                }
            }
        }

        return false;
    }

    /** Columns by longest path from the form, rows in order within each column. */
    private function layout(array $keys, array $edges): array
    {
        $depth = ['trigger' => 0] + array_fill_keys($keys, 1);
        for ($i = 0; $i < count($keys); $i++) {
            foreach ($edges as [$a, $b]) {
                $depth[$b] = max($depth[$b], $depth[$a] + 1);
            }
        }

        $rows = [];
        $positions = [];
        foreach (['trigger', ...$keys] as $key) {
            $column = $depth[$key];
            $row = $rows[$column] = ($rows[$column] ?? -1) + 1;
            $positions[$key] = ['x' => $column * 300, 'y' => $row * 140];
        }

        return $positions;
    }

    private function uniqueKey(string $wanted, array $taken): string
    {
        $base = FormFields::key($wanted) ?: 'step';
        $base = $base === 'trigger' ? 'step' : $base;
        $key = $base;
        for ($i = 2; in_array($key, $taken, true); $i++) {
            $key = $base.$i;
        }

        return $key;
    }

    private function fallbackField(string $key): array
    {
        return ['key' => $key, 'label' => Str::headline($key), 'type' => 'text', 'required' => true, 'placeholder' => '', 'help' => '', 'options' => []];
    }

    /** @return list<string> form keys whose answer picks the character(s) of an Image/Video step */
    private function characterKeysUsed(array $nodes): array
    {
        $keys = [];
        foreach ($nodes as $node) {
            foreach ((array) ($node['data']['character_ids'] ?? []) as $value) {
                if (is_string($value) && preg_match('/trigger\.output\.([A-Za-z0-9_\-]+)/', $value, $m)) {
                    $keys[] = $m[1];
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return list<string> form keys read as {{trigger.output.x}}, in order of first use */
    private function triggerKeysUsed(array $nodes): array
    {
        $keys = [];
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'trigger.manual') {
                continue;
            }
            preg_match_all('/\{\{\s*trigger\.output\.([A-Za-z0-9_\-]+)/', json_encode($node['data'] ?? []), $m);
            array_push($keys, ...$m[1]);
        }

        return array_values(array_unique($keys));
    }

    private function mapStrings(array $data, callable $fn): array
    {
        return array_map(fn ($v) => is_string($v) ? $fn($v) : (is_array($v) ? $this->mapStrings($v, $fn) : $v), $data);
    }

    /** @return array{characters: array<int, string>, connectors: array<int, string>} */
    private function workspaceContext(Workspace $workspace): array
    {
        return [
            'characters' => $workspace->characters()->pluck('name', 'id')->all(),
            'connectors' => $workspace->mcpServers()->where('status', 'valid')->pluck('name', 'id')->all(),
        ];
    }

    private function askJson(Workspace $workspace, string $system, string $request): array
    {
        try {
            $candidates = $this->router->candidates($workspace, 'text', []);
        } catch (RuntimeException) {
            throw new InvalidArgumentException('Set a default text model on the AI keys & models page first; the flow generator uses it.');
        }

        $error = null;
        foreach ($candidates as ['credential' => $credential, 'model' => $model]) {
            if (! $credential) {
                continue;
            }
            try {
                $text = $this->providers->for($credential->provider)->generateText($credential->secret(), $model, $request, $system)->text;
                $json = self::decode($text);
                if ($json !== null) {
                    return $json;
                }
                $error = new RuntimeException('the model did not return valid JSON');
            } catch (Throwable $e) {
                $error = $e;
            }
        }

        throw new RuntimeException('The AI flow generator failed: '.($error?->getMessage() ?? 'no usable text model.'));
    }

    /** The first JSON object in a reply, ignoring code fences and chatter around it. */
    public static function decode(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $json = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($json) ? $json : null;
    }

    private function system(array $context): string
    {
        $characters = $context['characters']
            ? implode("\n", array_map(fn ($name, $id) => "- id {$id}: {$name}", $context['characters'], array_keys($context['characters'])))
            : '(none)';
        $connectors = $context['connectors']
            ? implode("\n", array_map(fn ($name, $id) => "- id {$id}: {$name}", $context['connectors'], array_keys($context['connectors'])))
            : '(none)';

        return <<<PROMPT
        You design automation flows for an AI platform. A visitor fills in a public form; the flow's steps run in order and the visitor sees the results.

        Reply with ONE JSON object and nothing else:
        {
          "name": "short flow name",
          "form": {
            "title": "form heading shown to visitors",
            "description": "one or two sentences telling visitors what they get",
            "submit_label": "button text, e.g. Create my post",
            "fields": [
              {"key": "snake_case", "label": "Question shown to the visitor", "type": "text|textarea|select|number|email|url|image|character",
               "required": true, "placeholder": "an example answer", "help": "optional hint", "options": ["only for select"],
               "multiple": false,
               "example": "a realistic test value"}
            ]
          },
          "nodes": [
            {"key": "snake_case_key", "type": "ai.text|ai.research|ai.image|ai.video", "label": "Short step name",
             "show_in_result": true, "data": { ... }}
          ],
          "edges": [{"from": "trigger", "to": "first_key"}, {"from": "first_key", "to": "second_key"}]
        }

        STEP TYPES and their "data":
        - ai.research: web research with citations. {"prompt": "what to research", "mode": "quick" (or "deep" only when the user asks for in-depth research; it takes several minutes), "mcp_server_ids": [ids of connected apps to use]}. Output: {{key.output.text}}.
        - ai.text: writing, rewriting, summarising, captions, scripts, emails, JSON. {"prompt": "the full instruction", "system": "optional role/tone"}. Output: {{key.output.text}}.
        - ai.image: one image. {"brief": "what the image should show; an AI expands it into a detailed prompt", "aspect_ratio": "1:1|4:5|9:16|16:9", "character_ids": [saved character ids and/or "{{trigger.output.<character field>}}"], "references": [{"src": "{{trigger.output.photo}}", "role": "product|person|logo|style|color_theme|match|auto", "note": "how and where to use it"}]}. Output: {{key.output.url}}.
        - ai.video: one short clip. {"brief": "what happens in the video, start to finish", "aspect_ratio": "9:16|16:9", "duration": 30|60|90|120 (only for videos longer than 10 seconds: the step then writes a multi-scene story and joins the clips; leave it out for one short clip), "character_ids": [same as image], "references": [same as image]}. Output: {{key.output.url}}.

        RULES
        - The form is the step called "trigger"; never add it to "nodes". Read form answers as {{trigger.output.<field key>}}. Every field you ask for must be used by a step, and every {{trigger.output.x}} must be a field.
        - Pass results forward with {{step_key.output.text}} or {{step_key.output.url}} and add an edge from that step. No loops.
        - Ask only for what you cannot sensibly decide yourself; 1 to 6 fields. Use "image" fields for uploads such as a product photo or logo, then use them as references with the right role.
        - Use 1 to 6 steps. Prefer quick research, then text, then media. Write complete, specific prompts; no [PLACEHOLDERS].
        - Set "show_in_result": false for helper steps whose output the visitor doesn't need (e.g. an image brief), true for the deliverables.
        - When the visitor should choose who appears, add a "character" field (they pick from the saved characters below; "multiple": true lets them pick up to 3) and put "{{trigger.output.<that key>}}" in the image/video step's "character_ids". Never ask visitors to upload a photo of a character. In text steps the chosen name is {{trigger.output.<that key>_name}}.
        - Only use these saved characters (by id) if the request is about them:
        {$characters}
        - Only use these connected apps (by id) in research steps if the request needs them:
        {$connectors}
        PROMPT;
    }

    private function formSystem(): string
    {
        return <<<'PROMPT'
        You design the public form for an existing automation flow. Reply with ONE JSON object and nothing else:
        {"title": "form heading", "description": "one or two sentences on what the visitor gets",
         "fields": [{"key": "given key", "label": "question shown to visitors", "type": "text|textarea|select|number|email|url|image|character",
                     "required": true, "placeholder": "an example answer", "help": "optional hint", "options": ["only for select"]}]}
        Use exactly the keys you are given. Choose the type from how the step uses the value: a photo or logo used as a reference is "image"; long free text is "textarea"; a small fixed set of choices is "select"; a value used in a step's character_ids is "character" (the visitor picks one of the saved characters).
        PROMPT;
    }
}
