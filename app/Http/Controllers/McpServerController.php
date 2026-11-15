<?php

namespace App\Http\Controllers;

use App\Models\McpServer;
use App\Services\Ai\Redactor;
use App\Services\Mcp\McpClient;
use App\Services\Mcp\McpOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

class McpServerController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('connectors/index', [
            'servers' => $this->workspace($request)->mcpServers()->latest()->get(),
            'catalog' => collect(config('mcp_catalog.apps'))->map(fn (array $app) => [
                'id' => $app['id'],
                'name' => $app['name'],
                'category' => $app['category'],
                'color' => $app['color'],
                'description' => $app['description'],
                'note' => $app['note'] ?? null,
                'ready' => $this->clientReady($app),
            ])->values(),
            'callbackUrl' => route('connectors.oauth.callback'),
        ]);
    }

    public function store(Request $request, McpClient $client): RedirectResponse
    {
        $workspace = $this->guard($request, write: true);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'url' => ['required', 'url:https', 'max:500'],
            'token' => ['nullable', 'string', 'max:2000'],
        ]);

        $server = new McpServer(['name' => $validated['name'], 'url' => $validated['url']]);
        $server->workspace()->associate($workspace);
        $server->setToken($validated['token'] ?? null);
        $this->check($server, $client);

        return back()->with('success', $server->status === 'valid' ? 'Connector added.' : 'Connector saved, but we could not reach it.');
    }

    /** One click: start OAuth sign-in for a catalog app. */
    public function connectApp(Request $request, string $app, McpOAuth $oauth): HttpResponse
    {
        $this->guard($request, write: true);
        $entry = collect(config('mcp_catalog.apps'))->firstWhere('id', $app) ?? abort(404);

        return $this->beginSignIn($request, $oauth, $entry, ['catalog_id' => $entry['id'], 'name' => $entry['name'], 'url' => $entry['url']]);
    }

    /** Sign in to any MCP server URL that supports OAuth. */
    public function connectCustom(Request $request, McpOAuth $oauth): HttpResponse
    {
        $this->guard($request, write: true);
        $validated = $request->validate(['name' => ['required', 'string', 'max:60'], 'url' => ['required', 'url:https', 'max:500']]);

        return $this->beginSignIn($request, $oauth, ['name' => $validated['name'], 'url' => $validated['url']], ['catalog_id' => null] + $validated);
    }

    /** Sign in again to an existing connector, keeping its id so flows using it keep working. */
    public function reconnect(Request $request, McpServer $server, McpOAuth $oauth): HttpResponse
    {
        $this->guard($request, $server->workspace_id, write: true);
        $entry = collect(config('mcp_catalog.apps'))->firstWhere('id', $server->catalog_id) ?? ['name' => $server->name, 'url' => $server->url];

        return $this->beginSignIn($request, $oauth, $entry, ['server_id' => $server->id, 'catalog_id' => $server->catalog_id, 'name' => $server->name, 'url' => $server->url]);
    }

    public function callback(Request $request, McpOAuth $oauth, McpClient $client): RedirectResponse
    {
        $pending = $request->session()->pull('mcp_oauth');
        $workspace = $this->workspace($request);

        if (! $pending || ! hash_equals($pending['state'], (string) $request->query('state')) || $pending['workspace_id'] !== $workspace->id) {
            return to_route('connectors.index')->with('error', 'Sign-in expired or was started elsewhere. Please try again.');
        }
        if ($request->query('error')) {
            return to_route('connectors.index')->with('error', 'Sign-in was cancelled: '.mb_substr((string) ($request->query('error_description') ?? $request->query('error')), 0, 200));
        }

        try {
            $tokens = $oauth->complete($pending, (string) $request->query('code'));
        } catch (Throwable $e) {
            return to_route('connectors.index')->with('error', Redactor::clean($e->getMessage()));
        }

        $server = isset($pending['server']['server_id'])
            ? $workspace->mcpServers()->findOrFail($pending['server']['server_id'])
            : new McpServer(['catalog_id' => $pending['server']['catalog_id'], 'name' => $this->uniqueName($workspace, $pending['server']['name']), 'url' => $pending['server']['url']]);
        $server->workspace()->associate($workspace);
        $server->encrypted_token = null;
        $server->setOAuthData($tokens);
        $this->check($server, $client);

        return to_route('connectors.index')->with(
            $server->status === 'valid' ? 'success' : 'error',
            $server->status === 'valid' ? "{$server->name} connected." : "Signed in to {$server->name}, but listing its tools failed: {$server->status_message}",
        );
    }

    private function beginSignIn(Request $request, McpOAuth $oauth, array $entry, array $server): HttpResponse
    {
        try {
            $start = $oauth->begin($entry, route('connectors.oauth.callback'));
        } catch (Throwable $e) {
            return back()->with('error', Redactor::clean($e->getMessage()));
        }

        $request->session()->put('mcp_oauth', $start['pending'] + [
            'workspace_id' => $this->workspace($request)->id,
            'server' => $server,
        ]);

        // Full-page redirect to the provider's sign-in screen (works for Inertia and plain requests).
        return Inertia::location($start['url']);
    }

    private function clientReady(array $app): bool
    {
        $key = $app['oauth']['client'] ?? null;
        $client = $key ? config("mcp_catalog.clients.{$key}") : null;

        return ! $client || ! empty($client['client_id']) || ! empty($client['optional']);
    }

    private function uniqueName($workspace, string $name): string
    {
        $taken = $workspace->mcpServers()->where('name', 'like', "{$name}%")->pluck('name')->all();
        for ($i = 1; in_array($candidate = $i === 1 ? $name : "{$name} {$i}", $taken, true); $i++);

        return $candidate;
    }

    public function test(Request $request, McpServer $server, McpClient $client): RedirectResponse
    {
        $this->guard($request, $server->workspace_id, write: true);
        $this->check($server, $client);

        return back();
    }

    public function destroy(Request $request, McpServer $server): RedirectResponse
    {
        $this->guard($request, $server->workspace_id, write: true);
        $server->delete();

        return back();
    }

    /** Connect, list the tools, and cache them so the user sees what the AI will be able to do. */
    private function check(McpServer $server, McpClient $client): void
    {
        try {
            $server->tools_cache = $client->listTools($server->url, $server->token());
            $server->status = 'valid';
            $server->status_message = null;
        } catch (Throwable $e) {
            $server->status = 'invalid';
            $server->status_message = mb_substr(Redactor::clean($e->getMessage(), [$server->token()]), 0, 250);
        }

        $server->checked_at = now();
        $server->save();
    }
}
