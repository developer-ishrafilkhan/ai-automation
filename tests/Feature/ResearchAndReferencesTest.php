<?php

namespace Tests\Feature;

use App\Jobs\RunStep;
use App\Models\AiCredential;
use App\Models\Asset;
use App\Models\Execution;
use App\Models\McpServer;
use App\Models\User;
use App\Models\Workspace;
use App\Support\UrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResearchAndReferencesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        $this->user = User::factory()->create();
        $this->workspace = Workspace::createFor($this->user, 'Test');

        // Offline DNS: "internal" hosts resolve to a private address, everything else is public.
        $this->app->instance(UrlGuard::class, new class extends UrlGuard
        {
            protected function resolve(string $host): array
            {
                return match (true) {
                    filter_var($host, FILTER_VALIDATE_IP) !== false => [$host],
                    str_starts_with($host, 'internal.') => ['10.0.0.5'],
                    default => ['93.184.216.34'],
                };
            }
        });
    }

    public function test_claude_research_uses_web_search_fetch_and_mcp_connectors_and_returns_citations()
    {
        $claude = $this->credential('anthropic', 'sk-ant-key');
        $notion = $this->mcpServer('Notion', 'https://mcp.example.com/notion', 'notion-secret');
        $calls = 0;

        Http::fake(['api.anthropic.com/v1/messages' => function () use (&$calls) {
            return ++$calls === 1
                ? Http::response(['content' => [['type' => 'server_tool_use', 'id' => 's1', 'name' => 'web_search', 'input' => ['query' => 'tea']]], 'stop_reason' => 'pause_turn', 'usage' => ['input_tokens' => 5, 'output_tokens' => 1]])
                : Http::response(['content' => [
                    ['type' => 'text', 'text' => '# Report', 'citations' => null],
                    ['type' => 'text', 'text' => ' Tea sales grew.', 'citations' => [
                        ['type' => 'web_search_result_location', 'url' => 'https://a.example/1', 'title' => 'Source A', 'cited_text' => '...'],
                        ['type' => 'web_search_result_location', 'url' => 'https://a.example/1', 'title' => 'Source A', 'cited_text' => '...'],
                    ]],
                ], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 50, 'output_tokens' => 200]]);
        }]);

        $execution = $this->runGraph([$this->node('r', 'ai.research', [
            'key' => 'research', 'credential_id' => $claude->id, 'model' => 'claude-opus-5-5',
            'mode' => 'deep', 'prompt' => 'Indian tea market', 'urls' => "https://tea.example/report\nhttp://insecure.example",
            'mcp_server_ids' => [$notion->id],
        ])]);

        $step = $execution->steps->first();
        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        $this->assertSame('# Report Tea sales grew.', $step->output['text']);
        $this->assertSame([['url' => 'https://a.example/1', 'title' => 'Source A']], $step->output['citations']);
        $this->assertSame(['input' => 55, 'output' => 201], $step->tokens);

        Http::assertSent(function (Request $request) use ($notion) {
            $types = array_column($request['tools'], 'type');

            return $request->hasHeader('anthropic-beta', 'mcp-client-2025-11-20')
                && $types === ['web_search_20260209', 'web_fetch_20260209', 'mcp_toolset']
                && $request['tools'][0]['max_uses'] === 25
                && $request['output_config'] === ['effort' => 'high']
                && $request['mcp_servers'][0] === ['type' => 'url', 'url' => 'https://mcp.example.com/notion', 'name' => $notion->slug(), 'authorization_token' => 'notion-secret']
                && str_contains($request['messages'][0]['content'], 'https://tea.example/report')
                && ! str_contains($request['messages'][0]['content'], 'http://insecure');
        });
        // The continuation resends the paused assistant turn instead of adding a new user message.
        Http::assertSent(fn (Request $request) => count($request['messages']) === 2 && $request['messages'][1]['role'] === 'assistant');
    }

    public function test_openai_deep_research_runs_in_the_background_and_resumes()
    {
        $openai = $this->credential('openai', 'sk-openai-key');
        $polls = 0;
        Http::fake([
            'api.openai.com/v1/responses' => Http::response(['id' => 'resp_1', 'status' => 'queued']),
            'api.openai.com/v1/responses/resp_1' => function () use (&$polls) {
                return ++$polls < 2 ? Http::response(['id' => 'resp_1', 'status' => 'in_progress']) : Http::response([
                    'id' => 'resp_1', 'status' => 'completed',
                    'output' => [
                        ['type' => 'web_search_call'],
                        ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Deep findings', 'annotations' => [['type' => 'url_citation', 'url' => 'https://b.example', 'title' => 'B']]]]],
                    ],
                    'usage' => ['input_tokens' => 7, 'output_tokens' => 9],
                ]);
            },
        ]);

        $execution = $this->runGraph([$this->node('r', 'ai.research', [
            'credential_id' => $openai->id, 'model' => 'o3-deep-research', 'mode' => 'deep', 'prompt' => 'Q',
        ])]);

        $output = $execution->steps->first()->output;
        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        $this->assertSame('Deep findings', $output['text']);
        $this->assertSame([['url' => 'https://b.example', 'title' => 'B']], $output['citations']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/responses' && $r['background'] === true && $r['tools'][0]['type'] === 'web_search_preview');
    }

    public function test_gemini_research_is_grounded_with_google_search_and_refuses_mcp()
    {
        $gemini = $this->credential('google', 'AIzaGeminiKeyForTests000000');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Grounded']]], 'groundingMetadata' => ['groundingChunks' => [['web' => ['uri' => 'https://c.example', 'title' => 'C']]]]]],
        ])]);

        $ok = $this->runGraph([$this->node('r', 'ai.research', ['credential_id' => $gemini->id, 'model' => 'gemini-x', 'prompt' => 'Q'])]);
        $this->assertSame([['url' => 'https://c.example', 'title' => 'C']], $ok->steps->first()->output['citations']);
        Http::assertSent(fn (Request $r) => isset($r['tools'][0]['google_search']));

        $server = $this->mcpServer('Drive', 'https://mcp.example.com/drive', null);
        $failed = $this->runGraph([$this->node('r', 'ai.research', ['credential_id' => $gemini->id, 'model' => 'gemini-x', 'prompt' => 'Q', 'mcp_server_ids' => [$server->id]])]);
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('MCP connectors', $failed->steps->first()->error);
    }

    public function test_image_node_sends_uploaded_and_upstream_reference_images_to_openai_edits()
    {
        $openai = $this->credential('openai', 'sk-openai-key');
        Http::fake(['api.openai.com/v1/images/*' => Http::response(['data' => [['b64_json' => base64_encode('NEWPNG')]]])]);

        $uploadedId = $this->actingAs($this->user)
            ->post('/assets/upload', ['image' => UploadedFile::fake()->image('product.png', 64, 64)], ['Accept' => 'application/json'])
            ->assertOk()->json('id');
        $this->assertSame(['reference'], Asset::find($uploadedId)->tags);

        $execution = $this->runGraph(
            [
                $this->node('first', 'ai.image', ['key' => 'cover', 'credential_id' => $openai->id, 'model' => 'gpt-image-1', 'prompt' => 'Cover']),
                $this->node('second', 'ai.image', ['credential_id' => $openai->id, 'model' => 'gpt-image-1', 'prompt' => 'Same product, new scene',
                    'references' => ["asset:{$uploadedId}", '{{cover.output.url}}']]),
            ],
            [['source' => 'first', 'target' => 'second']],
        );

        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        $this->assertSame(2, $execution->steps->firstWhere('node_id', 'second')->output['reference_count']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/images/generations');
        Http::assertSent(function (Request $r) {
            if ($r->url() !== 'https://api.openai.com/v1/images/edits') {
                return false;
            }
            $images = array_filter($r->data(), fn ($part) => $part['name'] === 'image[]');

            return count($images) === 2;
        });
    }

    public function test_gemini_receives_reference_images_inline()
    {
        $gemini = $this->credential('google', 'AIzaGeminiKeyForTests000000');
        $asset = $this->asset('REFBYTES');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('OUT')]]]]]]])]);

        $this->runGraph([$this->node('i', 'ai.image', ['credential_id' => $gemini->id, 'model' => 'gemini-image', 'prompt' => 'P', 'references' => ["asset:{$asset->id}"]])]);

        Http::assertSent(fn (Request $r) => ($r['contents'][0]['parts'][0]['inlineData']['data'] ?? null) === base64_encode('REFBYTES')
            && $r['contents'][0]['parts'][1]['text'] === 'P');
    }

    public function test_asset_urls_work_on_any_host_and_old_absolute_urls_still_resolve()
    {
        config(['app.url' => 'http://localhost']);
        $gemini = $this->credential('google', 'AIzaGeminiKeyForTests000000');
        $asset = $this->asset('REFBYTES');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('OUT')]]]]]]])]);

        // Root-relative, so previews load whether the app runs on :80, :8000 or a real domain.
        $this->assertSame("/storage/assets/{$this->workspace->id}/ref.png", $asset->url);

        // Outputs saved before this change hold absolute APP_URL links; they must still be read from disk.
        $legacy = "http://localhost/storage/assets/{$this->workspace->id}/ref.png";
        $execution = $this->runGraph([$this->node('i', 'ai.image', ['credential_id' => $gemini->id, 'model' => 'g', 'prompt' => 'P', 'references' => [$asset->url, $legacy]])]);

        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        $this->assertSame(2, $execution->steps->first()->output['reference_count']);
    }

    public function test_reference_urls_to_private_networks_and_other_workspaces_are_blocked()
    {
        $openai = $this->credential('openai', 'sk-openai-key');
        Http::fake();
        $foreign = Workspace::createFor(User::factory()->create(), 'Other');
        $foreignAsset = Asset::create(['workspace_id' => $foreign->id, 'type' => 'image', 'storage_path' => 'assets/x.png', 'mime' => 'image/png', 'size' => 1]);

        foreach (['https://internal.example/secret.png', 'https://127.0.0.1/x.png', 'http://cdn.example/x.png', "asset:{$foreignAsset->id}"] as $ref) {
            $execution = $this->runGraph([$this->node('i', 'ai.image', ['credential_id' => $openai->id, 'model' => 'gpt-image-1', 'prompt' => 'P', 'references' => [$ref]])]);
            $this->assertSame('failed', $execution->status, $ref);
        }
        Http::assertNothingSent();
    }

    public function test_connectors_are_verified_with_their_tools_listed_and_tokens_encrypted()
    {
        Http::fake(['mcp.example.com/*' => function (Request $r) {
            return match ($r['method']) {
                'initialize' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18']], 200, ['Mcp-Session-Id' => 'sess-1']),
                'notifications/initialized' => Http::response('', 202),
                'tools/list' => Http::response("event: message\ndata: ".json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'search_pages', 'description' => 'Search Notion']]]])."\n\n", 200, ['Content-Type' => 'text/event-stream']),
            };
        }]);

        $this->actingAs($this->user)->post('/connectors', ['name' => 'Notion', 'url' => 'https://mcp.example.com/mcp', 'token' => 'secret-token-123'])->assertRedirect();

        $server = McpServer::first();
        $this->assertSame('valid', $server->status, (string) $server->status_message);
        $this->assertSame([['name' => 'search_pages', 'description' => 'Search Notion']], $server->tools_cache);
        $this->assertStringNotContainsString('secret-token-123', $server->encrypted_token);
        $this->assertSame('secret-token-123', $server->token());
        Http::assertSent(fn (Request $r) => $r['method'] === 'tools/list' && $r->hasHeader('Mcp-Session-Id', 'sess-1') && $r->hasHeader('Authorization', 'Bearer secret-token-123'));

        $this->actingAs($this->user)->post('/connectors', ['name' => 'Internal', 'url' => 'https://internal.example/mcp']);
        $this->assertSame('invalid', McpServer::where('name', 'Internal')->first()->status);
        $this->actingAs($this->user)->withoutVite()->get('/connectors')->assertOk()->assertDontSee('secret-token-123');
    }

    public function test_the_ready_made_prompt_library_is_gone()
    {
        $workflow = $this->workspace->workflows()->create(['name' => 'X', 'draft_graph' => ['nodes' => [], 'edges' => []]]);

        $this->actingAs($this->user)->withoutVite()->get("/workflows/{$workflow->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->missing('promptLibrary'));
        $this->assertNull(config('prompt_library'));
    }

    private function runGraph(array $nodes, array $edges = []): Execution
    {
        $workflow = $this->workspace->workflows()->create(['name' => 'T', 'draft_graph' => ['nodes' => $nodes, 'edges' => $edges]]);
        $id = $this->actingAs($this->user)->postJson("/workflows/{$workflow->id}/run")->assertOk()->json('execution_id');

        for ($i = 0; $i < 50 && ($jobs = Queue::pushed(RunStep::class)->all()); $i++) {
            Queue::fake();
            foreach ($jobs as $job) {
                app()->call([$job, 'handle']);
            }
        }

        return Execution::with('steps')->find($id);
    }

    private function node(string $id, string $type, array $data): array
    {
        return ['id' => $id, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'data' => $data];
    }

    private function credential(string $provider, string $key): AiCredential
    {
        $credential = new AiCredential(['provider' => $provider, 'label' => $provider, 'status' => 'valid']);
        $credential->workspace()->associate($this->workspace);
        $credential->setSecret($key);
        $credential->save();

        return $credential;
    }

    private function mcpServer(string $name, string $url, ?string $token): McpServer
    {
        $server = new McpServer(['name' => $name, 'url' => $url, 'status' => 'valid']);
        $server->workspace()->associate($this->workspace);
        $server->setToken($token);
        $server->save();

        return $server;
    }

    private function asset(string $bytes): Asset
    {
        Storage::disk('public')->put("assets/{$this->workspace->id}/ref.png", $bytes);

        return Asset::create(['workspace_id' => $this->workspace->id, 'type' => 'image', 'storage_path' => "assets/{$this->workspace->id}/ref.png", 'mime' => 'image/png', 'size' => strlen($bytes)]);
    }
}
