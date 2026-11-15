<?php

namespace Tests\Feature;

use App\Jobs\RunStep;
use App\Models\AiCredential;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Execution;
use App\Models\PromptGuide;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PromptWriterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private AiCredential $claude;

    private AiCredential $openai;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        $this->withoutVite();
        $this->user = User::factory()->create();
        $this->workspace = Workspace::createFor($this->user, 'Test');
        $this->claude = $this->credential('anthropic', 'sk-ant-key');
        $this->openai = $this->credential('openai', 'sk-openai-key');
        $this->workspace->modelDefaults()->create(['task_type' => 'text', 'credential_id' => $this->claude->id, 'model' => 'claude-opus-5-5']);
        $this->workspace->modelDefaults()->create(['task_type' => 'image', 'credential_id' => $this->openai->id, 'model' => 'gpt-image-2']);
    }

    public function test_ai_writes_the_prompt_from_the_brief_and_training_and_the_image_model_gets_it_with_locked_blocks()
    {
        PromptGuide::create(['workspace_id' => $this->workspace->id, 'task_type' => 'image',
            'instructions' => 'Always Instagram 4:5. Livora brand: sage green and cream. Headline top-left in a serif font.',
            'examples' => [['prompt' => 'Editorial flat lay of chamomile tea on cream linen...', 'note' => 'loved this', 'saved_at' => now()->toIso8601String()]]]);
        $maya = $this->character('Maya');
        $layout = $this->asset('LAYOUT');

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => 'Prompt: Maya holds a glass cup of green tea beside the headline "Calm Focus", keeping the layout of reference image 2.']], 'usage' => []]),
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode('IMG')]]]),
        ]);

        $execution = $this->runFlow(['brief' => 'Maya with our green tea, Tip #2, calm focus', 'prompt_mode' => 'ai',
            'character_ids' => [$maya->id], 'references' => [['src' => "asset:{$layout->id}", 'role' => 'match']]]);

        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        $output = $execution->steps->first()->output;

        // 1. The writer (text model) got the training, the brief, the cast, the roles and the actual images.
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'anthropic')) {
                return false;
            }
            $content = $r['messages'][0]['content'];
            $text = end($content)['text'];

            return str_contains($r['system'], 'STYLE GUIDE FROM THE USER (always follow it):'."\nAlways Instagram 4:5. Livora brand")
                && str_contains($r['system'], "Example 1 (loved this):\nEditorial flat lay of chamomile tea")
                && str_contains($r['system'], 'Never leave blanks')
                && str_contains($text, "BRIEF:\nMaya with our green tea, Tip #2, calm focus")
                && str_contains($text, '- Maya (')
                && str_contains($text, '- reference image 1: character sheet / photo of a recurring character')
                && str_contains($text, '- reference image 2: Match closely')
                && array_column(array_slice($content, 0, 2), 'type') === ['image', 'image'];
        });

        // 2. The image model got the written scene, wrapped in the fixed identity + reference blocks.
        $this->assertStringStartsWith('CHARACTER CONSISTENCY', $output['prompt_sent']);
        $this->assertStringContainsString('• Reference image 2: MATCH CLOSELY.', $output['prompt_sent']);
        $this->assertStringEndsWith("SCENE:\nMaya holds a glass cup of green tea beside the headline \"Calm Focus\", keeping the layout of reference image 2.", $output['prompt_sent']);
        $this->assertSame('anthropic · claude-opus-5-5', $output['prompt_written_by']);
        $this->assertSame(2, $output['reference_count']);
    }

    public function test_colour_theme_references_reach_the_writer_as_colours_only_never_as_an_image()
    {
        $theme = $this->asset(CharacterConsistencyTest::png([7, 8, 9], [248, 170, 85]));
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => 'A fresh layout in charcoal and orange.']], 'usage' => []]),
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode('IMG')]]]),
        ]);

        $execution = $this->runFlow(['brief' => 'Larwell automation ad', 'prompt_mode' => 'ai', 'references' => [['src' => "asset:{$theme->id}", 'role' => 'color_theme']]]);

        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'anthropic')) {
                return false;
            }
            $content = $r['messages'][0]['content'];

            return is_string($content) // no image blocks at all
                && str_contains($content, 'COLOUR THEME to build the design around (from a reference you are deliberately not shown')
                && preg_match('/#[0-9A-F]{6} near-black \(75%\), #[0-9A-F]{6} orange \(24%\)$/m', $content)
                && ! str_contains($content, 'ATTACHED REFERENCES')
                && str_contains($r['system'], 'invent a completely original layout');
        });
        // With no image references left, the image model uses plain generation, not the edits endpoint.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'images/generations'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'images/edits'));
    }

    public function test_the_writer_uses_the_model_chosen_in_its_training()
    {
        PromptGuide::create(['workspace_id' => $this->workspace->id, 'task_type' => 'image', 'credential_id' => $this->openai->id, 'model' => 'gpt-5.6-sol']);
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'A sunrise over tea fields.']]], 'usage' => []]),
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode('IMG')]]]),
        ]);

        $execution = $this->runFlow(['brief' => 'tea fields at sunrise', 'prompt_mode' => 'ai']);

        $this->assertSame('openai · gpt-5.6-sol', $execution->steps->first()->output['prompt_written_by']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'anthropic'));
    }

    public function test_ai_mode_ignores_a_leftover_hand_written_prompt_and_asks_for_a_brief_before_running()
    {
        $workflow = $this->workspace->workflows()->create(['name' => 'T', 'draft_graph' => ['nodes' => [
            ['id' => 'i', 'type' => 'ai.image', 'position' => ['x' => 0, 'y' => 0], 'data' => [
                'label' => 'Image', 'prompt_mode' => 'ai', 'prompt' => 'Old template with [BRAND NAME] and [NEW HEADLINE]',
            ]],
        ], 'edges' => []]]);

        $this->actingAs($this->user)->postJson("/workflows/{$workflow->id}/run")
            ->assertStatus(422)
            ->assertJsonPath('message', '"Image" needs a brief: write in one line what this image should show.');

        $workflow->update(['draft_graph' => ['nodes' => [['id' => 'i', 'type' => 'ai.image', 'position' => ['x' => 0, 'y' => 0], 'data' => [
            'label' => 'Image', 'prompt_mode' => 'ai', 'prompt' => 'Old template with [BRAND NAME]', 'brief' => 'Green tea post for Livora',
        ]]], 'edges' => []]]);
        Http::fake();

        $this->postJson("/workflows/{$workflow->id}/run")->assertOk();
    }

    public function test_manual_mode_still_checks_the_hand_written_prompt_for_blanks()
    {
        $workflow = $this->workspace->workflows()->create(['name' => 'T', 'draft_graph' => ['nodes' => [
            ['id' => 'i', 'type' => 'ai.image', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Image', 'prompt_mode' => 'manual', 'brief' => '[IGNORED]', 'prompt' => 'Post about [PRODUCT]']],
        ], 'edges' => []]]);

        $this->actingAs($this->user)->postJson("/workflows/{$workflow->id}/run")->assertStatus(422)->assertJsonPath('blanks.0.blanks', ['[PRODUCT]']);
    }

    public function test_an_empty_brief_is_caught_before_the_run_starts()
    {
        $workflow = $this->workspace->workflows()->create(['name' => 'T', 'draft_graph' => ['nodes' => [
            ['id' => 'v', 'type' => 'ai.video', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Clip', 'brief' => '  ', 'prompt_mode' => 'ai']],
        ], 'edges' => []]]);
        Http::fake();

        $this->actingAs($this->user)->postJson("/workflows/{$workflow->id}/run")
            ->assertStatus(422)->assertJsonPath('message', '"Clip" needs a brief: write in one line what this video should show.');
        $this->assertSame(0, Execution::count());
        Http::assertNothingSent();
    }

    public function test_use_as_example_saves_only_the_scene_and_keeps_the_newest_twelve()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Maya at a sunlit cafe table, warm tones.']], 'usage' => []]),
            'api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode('IMG')]]]),
        ]);
        $maya = $this->character('Maya');
        $step = $this->runFlow(['brief' => 'Maya at a cafe', 'prompt_mode' => 'ai', 'character_ids' => [$maya->id]])->steps->first();

        $this->actingAs($this->user)->postJson("/runs/steps/{$step->id}/learn", ['note' => 'great mood'])->assertOk()->assertJson(['examples' => 1]);

        $guide = PromptGuide::where('task_type', 'image')->first();
        $this->assertSame('Maya at a sunlit cafe table, warm tones.', $guide->examples[0]['prompt']);
        $this->assertSame('great mood', $guide->examples[0]['note']);

        $guide->update(['examples' => array_fill(0, 12, ['prompt' => 'old', 'note' => null, 'saved_at' => now()->toIso8601String()])]);
        $this->postJson("/runs/steps/{$step->id}/learn")->assertOk()->assertJson(['examples' => 12]);
        $this->assertSame('Maya at a sunlit cafe table, warm tones.', $guide->fresh()->examples[0]['prompt']);

        $other = User::factory()->create();
        $this->actingAs($other)->postJson("/runs/steps/{$step->id}/learn")->assertNotFound();
    }

    public function test_training_is_saved_from_the_prompt_writer_page()
    {
        $this->actingAs($this->user)->get('/prompt-writer')->assertOk()->assertInertia(fn ($page) => $page->component('prompt-writer/index')->has('guides.image')->has('guides.video'));

        $this->put('/prompt-writer/video', ['instructions' => 'Vertical, 8 seconds, handheld feel.', 'examples' => [['prompt' => 'A slow push-in on Maya...']]])->assertRedirect();

        $guide = PromptGuide::where('task_type', 'video')->first();
        $this->assertSame('Vertical, 8 seconds, handheld feel.', $guide->instructions);
        $this->assertSame('A slow push-in on Maya...', $guide->examples[0]['prompt']);
    }

    private function runFlow(array $data): Execution
    {
        $workflow = $this->workspace->workflows()->create(['name' => 'T', 'draft_graph' => ['nodes' => [
            ['id' => 'i', 'type' => 'ai.image', 'position' => ['x' => 0, 'y' => 0], 'data' => $data],
        ], 'edges' => []]]);
        $id = $this->actingAs($this->user)->postJson("/workflows/{$workflow->id}/run")->assertOk()->json('execution_id');
        foreach (Queue::pushed(RunStep::class) as $job) {
            app()->call([$job, 'handle']);
        }

        return Execution::with('steps')->find($id);
    }

    private function character(string $name): Character
    {
        return Character::create(['workspace_id' => $this->workspace->id, 'name' => $name, 'style' => 'photorealistic',
            'appearance' => 'Wavy black hair, gold nose stud, mustard kurta', 'sheet_asset_id' => $this->asset('SHEET')->id, 'sheet_status' => 'ready']);
    }

    private function credential(string $provider, string $key): AiCredential
    {
        $credential = new AiCredential(['provider' => $provider, 'label' => $provider, 'status' => 'valid']);
        $credential->workspace()->associate($this->workspace);
        $credential->setSecret($key);
        $credential->save();

        return $credential;
    }

    private function asset(string $bytes): Asset
    {
        $path = "assets/{$this->workspace->id}/".md5($bytes.microtime()).'.png';
        Storage::disk('public')->put($path, $bytes);

        return Asset::create(['workspace_id' => $this->workspace->id, 'type' => 'image', 'storage_path' => $path, 'mime' => 'image/png', 'size' => strlen($bytes)]);
    }
}
