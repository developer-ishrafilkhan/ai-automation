<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function workspace(Request $request): Workspace
    {
        return $request->user()->currentWorkspace;
    }

    /** 404 for records outside the current workspace, 403 for viewers trying to change things. */
    protected function guard(Request $request, ?int $workspaceId = null, bool $write = false): Workspace
    {
        $workspace = $this->workspace($request);

        abort_if($workspaceId !== null && $workspaceId !== $workspace->id, 404);
        abort_if($write && $request->user()->roleIn($workspace) === 'viewer', 403, 'Viewers cannot change this workspace.');

        return $workspace;
    }
}
