<?php

namespace Tests\Feature;

use App\Jobs\RunStep;
use App\Models\AiCredential;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkflowRunTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private AiCredential $claude;

    private AiCredential $openai;

    private AiCredential $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        $this->user = User::factory()->create();
        $this->workspace = Workspace::createFor($this->user, 'Test');
        $this->claude = $this->credential('anthropic', 'sk-ant-claude-key');
        $this->openai = $this->credential('openai', 'sk-openai-key');
        $this->gemini = $this->credential('google', 'AIzaGeminiKeyForTests000000');
    }

    public function test_the_proof_of_concept_flow_runs_end_to_end()
    {
        $polls = 0;
        Http::fake(function ($request) use (&$polls) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'api.anthropic.com/v1/messages') => Http::response([
                    'content' => [['type' => 'text', 'text' => str_starts_with($request['messages'][0]['content'], 'Caption') ? 'CAPTION' : 'SCRIPT about '.substr($request['messages'][0]['content'], 26)]],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
                ]),
                str_contains($url, 'images/generations') => Http::response(['data' => [['b64_json' => base64_encode('PNGDATA')]]]),
                str_ends_with($url, '/v1beta/interactions') => Http::response(['id' => 'int-1', 'status' => 'in_progress']),
                str_contains($url, '/v1beta/interactions/int-1') => Http::response(++$polls < 2
                    ? ['id' => 'int-1', 'status' => 'in_progress']
                    : ['id' => 'int-1', 'status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'video', 'mime_type' => 'video/mp4', 'data' => base64_encode('MP4DATA')]]]]]),
                str_contains($url, 'files/vid') => Http::response('MP4DATA', 200, ['Content-Type' => 'video/mp4']),
            };
        });

        $workflow = $this->workspace->workflows()->create(['name' => 'Reel', 'draft_graph' => $this->pocGraph()]);

        $id = $this->actingAs($this->user)->postJson("/workflows/{$workflow->id}/run")->assertOk()->json('execution_id');
        $this->drainQueue();

        $execution = Execution::with('steps')->find($id);
        $steps = $execution->steps->keyBy('node_id');

        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        $this->assertSame('Write a reel script about monsoon tea', $steps['n1']->input['prompt']);
        $this->assertSame('Cover for: SCRIPT about monsoon tea', $steps['n2']->input['prompt']);
        $this->assertSame('CAPTION', $steps['n4']->output['text']);
        $this->assertSame(['input' => 10, 'output' => 20], $steps['n1']->tokens);
        $this->assertSame(['image', 'video'], $execution->assets()->pluck('type')->sort()->values()->all());
        Storage::disk('public')->assertExists($execution->assets()->where('type', 'video')->first()->storage_path);

        // Keys travel in headers only, never in URLs.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'AIza') || str_contains($request->url(), 'sk-'));
        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'AIzaGeminiKeyForTests000000'));
    }

    public function test_a_failing_step_fails_the_run_with_keys_redacted_and_can_be_retried()
    {
        $fail = true;
        Http::fake(function () use (&$fail) {
            return $fail
                ? Http::response(['error' => ['message' => 'overloaded for sk-ant-claude-key']], 529)
                : Http::response(['content' => [['type' => 'text', 'text' => 'ok']], 'usage' => []]);
        });

        $workflow = $this->workspace->workflows()->create(['name' => 'Fail', 'draft_graph' => [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger.manual', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'n1', 'type' => 'ai.text', 'position' => ['x' => 0, 'y' => 0], 'data' => ['credential_id' => $this->claude->id, 'model' => 'claude-x', 'prompt' => 'hi']],
            ],
            'edges' => [['source' => 'trigger', 'target' => 'n1']],
        ]]);

        $id = $this->actingAs($this->user)->postJson("/workflows/{$workflow->id}/run")->json('execution_id');
        $this->drainQueue();

        $execution = Execution::find($id);
        $step = $execution->steps()->where('node_id', 'n1')->first();
        $this->assertSame('failed', $execution->status);
        $this->assertSame(3, $step->attempts);
        $this->assertStringNotContainsString('sk-ant-claude-key', $step->error);

        $fail = false;
        $this->actingAs($this->user)->postJson("/runs/{$id}/retry")->assertOk();
        $this->drainQueue();

        $this->assertSame('succeeded', $execution->fresh()->status);
    }

    public function test_nodes_without_a_model_use_workspace_defaults_then_the_fallback()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'rate limited']], 429),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'from gemini']]]]]]),
        ]);
        $this->workspace->modelDefaults()->create([
            'task_type' => 'text',
            'credential_id' => $this->claude->id, 'model' => 'claude-x',
            'fallback_credential_id' => $this->gemini->id, 'fallback_model' => 'gemini-flash',
        ]);
        $workflow = $this->workspace->workflows()->create(['name' => 'Route', 'draft_graph' => [
            'nodes' => [['id' => 'n1', 'type' => 'ai.text', 'position' => ['x' => 0, 'y' => 0], 'data' => ['prompt' => 'hi']]],
            'edges' => [],
        ]]);

        $id = $this->actingAs($this->user)->postJson("/workflows/{$workflow->id}/run")->json('execution_id');
        $this->drainQueue();

        $output = Execution::find($id)->steps()->first()->output;
        $this->assertSame(['text' => 'from gemini', 'provider' => 'google', 'model' => 'gemini-flash'], $output);
    }

    public function test_other_workspaces_cannot_run_or_view_a_flow()
    {
        $workflow = $this->workspace->workflows()->create(['name' => 'Private', 'draft_graph' => Workflow::emptyGraph()]);
        $other = User::factory()->create();

        $this->actingAs($other)->postJson("/workflows/{$workflow->id}/run")->assertNotFound();
        $this->actingAs($other)->get("/workflows/{$workflow->id}")->assertNotFound();
    }

    public function test_saving_a_graph_rejects_unknown_node_types()
    {
        $workflow = $this->workspace->workflows()->create(['name' => 'X', 'draft_graph' => Workflow::emptyGraph()]);

        $this->actingAs($this->user)->putJson("/workflows/{$workflow->id}", [
            'graph' => ['nodes' => [['id' => 'a', 'type' => 'shell.exec', 'data' => []]], 'edges' => []],
        ])->assertUnprocessable();
    }

    private function credential(string $provider, string $key): AiCredential
    {
        $credential = new AiCredential(['provider' => $provider, 'label' => $provider, 'status' => 'valid']);
        $credential->workspace()->associate($this->workspace);
        $credential->setSecret($key);
        $credential->save();

        return $credential;
    }

    /** Run queued RunStep jobs (ignoring delays) until none are left. */
    private function drainQueue(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $jobs = Queue::pushed(RunStep::class)->all();
            if (! $jobs) {
                return;
            }
            Queue::fake();
            foreach ($jobs as $job) {
                app()->call([$job, 'handle']);
            }
        }
        $this->fail('Queue did not drain.');
    }

    private function pocGraph(): array
    {
        $node = fn ($id, $type, $data) => ['id' => $id, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'data' => $data];

        return [
            'nodes' => [
                $node('trigger', 'trigger.manual', ['input' => ['topic' => 'monsoon tea']]),
                $node('n1', 'ai.text', ['key' => 'script', 'credential_id' => $this->claude->id, 'model' => 'claude-x', 'prompt' => 'Write a reel script about {{trigger.output.topic}}']),
                $node('n2', 'ai.image', ['key' => 'cover', 'credential_id' => $this->openai->id, 'model' => 'gpt-image-1', 'prompt' => 'Cover for: {{script.output.text}}']),
                $node('n3', 'ai.video', ['key' => 'clip', 'credential_id' => $this->gemini->id, 'model' => 'gemini-omni-1.1-flash', 'prompt' => '{{script.output.text}}']),
                $node('n4', 'ai.text', ['key' => 'caption', 'credential_id' => $this->claude->id, 'model' => 'claude-x', 'prompt' => 'Caption for {{cover.output.url}} and {{clip.output.url}}']),
            ],
            'edges' => [
                ['source' => 'trigger', 'target' => 'n1'],
                ['source' => 'n1', 'target' => 'n2'],
                ['source' => 'n1', 'target' => 'n3'],
                ['source' => 'n2', 'target' => 'n4'],
                ['source' => 'n3', 'target' => 'n4'],
            ],
        ];
    }
}
