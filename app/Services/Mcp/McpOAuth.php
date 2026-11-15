<?php

namespace App\Services\Mcp;

use App\Models\McpServer;
use App\Support\UrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OAuth sign-in for remote MCP servers (MCP authorization spec: OAuth 2.1 + PKCE).
 *
 *  1. Discover the authorization server: protected-resource metadata, then
 *     authorization-server metadata. Catalog apps may pin endpoints instead.
 *  2. Get a client: a pre-registered one from config, or dynamic client registration.
 *  3. Send the user to the authorization endpoint; exchange the code on callback.
 *  4. Refresh the access token before it expires.
 */
class McpOAuth
{
    public function __construct(private readonly UrlGuard $guard) {}

    /**
     * Prepare the sign-in. Returns the URL to send the user to and the state to keep in the session.
     *
     * @param  array  $app  catalog entry, or ['url' => ...] for a custom server
     * @return array{url: string, pending: array}
     */
    public function begin(array $app, string $redirectUri): array
    {
        $resource = $app['url'];
        $this->guard->assertPublicHttps($resource);

        $pinned = $app['oauth'] ?? [];
        $endpoints = isset($pinned['authorization_endpoint'])
            ? ['authorization_endpoint' => $pinned['authorization_endpoint'], 'token_endpoint' => $pinned['token_endpoint'], 'registration_endpoint' => null, 'scopes' => $pinned['scopes'] ?? []]
            : $this->discover($resource);

        $client = $this->client($app, $endpoints, $redirectUri);
        $verifier = Str::random(64);
        $state = Str::random(40);
        $scopes = $pinned['scopes'] ?? $endpoints['scopes'];

        $query = array_filter([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'scope' => $scopes ? implode(' ', $scopes) : null,
            'resource' => $resource,
        ]) + ($pinned['extra_params'] ?? []);

        return [
            'url' => $endpoints['authorization_endpoint'].(str_contains($endpoints['authorization_endpoint'], '?') ? '&' : '?').http_build_query($query),
            'pending' => [
                'state' => $state,
                'verifier' => $verifier,
                'redirect_uri' => $redirectUri,
                'resource' => $resource,
                'token_endpoint' => $endpoints['token_endpoint'],
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'] ?? null,
            ],
        ];
    }

    /** Exchange the authorization code for tokens. */
    public function complete(array $pending, string $code): array
    {
        return $this->tokenRequest($pending, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $pending['redirect_uri'],
            'code_verifier' => $pending['verifier'],
        ]);
    }

    /** A valid access token for the server, refreshed if it is about to expire. */
    public function accessToken(McpServer $server): string
    {
        $oauth = $server->oauthData();
        if (! $oauth) {
            throw new RuntimeException("{$server->name} is not signed in. Reconnect it on the Connectors page.");
        }

        $expiresAt = $oauth['expires_at'] ?? null;
        if ($expiresAt === null || $expiresAt > now()->timestamp + 60) {
            return $oauth['access_token'];
        }

        if (empty($oauth['refresh_token'])) {
            $this->markExpired($server);
        }

        try {
            $fresh = $this->tokenRequest($oauth, ['grant_type' => 'refresh_token', 'refresh_token' => $oauth['refresh_token']]);
        } catch (RuntimeException) {
            $this->markExpired($server);
        }

        // Some providers don't rotate refresh tokens; keep the old one then.
        $fresh['refresh_token'] ??= $oauth['refresh_token'];
        $server->setOAuthData($fresh);
        $server->save();

        return $fresh['access_token'];
    }

    private function tokenRequest(array $context, array $params): array
    {
        $this->guard->assertPublicHttps($context['token_endpoint']);

        $response = Http::asForm()->acceptJson()->timeout(20)->post($context['token_endpoint'], $params + array_filter([
            'client_id' => $context['client_id'],
            'client_secret' => $context['client_secret'] ?? null,
            'resource' => $context['resource'],
        ]));

        $token = $response->json('access_token');
        if ($response->failed() || ! $token) {
            $reason = $response->json('error_description') ?? $response->json('error') ?? "HTTP {$response->status()}";
            throw new RuntimeException("Sign-in failed: {$reason}");
        }

        return [
            'access_token' => $token,
            'refresh_token' => $response->json('refresh_token'),
            'expires_at' => $response->json('expires_in') ? now()->timestamp + (int) $response->json('expires_in') : null,
            'token_endpoint' => $context['token_endpoint'],
            'client_id' => $context['client_id'],
            'client_secret' => $context['client_secret'] ?? null,
            'resource' => $context['resource'],
        ];
    }

    /** @return never */
    private function markExpired(McpServer $server): void
    {
        $server->forceFill(['status' => 'invalid', 'status_message' => 'Sign-in expired. Click Reconnect.'])->save();

        throw new RuntimeException("{$server->name} sign-in expired. Reconnect it on the Connectors page.");
    }

    /** @return array{authorization_endpoint: string, token_endpoint: string, registration_endpoint: ?string, scopes: list<string>} */
    private function discover(string $resource): array
    {
        $issuer = $this->authorizationServer($resource);

        foreach ($this->metadataUrls($issuer) as $url) {
            $meta = $this->getJson($url);
            if (! empty($meta['authorization_endpoint']) && ! empty($meta['token_endpoint'])) {
                if (! in_array('S256', $meta['code_challenge_methods_supported'] ?? ['S256'], true)) {
                    throw new RuntimeException('This server does not support secure (PKCE S256) sign-in.');
                }

                return [
                    'authorization_endpoint' => $meta['authorization_endpoint'],
                    'token_endpoint' => $meta['token_endpoint'],
                    'registration_endpoint' => $meta['registration_endpoint'] ?? null,
                    'scopes' => [],
                ];
            }
        }

        throw new RuntimeException('This server does not offer OAuth sign-in. Add it with an access token instead.');
    }

    /** Which authorization server protects the MCP endpoint (RFC 9728), falling back to the server's own origin. */
    private function authorizationServer(string $resource): string
    {
        $parts = parse_url($resource);
        $origin = "{$parts['scheme']}://{$parts['host']}".(isset($parts['port']) ? ":{$parts['port']}" : '');
        $path = rtrim($parts['path'] ?? '', '/');

        $candidates = [];
        try {
            // An unauthenticated call answers 401 with a pointer to the protected-resource metadata.
            $probe = Http::timeout(15)->withHeaders(['Accept' => 'application/json, text/event-stream'])
                ->post($resource, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => config('app.name'), 'version' => '1.0']]]);
            if (preg_match('/resource_metadata="([^"]+)"/', (string) $probe->header('WWW-Authenticate'), $m)) {
                $candidates[] = $m[1];
            }
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach the MCP server.');
        }
        $candidates[] = "{$origin}/.well-known/oauth-protected-resource{$path}";
        $candidates[] = "{$origin}/.well-known/oauth-protected-resource";

        foreach (array_unique($candidates) as $url) {
            $servers = $this->getJson($url)['authorization_servers'] ?? [];
            if ($servers) {
                return rtrim($servers[0], '/');
            }
        }

        return $origin;
    }

    /** RFC 8414 and OpenID discovery locations for an issuer, including issuers with a path. */
    private function metadataUrls(string $issuer): array
    {
        $parts = parse_url($issuer);
        $origin = "{$parts['scheme']}://{$parts['host']}".(isset($parts['port']) ? ":{$parts['port']}" : '');
        $path = rtrim($parts['path'] ?? '', '/');

        return array_unique([
            "{$origin}/.well-known/oauth-authorization-server{$path}",
            "{$origin}/.well-known/openid-configuration{$path}",
            "{$issuer}/.well-known/openid-configuration",
        ]);
    }

    /** @return array{client_id: string, client_secret?: ?string} */
    private function client(array $app, array $endpoints, string $redirectUri): array
    {
        $configKey = $app['oauth']['client'] ?? null;
        $configured = $configKey ? config("mcp_catalog.clients.{$configKey}") : null;

        if (! empty($configured['client_id'])) {
            return ['client_id' => $configured['client_id'], 'client_secret' => $configured['client_secret'] ?: null];
        }

        if (! empty($endpoints['registration_endpoint'])) {
            return $this->register($endpoints['registration_endpoint'], $redirectUri);
        }

        throw new RuntimeException($configured
            ? "{$app['name']} needs a one-time OAuth app setup by your admin: set {$configured['env']} in .env (redirect URI {$redirectUri})."
            : 'This server does not allow automatic sign-in setup. Add it with an access token instead.');
    }

    /** Dynamic client registration (RFC 7591) as a public client using PKCE. */
    private function register(string $endpoint, string $redirectUri): array
    {
        $this->guard->assertPublicHttps($endpoint);

        $response = Http::acceptJson()->timeout(20)->post($endpoint, [
            'client_name' => config('app.name'),
            'redirect_uris' => [$redirectUri],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]);

        if ($response->failed() || ! $response->json('client_id')) {
            throw new RuntimeException("The server refused client registration (HTTP {$response->status()}).");
        }

        return ['client_id' => $response->json('client_id'), 'client_secret' => $response->json('client_secret')];
    }

    private function getJson(string $url): array
    {
        try {
            $this->guard->assertPublicHttps($url);
            $response = Http::acceptJson()->timeout(15)->get($url);
        } catch (\Throwable $e) {
            if ($e instanceof \InvalidArgumentException) {
                throw new RuntimeException('Sign-in discovery pointed to a disallowed address: '.$e->getMessage());
            }

            return [];
        }

        return $response->successful() && is_array($response->json()) ? $response->json() : [];
    }
}
