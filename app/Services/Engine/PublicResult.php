<?php

namespace App\Services\Engine;

use App\Models\Execution;

/**
 * What a form visitor may see of a run: each visible step's deliverable (text, sources, image or
 * video) in flow order. Prompts, models, token counts and raw error messages stay private.
 */
class PublicResult
{
    public static function of(Execution $execution): array
    {
        $graph = $execution->version->graph;
        $steps = $execution->steps()->get()->keyBy('node_id');
        $items = [];

        foreach (self::ordered($graph) as $node) {
            if ($node['type'] === 'trigger.manual' || ! empty($node['data']['hide_in_form'])) {
                continue;
            }
            $step = $steps->get($node['id']);
            $output = $step?->status === 'succeeded' ? ($step->output ?? []) : [];

            $items[] = [
                'id' => $node['id'],
                'label' => $node['data']['label'] ?? $node['id'],
                'kind' => match ($node['type']) {
                    'ai.image' => 'image',
                    'ai.video' => 'video',
                    'ai.research' => 'research',
                    default => 'text',
                },
                'status' => $step?->status ?? 'pending',
                'progress' => $step?->progress,
                'text' => $output['text'] ?? null,
                'url' => $output['url'] ?? null,
                'citations' => array_values(array_map(
                    fn ($c) => ['title' => (string) ($c['title'] ?? $c['url'] ?? ''), 'url' => (string) ($c['url'] ?? '')],
                    array_filter((array) ($output['citations'] ?? []), fn ($c) => is_array($c) && str_starts_with((string) ($c['url'] ?? ''), 'http')),
                )),
            ];
        }

        return [
            'status' => $execution->status,
            'failed' => $execution->status === 'failed',
            'items' => $items,
        ];
    }

    /** Nodes in run order (a topological sort); ties keep their canvas order. */
    public static function ordered(array $graph): array
    {
        $nodes = $graph['nodes'] ?? [];
        $edges = $graph['edges'] ?? [];
        $indegree = array_fill_keys(array_column($nodes, 'id'), 0);
        foreach ($edges as $edge) {
            if (isset($indegree[$edge['target']])) {
                $indegree[$edge['target']]++;
            }
        }

        $byId = array_column($nodes, null, 'id');
        $ready = array_keys(array_filter($indegree, fn ($n) => $n === 0));
        $ordered = [];
        while ($ready) {
            $id = array_shift($ready);
            $ordered[] = $byId[$id];
            foreach ($edges as $edge) {
                if ($edge['source'] === $id && isset($indegree[$edge['target']]) && --$indegree[$edge['target']] === 0) {
                    $ready[] = $edge['target'];
                }
            }
        }

        return $ordered;
    }
}
