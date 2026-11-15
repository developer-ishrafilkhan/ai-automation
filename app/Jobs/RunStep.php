<?php

namespace App\Jobs;

use App\Models\ExecutionStep;
use App\Services\Ai\Redactor;
use App\Services\Engine\Graph;
use App\Services\Engine\NodeRegistry;
use App\Services\Engine\Template;
use App\Services\Engine\WorkflowRunner;
use App\Support\FormFields;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

/** One queued job per step. Retries, waiting and failure are tracked on the step row, not the queue. */
class RunStep implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 3;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $stepId) {}

    /** Finished videos arrive as base64 inside JSON (10+ MB each); PHP's default 128 MB is too small. */
    public const MEMORY_LIMIT = '1024M';

    public function handle(NodeRegistry $nodes, WorkflowRunner $runner): void
    {
        self::raiseMemoryLimit();
        $step = ExecutionStep::with('execution.version', 'execution.workflow.workspace')->find($this->stepId);
        if (! $step || ! in_array($step->status, ['queued', 'waiting'], true) || $step->execution->status === 'failed') {
            return;
        }

        // Also fixes flows published before a reference could point at a Saved character answer.
        $graph = new Graph(FormFields::characterReferencesToCast($step->execution->version->graph));
        $node = $graph->node($step->node_id);
        $resuming = $step->status === 'waiting';

        $data = $this->render($node['data'] ?? [], $this->context($step, $graph));
        $step->update([
            'status' => 'running',
            'input' => $resuming ? $step->input : $data,
            'started_at' => $step->started_at ?? now(),
            'attempts' => $resuming ? $step->attempts : $step->attempts + 1,
        ]);

        try {
            $result = $nodes->handler($node['type'])->handle($step, $data, $step->state);
        } catch (Throwable $e) {
            $error = Redactor::clean($e->getMessage());
            if ($resuming) {
                $step->increment('attempts'); // failed polls count too, so a broken job can't poll forever
            }

            // Setup mistakes (missing brief, deleted character, blocked reference URL) can't be fixed by retrying.
            $retryable = ! $e instanceof InvalidArgumentException;

            if ($retryable && $step->attempts < self::MAX_ATTEMPTS) {
                $step->update(['status' => $resuming ? 'waiting' : 'queued', 'error' => $error]);
                self::dispatch($step->id)->delay(now()->addSeconds(10 * 2 ** ($step->attempts - 1)));
            } else {
                $runner->fail($step, $error);
            }

            return;
        }

        if ($result->waiting) {
            $step->update(['status' => 'waiting', 'state' => $result->state, 'error' => null]);
            self::dispatch($step->id)->delay(now()->addSeconds($result->resumeAfterSeconds));

            return;
        }

        $step->update([
            'status' => 'succeeded',
            'output' => $result->output,
            'tokens' => $result->tokens,
            'state' => null,
            'error' => null,
            'finished_at' => now(),
        ]);
        $runner->advance($step);
    }

    /** Outputs of finished steps, addressable as {{nodeKey.output.field}}. */
    private function context(ExecutionStep $step, Graph $graph): array
    {
        $context = [];
        foreach ($step->execution->steps()->where('status', 'succeeded')->get() as $done) {
            $context[$graph->keyOf($done->node_id)] = ['output' => $done->output];
        }

        return $context;
    }

    private function render(array $data, array $context): array
    {
        return array_map(fn ($value) => match (true) {
            is_string($value) => Template::render($value, $context),
            is_array($value) => $this->render($value, $context),
            default => $value,
        }, $data);
    }

    private static function raiseMemoryLimit(): void
    {
        $current = ini_get('memory_limit');
        if ($current !== '-1' && self::bytes($current) < self::bytes(self::MEMORY_LIMIT)) {
            ini_set('memory_limit', self::MEMORY_LIMIT);
        }
    }

    private static function bytes(string $value): int
    {
        $number = (int) $value;

        return match (strtoupper(substr(trim($value), -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
