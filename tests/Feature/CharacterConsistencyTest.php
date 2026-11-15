<?php

namespace Tests\Feature;

use App\Jobs\GenerateCharacterSheet;
use App\Jobs\RunStep;
use App\Models\AiCredential;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CharacterConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        $this->withoutVite();
        $this->user = User::factory()->create();
        $this->workspace = Workspace::createFor($this->user, 'Test');
    }

    public function test_a_character_is_created_with_photos_and_its_sheet_is_generated_from_those_photos_only()
    {
        $photo = $this->asset('PHOTO');
        $openai = $this->credential('openai', 'sk-openai');
        $this->workspace->modelDefaults()->create(['task_type' => 'image', 'credential_id' => $openai->id, 'model' => 'gpt-image-1']);

        $this->actingAs($this->user)->post('/characters', [
            'name' => 'Maya', 'appearance' => 'Young Indian woman, shoulder-length wavy black hair, small nose stud, mustard kurta', 'style' => 'photorealistic',
            'voice' => 'Warm, upbeat Hinglish', 'reference_asset_ids' => [$photo->id],
        ])->assertRedirect();
        $maya = Character::first();

        $this->post("/characters/{$maya->id}/sheet")->assertRedirect();
        $this->assertSame('generating', $maya->fresh()->sheet_status);
        Queue::assertPushed(GenerateCharacterSheet::class);

        Http::fake(['api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode('SHEET')]]])]);
        app()->call([new GenerateCharacterSheet($maya->id), 'handle']);

        $maya->refresh();
        $this->assertSame('ready', $maya->sheet_status);
        $this->assertSame('SHEET', Storage::disk('public')->get($maya->sheet->storage_path));
        $this->assertContains('character-sheet', $maya->sheet->tags);
        Http::assertSent(fn (Request $r) => count(array_filter($r->data(), fn ($p) => $p['name'] === 'image[]')) === 1
            && str_contains(collect($r->data())->firstWhere('name', 'prompt')['contents'], 'mustard kurta'));
    }

    public function test_a_sheet_stuck_because_its_worker_died_is_shown_as_failed_and_can_be_retried()
    {
        $maya = Character::create(['workspace_id' => $this->workspace->id, 'name' => 'Maya', 'appearance' => 'Curly red hair, green eyes, freckles', 'style' => 'anime', 'sheet_status' => 'generating']);
        Character::whereKey($maya->id)->update(['updated_at' => now()->subMinutes(20)]);

        $this->actingAs($this->user)->get('/characters')->assertOk()
            ->assertInertia(fn ($page) => $page->where('characters.0.sheet_status', 'failed')
                ->where('characters.0.sheet_error', fn ($e) => str_contains($e, 'interrupted')));

        $this->post("/characters/{$maya->id}/sheet")->assertRedirect();
        $this->assertSame('generating', $maya->fresh()->sheet_status);
    }

    public function test_the_app_warns_when_queued_work_is_not_being_picked_up()
    {
        config(['queue.default' => 'database']);
        $this->actingAs($this->user)->get('/characters')->assertInertia(fn ($page) => $page->where('queueStalled', false));

        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(5)->timestamp, 'created_at' => now()->subMinutes(5)->timestamp]);

        $this->get('/characters')->assertInertia(fn ($page) => $page->where('queueStalled', true));
    }

    public function test_image_nodes_send_the_character_sheet_and_photos_first_with_an_identity_block_on_top()
    {
        $gemini = $this->credential('google', 'AIzaGeminiKeyForTests000000');
        $maya = $this->character('Maya', sheet: 'SHEET', photos: ['P1']);
        $extra = $this->asset('STYLEREF');
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('OUT')]]]]]]])]);

        $execution = $this->runFlow([$this->node('i', 'ai.image', [
            'credential_id' => $gemini->id, 'model' => 'gemini-image', 'prompt' => 'Maya sipping chai at a rooftop cafe at sunset',
            'character_ids' => [$maya->id], 'references' => ["asset:{$extra->id}"],
        ])]);

        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        $this->assertSame(3, $execution->steps->first()->output['reference_count']);
        Http::assertSent(function (Request $r) {
            $parts = $r['contents'][0]['parts'];
            $text = end($parts)['text'];

            return array_map(fn ($p) => base64_decode($p['inlineData']['data'] ?? ''), array_slice($parts, 0, 3)) === ['SHEET', 'P1', 'STYLEREF']
                && str_starts_with($text, 'CHARACTER CONSISTENCY')
                && str_contains($text, 'Maya: shown in reference image 1, reference image 2.')
                && str_contains($text, 'Appearance (never change): Curly red hair')
                && str_ends_with($text, "SCENE:\nMaya sipping chai at a rooftop cafe at sunset");
        });
    }

    public function test_omni_video_gets_character_images_as_input_parts_with_image_ref_tags_and_voice()
    {
        $gemini = $this->credential('google', 'AIzaGeminiKeyForTests000000');
        $maya = $this->character('Maya', sheet: 'SHEET', photos: [], voice: 'Calm, low voice');
        $polls = 0;
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response(['id' => 'v1', 'status' => 'in_progress']),
            'generativelanguage.googleapis.com/v1beta/interactions/v1' => function () use (&$polls) {
                return ++$polls < 2 ? Http::response(['status' => 'in_progress'])
                    : Http::response(['status' => 'completed', 'steps' => [['type' => 'user_input'], ['type' => 'model_output', 'content' => [['type' => 'video', 'mime_type' => 'video/mp4', 'data' => base64_encode('MP4')]]]]]);
            },
        ]);

        $execution = $this->runFlow([$this->node('v', 'ai.video', [
            'credential_id' => $gemini->id, 'model' => 'gemini-omni-1.1-flash', 'prompt' => 'Maya waves at the camera',
            'character_ids' => [$maya->id], 'aspect_ratio' => '9:16',
        ])]);

        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        $this->assertSame('MP4', Storage::disk('public')->get(Asset::where('type', 'video')->first()->storage_path));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/interactions')
            && $r['background'] === true
            && $r['response_format'] === ['type' => 'video', 'aspect_ratio' => '9:16']
            && $r->hasHeader('Api-Revision', '2026-05-20')
            && $r['input'][0] === ['type' => 'image', 'data' => base64_encode('SHEET'), 'mime_type' => 'image/png']
            && str_contains($r['input'][1]['text'], 'Maya: shown in <IMAGE_REF_0>.')
            && str_contains($r['input'][1]['text'], 'Voice: Calm, low voice'));
    }

    public function test_runs_with_unfilled_prompt_blanks_are_stopped_with_the_blanks_listed()
    {
        $workflow = $this->workspace->workflows()->create(['name' => 'T', 'draft_graph' => ['nodes' => [
            $this->node('i', 'ai.image', ['label' => 'Cover', 'prompt' => "Main visual: [NEW SUBJECT, e.g. the green tea variant]\nHeadline: \"[NEW HEADLINE]\"\nFormat: [1:1 / 4:5 / 9:16]. Uses {{script.output.text}} and [link](https://x.y)."]),
        ], 'edges' => []]]);

        $this->actingAs($this->user)->postJson("/workflows/{$workflow->id}/run")
            ->assertStatus(422)
            ->assertJsonPath('blanks.0.node', 'Cover')
            ->assertJsonPath('blanks.0.blanks', ['[NEW SUBJECT, e.g. the green tea variant]', '[NEW HEADLINE]', '[1:1 / 4:5 / 9:16]'])
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'Fill in'))->etc());
        $this->assertSame(0, Execution::count());
    }

    public function test_selected_characters_must_appear_and_the_exact_prompt_sent_is_recorded()
    {
        $gemini = $this->credential('google', 'AIzaGeminiKeyForTests000000');
        $maya = $this->character('Maya', sheet: 'SHEET', photos: []);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('OUT')]]]]]]])]);

        $execution = $this->runFlow([$this->node('i', 'ai.image', ['credential_id' => $gemini->id, 'model' => 'g', 'prompt' => 'A tea post', 'character_ids' => [$maya->id]])]);

        $output = $execution->steps->first()->output;
        $this->assertStringContainsString('Maya must appear in this image', $output['prompt_sent']);
        $this->assertStringEndsWith("SCENE:\nA tea post", $output['prompt_sent']);
        $this->assertSame(1, $output['reference_count']);
    }

    public function test_reference_roles_tell_the_model_what_to_take_from_each_image()
    {
        $gemini = $this->credential('google', 'AIzaGeminiKeyForTests000000');
        $maya = $this->character('Maya', sheet: 'SHEET', photos: []);
        $scene = $this->asset('SCENE');
        $palette = $this->asset(self::png([28, 28, 30], [243, 154, 43]));
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('OUT')]]]]]]])]);

        $execution = $this->runFlow([$this->node('i', 'ai.image', [
            'credential_id' => $gemini->id, 'model' => 'g', 'prompt' => 'Diwali offer post',
            'character_ids' => [$maya->id],
            'references' => [
                ['src' => "asset:{$scene->id}", 'role' => 'swap_character'],
                ['src' => "asset:{$palette->id}", 'role' => 'color_theme'],
            ],
        ])]);

        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        $this->assertSame(2, $execution->steps->first()->output['reference_count']);
        Http::assertSent(function (Request $r) {
            $parts = $r['contents'][0]['parts'];
            $text = end($parts)['text'];

            // Only the character sheet and the scene are shown; the colour-theme image is not sent at all.
            return array_map(fn ($p) => base64_decode($p['inlineData']['data'] ?? ''), array_slice($parts, 0, -1)) === ['SHEET', 'SCENE']
                && str_contains($text, '• Reference image 2: CHANGE CHARACTER.')
                && str_contains($text, 'replace the person or character in it with the character Maya (exactly as in their own reference images)')
                && preg_match('/COLOUR THEME \(taken from a reference image that is deliberately not shown\): #[0-9A-F]{6} near-black \(75%\), #[0-9A-F]{6} orange \(24%\)\./', $text)
                && ! str_contains($text, 'Reference image 3')
                && strpos($text, 'CHARACTER CONSISTENCY') < strpos($text, 'REFERENCES — how to use each one')
                && str_ends_with($text, "SCENE:\nDiwali offer post");
        });
    }

    public function test_the_editor_can_preview_a_colour_theme_palette_for_its_own_assets_only()
    {
        $theme = $this->asset(self::png([28, 28, 30], [243, 154, 43]));
        $this->actingAs($this->user)->getJson("/assets/{$theme->id}/palette")->assertOk()
            ->assertJsonPath('colours.0.name', 'near-black')->assertJsonPath('colours.0.share', 75)
            ->assertJsonPath('colours.1.name', 'orange')->assertJsonCount(2, 'colours');

        $this->actingAs(User::factory()->create())->getJson("/assets/{$theme->id}/palette")->assertNotFound();
    }

    /** A real PNG: top quarter in $accent, the rest in $base. */
    public static function png(array $base, array $accent): string
    {
        $img = imagecreatetruecolor(40, 40);
        imagefilledrectangle($img, 0, 0, 39, 39, imagecolorallocate($img, ...$base));
        imagefilledrectangle($img, 0, 0, 39, 9, imagecolorallocate($img, ...$accent));
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    public function test_video_reference_roles_use_omni_image_tags_and_unknown_roles_fall_back_to_general()
    {
        $gemini = $this->credential('google', 'AIzaGeminiKeyForTests000000');
        $look = $this->asset('LOOK');
        $other = $this->asset('OTHER');
        Http::fake(['generativelanguage.googleapis.com/v1beta/interactions' => Http::response(['id' => 'v2', 'status' => 'in_progress'])]);

        $this->runFlow([$this->node('v', 'ai.video', [
            'credential_id' => $gemini->id, 'model' => 'gemini-omni-1.1-flash', 'prompt' => 'Product spin',
            'references' => [['src' => "asset:{$look->id}", 'role' => 'match'], ['src' => "asset:{$other->id}", 'role' => 'not-a-role']],
        ])]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/interactions')
            && str_contains($r['input'][2]['text'], '• <IMAGE_REF_0>: MATCH CLOSELY.')
            && str_contains($r['input'][2]['text'], '• <IMAGE_REF_1>: General reference'));
    }

    public function test_veo_style_models_refuse_character_references_instead_of_silently_ignoring_them()
    {
        $gemini = $this->credential('google', 'AIzaGeminiKeyForTests000000');
        $maya = $this->character('Maya', sheet: 'SHEET', photos: []);
        Http::fake();

        $execution = $this->runFlow([$this->node('v', 'ai.video', ['credential_id' => $gemini->id, 'model' => 'veo-3.0-generate-001', 'prompt' => 'x', 'character_ids' => [$maya->id]])]);

        $this->assertSame('failed', $execution->status);
        $this->assertStringContainsString('gemini-omni-1.1-flash', $execution->steps->first()->error);
        Http::assertNothingSent();
    }

    public function test_characters_are_private_to_their_workspace_and_deleted_ones_fail_clearly()
    {
        $other = Workspace::createFor(User::factory()->create(), 'Other');
        $foreign = Character::create(['workspace_id' => $other->id, 'name' => 'Spy', 'appearance' => 'A very secret person in a long coat', 'style' => 'anime']);

        $this->actingAs($this->user)->put("/characters/{$foreign->id}", ['name' => 'x', 'appearance' => str_repeat('a', 30), 'style' => 'anime'])->assertNotFound();
        $this->get('/characters')->assertOk()->assertDontSee('A very secret person');

        $gemini = $this->credential('google', 'AIzaGeminiKeyForTests000000');
        Http::fake();
        $execution = $this->runFlow([$this->node('i', 'ai.image', ['credential_id' => $gemini->id, 'model' => 'g', 'prompt' => 'x', 'character_ids' => [$foreign->id]])]);
        $this->assertSame('failed', $execution->status);
        $this->assertStringContainsString('no longer exists', $execution->steps->first()->error);
    }

    private function character(string $name, ?string $sheet, array $photos, ?string $voice = null): Character
    {
        return Character::create([
            'workspace_id' => $this->workspace->id, 'name' => $name, 'style' => 'photorealistic', 'voice' => $voice,
            'appearance' => 'Curly red hair, green eyes, freckles, yellow raincoat',
            'sheet_asset_id' => $sheet ? $this->asset($sheet)->id : null, 'sheet_status' => $sheet ? 'ready' : 'none',
            'reference_asset_ids' => array_map(fn ($bytes) => $this->asset($bytes)->id, $photos),
        ]);
    }

    private function runFlow(array $nodes): Execution
    {
        $workflow = $this->workspace->workflows()->create(['name' => 'T', 'draft_graph' => ['nodes' => $nodes, 'edges' => []]]);
        $id = $this->actingAs($this->user)->postJson("/workflows/{$workflow->id}/run")->assertOk()->json('execution_id');
        for ($i = 0; $i < 20 && ($jobs = Queue::pushed(RunStep::class)->all()); $i++) {
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

    private function asset(string $bytes): Asset
    {
        $path = "assets/{$this->workspace->id}/".md5($bytes.microtime()).'.png';
        Storage::disk('public')->put($path, $bytes);

        return Asset::create(['workspace_id' => $this->workspace->id, 'type' => 'image', 'storage_path' => $path, 'mime' => 'image/png', 'size' => strlen($bytes)]);
    }
}
