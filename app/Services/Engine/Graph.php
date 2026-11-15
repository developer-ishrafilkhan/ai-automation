<?php

namespace App\Services\Engine;

use RuntimeException;

class Graph
{
    public function __construct(private readonly array $graph) {}

    public function node(string $id): array
    {
        foreach ($this->graph['nodes'] ?? [] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }

        throw new RuntimeException("Node [{$id}] is not in this flow version.");
    }

    /** Name used in {{...}} references: the node's key if set, else its id. */
    public function keyOf(string $id): string
    {
        return $this->node($id)['data']['key'] ?? $id;
    }

    /** @return list<array> */
    public function startNodes(): array
    {
        $targets = array_column($this->graph['edges'] ?? [], 'target');

        return array_values(array_filter($this->graph['nodes'] ?? [], fn ($node) => ! in_array($node['id'], $targets, true)));
    }

    /** @return list<string> */
    public function next(string $id): array
    {
        return array_values(array_unique(array_column(array_filter($this->graph['edges'] ?? [], fn ($edge) => $edge['source'] === $id), 'target')));
    }

    /** @return list<string> */
    public function previous(string $id): array
    {
        return array_values(array_unique(array_column(array_filter($this->graph['edges'] ?? [], fn ($edge) => $edge['target'] === $id), 'source')));
    }
}
