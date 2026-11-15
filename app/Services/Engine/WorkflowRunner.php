<?php

namespace App\Services\Engine;

use App\Jobs\RunStep;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;

/** Creates runs and moves them along the graph. Workers do the actual node work in RunStep. */
class WorkflowRunner
{
    public function start(WorkflowVersion $version, array $triggerData = [], array $attributes = []): Execution
    {
        return DB::transaction(function () use ($version, $triggerData, $attributes) {
            $execution = Execution::create($attributes + [
                'workflow_id' => $version->workflow_id,
                'version_id' => $version->id,
                'status' => 'running',
                'trigger_data' => $triggerData,
                'started_at' => now(),
            ]);

            foreach ((new Graph($version->graph))->startNodes() as $node) {
                $this->queueStep($execution, $node);
            }

            return $execution;
        });
    }

    /** After a step succeeds, queue every next node whose inputs have all finished (Merge semantics). */
    public function advance(ExecutionStep $step): void
    {
        DB::transaction(function () use ($step) {
            $execution = Execution::whereKey($step->execution_id)->lockForUpdate()->first();
            $graph = new Graph($execution->version->graph);
            $succeeded = $execution->steps()->where('status', 'succeeded')->pluck('node_id')->all();

            foreach ($graph->next($step->node_id) as $nextId) {
                if (array_diff($graph->previous($nextId), $succeeded) === []) {
                    $this->queueStep($execution, $graph->node($nextId));
                }
            }

            $this->finishIfDone($execution);
        });
    }

    public function fail(ExecutionStep $step, string $error): void
    {
        $step->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);
        $step->execution->update(['status' => 'failed', 'error' => "Step \"{$step->node_id}\" failed: {$error}", 'finished_at' => now()]);
    }

    /** Re-run a failed run from its failed steps, keeping every successful output. */
    public function retryFailed(Execution $execution): void
    {
        DB::transaction(function () use ($execution) {
            $execution->update(['status' => 'running', 'error' => null, 'finished_at' => null]);

            foreach ($execution->steps()->where('status', 'failed')->get() as $step) {
                $step->update(['status' => 'queued', 'error' => null, 'state' => null, 'attempts' => 0]);
                RunStep::dispatch($step->id)->afterCommit();
            }
        });
    }

    private function queueStep(Execution $execution, array $node): void
    {
        $step = ExecutionStep::firstOrCreate(
            ['execution_id' => $execution->id, 'node_id' => $node['id']],
            ['node_type' => $node['type'], 'status' => 'queued'],
        );

        if ($step->wasRecentlyCreated) {
            RunStep::dispatch($step->id)->afterCommit();
        }
    }

    private function finishIfDone(Execution $execution): void
    {
        $open = $execution->steps()->whereIn('status', ['queued', 'running', 'waiting'])->exists();

        if (! $open && $execution->status === 'running') {
            $execution->update(['status' => 'succeeded', 'finished_at' => now()]);
        }
    }
}
