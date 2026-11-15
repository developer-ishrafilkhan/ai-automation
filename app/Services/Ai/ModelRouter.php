<?php

namespace App\Services\Ai;

use App\Models\AiCredential;
use App\Models\Workspace;
use RuntimeException;

/**
 * Picks credential + model for a node: the node's own override first,
 * else the workspace default for the task, then the task's fallback.
 */
class ModelRouter
{
    /** @return list<array{credential: AiCredential, model: string}> */
    public function candidates(Workspace $workspace, string $taskType, array $nodeData): array
    {
        $candidates = [];
        $default = $workspace->modelDefaults()->where('task_type', $taskType)->first();

        if (! empty($nodeData['credential_id']) && ! empty($nodeData['model'])) {
            $candidates[] = [$nodeData['credential_id'], $nodeData['model']];
        } elseif ($default?->credential_id && $default->model) {
            $candidates[] = [$default->credential_id, $default->model];
        }

        if ($default?->fallback_credential_id && $default->fallback_model) {
            $candidates[] = [$default->fallback_credential_id, $default->fallback_model];
        }

        $resolved = [];
        foreach ($candidates as [$credentialId, $model]) {
            $credential = $workspace->credentials()->find($credentialId);
            if ($credential) {
                $resolved[] = ['credential' => $credential, 'model' => $model];
            }
        }

        if (! $resolved) {
            throw new RuntimeException("No model is set for this node. Pick one on the node, or set a default for \"{$taskType}\" in AI keys & models.");
        }

        return $resolved;
    }
}
