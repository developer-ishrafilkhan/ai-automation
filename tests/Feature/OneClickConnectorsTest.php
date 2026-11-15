<?php

namespace Tests\Feature;

use App\Jobs\RunStep;
use App\Models\AiCredential;
use App\Models\Execution;
use App\Models\McpServer;
use App\Models\User;
use App\Models\Workspace;
use App\Support\UrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class OneClickConnectorsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->user = User::factory()->create();
        $this->workspace = Workspace::createFor($this->user, 'Test');
        $this->app->instance(UrlGuard::class, new class extends UrlGuard
        {
            protected function resolve(string $host): array
            {
                return str_starts_with($host, 'internal.') ? ['10.0.0.5'] : ['93.184.216.34'];
            }
        });
    }

    public function test_notion_connects_in_one_click_via_discovery_registration_and_pkce()
    {
        $this->fakeMcp('mcp.notion.com', [
            'mcp.notion.com/mcp' => Http::response(['error' => 'unauthorized'], 401, ['WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.notion.com/.well-known/oauth-protected-resource/mcp"']),
            'mcp.notion.com/.well-known/oauth-protected-resource/mcp' => Http::response(['resource' => 'https://mcp.notion.com/mcp', 'authorization_servers' => ['https://mcp.notion.com']]),
            'mcp.notion.com/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://mcp.notion.com', 'authorization_endpoint' => 'https://mcp.notion.com/authorize',
                'token_endpoint' => 'https://mcp.notion.com/token', 'registration_endpoint' => 'https://mcp.notion.com/register',
                'code_challenge_methods_supported' => ['S256'],
            ]),
            'mcp.notion.com/register' => Http::response(['client_id' => 'dyn-client-1'], 201),
            'mcp.notion.com/token' => Http::response(['access_token' => 'notion-access', 'refresh_token' => 'notion-refresh', 'expires_in' => 3600]),
        ], authorized: 'notion-access');

        $redirect = $this->actingAs($this->user)->post('/connectors/apps/notion', [], ['X-Inertia' => 'true'])->assertStatus(409)->headers->get('X-Inertia-Location');
        parse_str(parse_url($redirect, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://mcp.notion.com/authorize?', $redirect);
        $this->assertSame('dyn-client-1', $query['client_id']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('https://mcp.notion.com/mcp', $query['resource']);
        $this->assertSame(route('connectors.oauth.callback'), $query['redirect_uri']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://mcp.notion.com/register' && $r['token_endpoint_auth_method'] === 'none' && $r['redirect_uris'] === [route('connectors.oauth.callback')]);

        $this->get('/connectors/oauth/callback?code=abc&state='.$query['state'])->assertRedirect('/connectors')->assertSessionHas('success', 'Notion connected.');

        $server = McpServer::first();
        $this->assertSame(['notion', 'oauth', 'valid'], [$server->catalog_id, $server->auth_type, $server->status]);
        $this->assertSame('notion-access', $server->oauthData()['access_token']);
        $this->assertStringNotContainsString('notion-access', $server->encrypted_oauth);
        $this->assertCount(1, $server->tools_cache);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://mcp.notion.com/token' && $r['grant_type'] === 'authorization_code' && $r['code'] === 'abc' && strlen($r['code_verifier']) === 64 && $r['client_id'] === 'dyn-client-1');
        Http::assertSent(fn (Request $r) => ($r['method'] ?? null) === 'tools/list' && $r->hasHeader('Authorization', 'Bearer notion-access'));
    }

    public function test_google_apps_use_the_configured_client_offline_access_and_exact_scopes()
    {
        config(['mcp_catalog.clients.google.client_id' => 'google-client', 'mcp_catalog.clients.google.client_secret' => 'google-secret']);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'g-access', 'refresh_token' => 'g-refresh', 'expires_in' => 3599])] + $this->toolsFake('sheetsmcp.googleapis.com/*'));

        $redirect = $this->actingAs($this->user)->post('/connectors/apps/google-sheets', [], ['X-Inertia' => 'true'])->headers->get('X-Inertia-Location');
        parse_str(parse_url($redirect, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $redirect);
        $this->assertSame('google-client', $query['client_id']);
        $this->assertSame(['offline', 'consent'], [$query['access_type'], $query['prompt']]);
        $this->assertSame('https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/spreadsheets.readonly https://www.googleapis.com/auth/spreadsheets', $query['scope']);
        $this->assertSame('https://sheetsmcp.googleapis.com/mcp/v1', $query['resource']);
        Http::assertNothingSent(); // pinned endpoints: no discovery needed

        $this->get('/connectors/oauth/callback?code=xyz&state='.$query['state'])->assertSessionHas('success');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['client_secret'] === 'google-secret');
        $this->assertSame('Google Sheets', McpServer::first()->name);
    }

    public function test_apps_that_need_an_oauth_app_explain_the_setup_instead_of_failing_silently()
    {
        config(['mcp_catalog.clients.github.client_id' => null]);

        $this->actingAs($this->user)->get('/connectors')
            ->assertInertia(fn ($page) => $page->where('catalog', fn ($apps) => collect($apps)->firstWhere('id', 'github')['ready'] === false
                && collect($apps)->firstWhere('id', 'notion')['ready'] === true));

        $this->actingAs($this->user)->from('/connectors')->post('/connectors/apps/github')
            ->assertRedirect('/connectors')
            ->assertSessionHas('error', fn ($msg) => str_contains($msg, 'GITHUB_MCP_CLIENT_ID') && str_contains($msg, route('connectors.oauth.callback')));
    }

    public function test_callback_rejects_a_wrong_state_and_discovery_cannot_point_at_private_networks()
    {
        config(['mcp_catalog.clients.google.client_id' => 'google-client']);
        Http::fake([
            'evil.example/mcp' => Http::response('', 401, ['WWW-Authenticate' => 'Bearer resource_metadata="https://internal.corp/.well-known/x"']),
            '*' => Http::response('', 404),
        ]);
        $this->actingAs($this->user)->post('/connectors/apps/gmail', [], ['X-Inertia' => 'true']);

        $this->get('/connectors/oauth/callback?code=x&state=forged')->assertSessionHas('error');
        $this->assertSame(0, McpServer::count());
        Http::assertNothingSent();

        $this->from('/connectors')->post('/connectors/oauth', ['name' => 'Evil', 'url' => 'https://evil.example/mcp'])
            ->assertSessionHas('error', fn ($msg) => str_contains($msg, 'disallowed address'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'internal.corp'));
    }

    public function test_research_refreshes_an_expired_oauth_token_before_using_the_connector()
    {
        Queue::fake();
        $server = new McpServer(['catalog_id' => 'linear', 'name' => 'Linear', 'url' => 'https://mcp.linear.app/mcp', 'status' => 'valid']);
        $server->workspace()->associate($this->workspace);
        $server->setOAuthData(['access_token' => 'old', 'refresh_token' => 'r1', 'expires_at' => now()->subMinute()->timestamp,
            'token_endpoint' => 'https://mcp.linear.app/token', 'client_id' => 'c1', 'client_secret' => null, 'resource' => 'https://mcp.linear.app/mcp']);
        $server->save();

        $claude = new AiCredential(['provider' => 'anthropic', 'label' => 'c', 'status' => 'valid']);
        $claude->workspace()->associate($this->workspace);
        $claude->setSecret('sk-ant-key');
        $claude->save();

        Http::fake([
            'mcp.linear.app/token' => Http::response(['access_token' => 'new-access', 'expires_in' => 3600]),
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn', 'usage' => []]),
        ]);

        $workflow = $this->workspace->workflows()->create(['name' => 'R', 'draft_graph' => ['nodes' => [[
            'id' => 'r', 'type' => 'ai.research', 'position' => ['x' => 0, 'y' => 0],
            'data' => ['credential_id' => $claude->id, 'model' => 'claude-opus-5-5', 'prompt' => 'Q', 'mcp_server_ids' => [$server->id]],
        ]], 'edges' => []]]);
        $id = $this->actingAs($this->user)->postJson("/workflows/{$workflow->id}/run")->json('execution_id');
        foreach (Queue::pushed(RunStep::class) as $job) {
            app()->call([$job, 'handle']);
        }

        $this->assertSame('succeeded', Execution::find($id)->status);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://mcp.linear.app/token' && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'r1');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic') && $r['mcp_servers'][0]['authorization_token'] === 'new-access');
        $this->assertSame(['new-access', 'r1'], [$server->fresh()->oauthData()['access_token'], $server->fresh()->oauthData()['refresh_token']]);
    }

    /** Unauthenticated MCP calls get the given responses; calls with the access token list tools. */
    private function fakeMcp(string $host, array $routes, string $authorized): void
    {
        $tools = $this->toolsFake('*')['*'];
        Http::fake(function (Request $r) use ($routes, $host, $authorized, $tools) {
            if ($r->hasHeader('Authorization', "Bearer {$authorized}")) {
                return $tools($r);
            }
            foreach ($routes as $pattern => $response) {
                if (Str::is("https://{$pattern}", $r->url())) {
                    return $response;
                }
            }

            return str_contains($r->url(), $host) ? Http::response('', 404) : null;
        });
    }

    private function toolsFake(string $pattern): array
    {
        return [$pattern => function (Request $r) {
            return match ($r['method'] ?? null) {
                'initialize' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]),
                'tools/list' => Http::response(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'search', 'description' => 'Search']]]]),
                default => Http::response('', 202),
            };
        }];
    }
}
