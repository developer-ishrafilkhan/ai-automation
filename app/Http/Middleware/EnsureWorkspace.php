<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every signed-in user works inside a workspace; create a personal one on first visit. */
class EnsureWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->currentWorkspace) {
            $existing = $user->workspaces()->first();
            $existing
                ? $user->forceFill(['current_workspace_id' => $existing->id])->save()
                : Workspace::createFor($user, "{$user->name}'s workspace");
            $user->refresh();
        }

        return $next($request);
    }
}
