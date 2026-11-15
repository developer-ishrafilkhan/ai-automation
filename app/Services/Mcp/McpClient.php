<?php

namespace App\Services\Mcp;

use App\Support\UrlGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal MCP client over Streamable HTTP: enough to verify a server and list its tools.
 * The AI providers (Claude, OpenAI) call the tools themselves during research.
 */
class McpClient
{
    private const PROTOCOL = '2025-06-18';

    public function __construct(private readonly UrlGuard $guard) {}

    /** @return list<array{name: string, description: string}> */
    public function listTools(string $url, ?string $token): array
    {
        $this->guard->assertPublicHttps($url);

        $init = $this->call($url, $token, null, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => self::PROTOCOL,
            'capabilities' => (object) [],
            'clientInfo' => ['name' => config('app.name'), 'version' => '1.0'],
        ]]);
        $session = $init->header('Mcp-Session-Id') ?: null;

        $this->call($url, $token, $session, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $list = $this->message($this->call($url, $token, $session, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']), 2);

        if (isset($list['error'])) {
            throw new RuntimeException('MCP server error: '.($list['error']['message'] ?? 'unknown'));
        }

        return array_map(fn (array $tool) => [
            'name' => (string) $tool['name'],
            'description' => mb_substr((string) ($tool['description'] ?? ''), 0, 300),
        ], $list['result']['tools'] ?? []);
    }

    private function call(string $url, ?string $token, ?string $session, array $payload): Response
    {
        $request = Http::timeout(20)->withHeaders(array_filter([
            'Accept' => 'application/json, text/event-stream',
            'Mcp-Session-Id' => $session,
            'MCP-Protocol-Version' => isset($payload['id']) && $payload['id'] === 1 ? null : self::PROTOCOL,
        ]));
        if ($token) {
            $request = $request->withToken($token);
        }

        $response = $request->post($url, $payload);
        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('The MCP server rejected the request. Check the access token.');
        }
        if ($response->failed()) {
            throw new RuntimeException("MCP server returned HTTP {$response->status()}.");
        }

        return $response;
    }

    /** Reads a JSON-RPC message from a JSON body or an SSE stream. */
    private function message(Response $response, int $id): array
    {
        if (str_contains((string) $response->header('Content-Type'), 'text/event-stream')) {
            foreach (preg_split('/\r?\n/', $response->body()) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data = json_decode(trim(substr($line, 5)), true);
                    if (($data['id'] ?? null) === $id) {
                        return $data;
                    }
                }
            }
            throw new RuntimeException('MCP server sent no reply to tools/list.');
        }

        return $response->json() ?? throw new RuntimeException('MCP server sent an unreadable reply.');
    }
}
