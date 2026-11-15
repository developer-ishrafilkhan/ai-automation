<?php

namespace App\Http\Controllers;

use App\Models\Execution;
use App\Services\Engine\WorkflowRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExecutionController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request);

        return Inertia::render('executions/index', [
            'executions' => Execution::whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
                ->with('workflow:id,name', 'version:id,version')
                ->latest()->paginate(25),
        ]);
    }

    public function show(Request $request, Execution $execution): Response|JsonResponse
    {
        $this->guard($request, $execution->workflow->workspace_id);
        $execution->load('steps', 'workflow:id,name', 'version', 'assets');

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($execution);
        }

        return Inertia::render('executions/show', ['execution' => $execution]);
    }

    public function retry(Request $request, Execution $execution, WorkflowRunner $runner): JsonResponse
    {
        $this->guard($request, $execution->workflow->workspace_id, write: true);
        abort_unless($execution->status === 'failed', 422, 'Only failed runs can be retried.');

        $runner->retryFailed($execution);

        return response()->json(['execution_id' => $execution->id]);
    }
}
