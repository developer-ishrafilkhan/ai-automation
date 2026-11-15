<?php

namespace Tests\Feature;

use App\Jobs\RunStep;
use App\Models\AiCredential;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FlowGeneratorAndFormsTest extends TestCase
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

    public function test_ai_designs_a_flow_and_its_form_and_broken_parts_are_repaired()
    {
        $this->textModel();
        $plan = [
            'name' => 'Product launch post',
            'form' => [
                'title' => 'Get a launch post',
                'description' => 'Tell us about your product.',
                'submit_label' => 'Create my post',
                'fields' => [
                    ['key' => 'Product Name', 'label' => 'Product name', 'type' => 'text', 'required' => true, 'example' => 'Livora Tea'],
                    ['key' => 'photo', 'label' => 'Product photo', 'type' => 'image', 'required' => false],
                    ['key' => 'tone', 'label' => 'Tone', 'type' => 'select', 'options' => ['Playful', 'Premium']],
                ],
            ],
            'nodes' => [
                ['key' => 'research', 'type' => 'ai.research', 'label' => 'Market research', 'show_in_result' => false,
                    'data' => ['prompt' => 'Research {{trigger.output.product_name}} for {{trigger.output.audience}}', 'mode' => 'deep', 'mcp_server_ids' => [999]]],
                ['key' => 'caption', 'type' => 'ai.text', 'label' => 'Caption',
                    'data' => ['prompt' => 'Write a {{trigger.output.tone}} caption using {{research.output.text}}. Mention {{image.output.url}}.']],
                ['key' => 'image', 'type' => 'ai.image', 'label' => 'Post image',
                    'data' => ['brief' => 'Hero shot for {{caption.output.text}}', 'aspect_ratio' => '4:5', 'character_ids' => [12345],
                        'references' => [['src' => '{{trigger.output.photo}}', 'role' => 'product', 'note' => 'centre'], ['src' => 'https://evil.example/x.png']]]],
                ['key' => 'nope', 'type' => 'shell.exec', 'data' => []],
            ],
            'edges' => [['from' => 'caption', 'to' => 'research'], ['from' => 'ghost', 'to' => 'caption'], 'junk'],
        ];
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => "Here you go:\n```json\n".json_encode($plan)."\n```"]], 'usage' => []])]);

        $response = $this->actingAs($this->user)->postJson('/workflows/generate', ['description' => 'A form where a shop owner gets a launch post for their product'])->assertOk();
        $workflow = Workflow::findOrFail($response->json('id'));
        $graph = $workflow->draft_graph;
        $nodes = collect($graph['nodes'])->keyBy('id');
        $edges = collect($graph['edges'])->map(fn ($e) => "{$e['source']}>{$e['target']}")->all();

        // The request described the job to the workspace's text model.
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][0]['content'], 'launch post for their product') && str_contains($r['system'], 'STEP TYPES'));

        $this->assertSame('Product launch post', $workflow->name);
        $this->assertSame(['title' => 'Get a launch post', 'description' => 'Tell us about your product.', 'submit_label' => 'Create my post', 'daily_limit' => 50], $workflow->form_settings);
        $this->assertSame(['trigger', 'research', 'caption', 'image'], $nodes->keys()->all(), 'unknown node types are dropped');

        // Form: keys cleaned, a field the steps read but the plan forgot is added, test values filled in.
        $fields = collect($nodes['trigger']['data']['form']['fields']);
        $this->assertSame(['product_name', 'photo', 'tone', 'audience'], $fields->pluck('key')->all());
        $this->assertSame(['product_name' => 'Livora Tea', 'photo' => '', 'tone' => 'Playful', 'audience' => ''], $nodes['trigger']['data']['input']);

        // Links follow what each step reads. The caption reading the (later) image would loop, and so
        // would the model's own caption>research edge, so both are dropped; nothing points at "ghost".
        $this->assertEqualsCanonicalizing(['trigger>research', 'research>caption', 'caption>image'], $edges);
        $this->assertSame('Write a {{trigger.output.tone}} caption using {{research.output.text}}. Mention .', $nodes['caption']['data']['prompt']);

        // Settings are limited to safe, known values.
        $this->assertSame('deep', $nodes['research']['data']['mode']);
        $this->assertArrayNotHasKey('mcp_server_ids', $nodes['research']['data']);
        $this->assertTrue($nodes['research']['data']['hide_in_form']);
        $this->assertSame([['src' => '{{trigger.output.photo}}', 'role' => 'product', 'note' => 'centre']], $nodes['image']['data']['references']);
        $this->assertArrayNotHasKey('character_ids', $nodes['image']['data']);
        $this->assertSame(['prompt_mode' => 'ai', 'aspect_ratio' => '4:5'], array_intersect_key($nodes['image']['data'], ['prompt_mode' => 1, 'aspect_ratio' => 1]));
        $this->assertGreaterThan($nodes['caption']['position']['x'], $nodes['image']['position']['x']);
    }

    public function test_generator_explains_when_there_is_no_text_model()
    {
        $this->actingAs($this->user)->postJson('/workflows/generate', ['description' => 'Turn a topic into a blog post with an image'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'AI keys & models'));
    }

    public function test_switching_the_form_on_publishes_the_draft_and_needs_fields()
    {
        $workflow = $this->formFlow(fields: []);
        $this->actingAs($this->user)->putJson("/workflows/{$workflow->id}/form", ['enabled' => true, 'daily_limit' => 5])
            ->assertStatus(422)->assertJsonPath('message', 'Add at least one form field to the Form step first.');

        $workflow = $this->formFlow();
        $state = $this->actingAs($this->user)->putJson("/workflows/{$workflow->id}/form", ['enabled' => true, 'title' => 'Blog post maker', 'daily_limit' => 5])
            ->assertOk()->json();

        $workflow->refresh();
        $this->assertTrue($state['enabled']);
        $this->assertStringEndsWith('/f/'.$workflow->form_slug, $state['url']);
        $this->assertNotNull($workflow->active_version_id);
        $this->assertFalse($state['outdated']);
        $this->assertSame('Blog post maker', $workflow->formSettings()['title']);
    }

    public function test_visitor_fills_the_form_and_gets_only_the_deliverables_of_the_published_version()
    {
        $this->textModel();
        $workflow = $this->publicFlow();
        // Draft edits after publishing don't reach visitors until published again.
        $draft = $workflow->draft_graph;
        $draft['nodes'][1]['data']['prompt'] = 'CHANGED DRAFT';
        $workflow->update(['draft_graph' => $draft]);

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => 'OUTLINE']], 'usage' => []])
            ->push(['content' => [['type' => 'text', 'text' => 'FINAL POST']], 'usage' => []])]);

        $this->get("/f/{$workflow->form_slug}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('public-form/show')
            ->where('title', 'Blog post maker')
            ->where('fields.0.key', 'topic')
            ->where('fields.1.key', 'notes'));

        $this->post("/f/{$workflow->form_slug}", [])->assertSessionHasErrors(['topic' => 'The Topic field is required.']);

        $redirect = $this->post("/f/{$workflow->form_slug}", ['topic' => 'Composting at home'])->assertRedirect();
        $this->drainQueue();

        $execution = Execution::latest('id')->first();
        $this->assertSame('form', $execution->source);
        $this->assertStringEndsWith("/f/{$workflow->form_slug}/r/{$execution->public_token}", $redirect->headers->get('Location'));
        $this->assertSame('succeeded', $execution->status, (string) $execution->error);

        // The published prompt ran with the visitor's answer; the blank optional field stayed blank.
        Http::assertSent(fn (Request $r) => $r['messages'][0]['content'] === 'Outline a post on Composting at home. Notes: ');

        $status = $this->getJson("/f/{$workflow->form_slug}/r/{$execution->public_token}/status")->assertOk()->json();
        $this->assertSame('succeeded', $status['status']);
        $this->assertSame([['id' => 'post', 'label' => 'Blog post', 'kind' => 'text', 'status' => 'succeeded', 'progress' => null, 'text' => 'FINAL POST', 'url' => null, 'citations' => []]], $status['items']);
        $this->assertStringNotContainsString('OUTLINE', json_encode($status), 'hidden helper steps stay private');
        $this->assertStringNotContainsString('claude', json_encode($status), 'models stay private');

        $this->getJson("/f/{$workflow->form_slug}/r/not-the-token/status")->assertNotFound();
    }

    public function test_form_is_protected_against_bots_floods_and_switched_off_links()
    {
        $workflow = $this->publicFlow(dailyLimit: 1);

        $this->post("/f/{$workflow->form_slug}", ['topic' => 'x', 'website' => 'http://spam'])->assertStatus(422);
        $this->post("/f/{$workflow->form_slug}", ['topic' => 'first'])->assertRedirect();
        $this->post("/f/{$workflow->form_slug}", ['topic' => 'second'])->assertSessionHasErrors('form');
        $this->assertSame(1, Execution::where('source', 'form')->count());

        for ($i = 0; $i < 3; $i++) {
            $this->post("/f/{$workflow->form_slug}", ['topic' => 'again']);
        }
        $this->post("/f/{$workflow->form_slug}", ['topic' => 'too many'])->assertStatus(429);

        $workflow->update(['form_enabled' => false]);
        $this->get("/f/{$workflow->form_slug}")->assertNotFound();
        $token = Execution::where('source', 'form')->value('public_token');
        $this->get("/f/{$workflow->form_slug}/r/{$token}")->assertOk(); // earlier results stay viewable
    }

    public function test_an_uploaded_photo_becomes_a_reference_for_the_image_step()
    {
        $this->textModel();
        $openai = $this->credential('openai');
        $this->workspace->modelDefaults()->create(['task_type' => 'image', 'credential_id' => $openai->id, 'model' => 'gpt-image-2']);
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'A bright product shot.']], 'usage' => []]),
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode('IMG')]]]),
        ]);

        $workflow = $this->publicFlow(graph: ['nodes' => [
            $this->trigger([['key' => 'photo', 'label' => 'Product photo', 'type' => 'image', 'required' => true]]),
            ['id' => 'shot', 'type' => 'ai.image', 'position' => ['x' => 300, 'y' => 0], 'data' => ['label' => 'Shot', 'prompt_mode' => 'ai',
                'brief' => 'Studio shot of the product', 'references' => [['src' => '{{trigger.output.photo}}', 'role' => 'product']]]],
        ], 'edges' => [['id' => 'trigger-shot', 'source' => 'trigger', 'target' => 'shot']]]);

        $this->post("/f/{$workflow->form_slug}", ['photo' => 'not a file'])->assertSessionHasErrors('photo');
        $this->post("/f/{$workflow->form_slug}", ['photo' => UploadedFile::fake()->image('tea.png', 50, 50)])->assertRedirect();
        $this->drainQueue();

        $execution = Execution::latest('id')->with('steps')->first();
        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        $this->assertSame(1, $execution->steps->firstWhere('node_id', 'shot')->output['reference_count']);
        $this->assertStringStartsWith('/storage/assets/'.$this->workspace->id.'/', $execution->trigger_data['photo']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'images/edits'));
    }

    public function test_ai_suggests_form_fields_for_the_values_a_flow_reads()
    {
        $this->textModel();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
            'title' => 'Blog post maker',
            'fields' => [['key' => 'topic', 'label' => 'What should the post be about?', 'type' => 'textarea'], ['key' => 'extra', 'label' => 'Unused']],
        ])]], 'usage' => []])]);

        $workflow = $this->formFlow(fields: []);
        $result = $this->actingAs($this->user)->postJson("/workflows/{$workflow->id}/form/suggest")->assertOk()->json();

        $this->assertSame(['topic', 'notes'], array_column($result['fields'], 'key'), 'exactly the keys the flow reads');
        $this->assertSame('What should the post be about?', $result['fields'][0]['label']);
        $this->assertSame('Notes', $result['fields'][1]['label']);
    }

    public function test_visitor_picks_a_saved_character_and_the_steps_keep_it_consistent()
    {
        $this->textModel();
        $openai = $this->credential('openai');
        $this->workspace->modelDefaults()->create(['task_type' => 'image', 'credential_id' => $openai->id, 'model' => 'gpt-image-2']);
        $maya = $this->character('Maya');
        $arjun = $this->character('Arjun');
        $stranger = Character::create(['workspace_id' => Workspace::createFor(User::factory()->create(), 'Other')->id, 'name' => 'Not yours', 'style' => 'photorealistic', 'appearance' => 'x']);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['content' => [['type' => 'text', 'text' => 'SCRIPT']], 'usage' => []])
                ->push(['content' => [['type' => 'text', 'text' => 'Maya explores a jungle temple.']], 'usage' => []]),
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode('IMG')]]]),
        ]);

        $workflow = $this->publicFlow(graph: ['nodes' => [
            $this->trigger([
                ['key' => 'idea', 'label' => 'What happens?', 'type' => 'textarea', 'required' => true],
                ['key' => 'hero', 'label' => 'Choose your character', 'type' => 'character', 'required' => true],
            ]),
            ['id' => 'script', 'type' => 'ai.text', 'position' => ['x' => 300, 'y' => 0], 'data' => ['label' => 'Script',
                'prompt' => 'Write a script starring {{trigger.output.hero_name}}: {{trigger.output.idea}}']],
            ['id' => 'shot', 'type' => 'ai.image', 'position' => ['x' => 600, 'y' => 0], 'data' => ['label' => 'Shot', 'prompt_mode' => 'ai',
                'brief' => '{{script.output.text}}', 'character_ids' => ['{{trigger.output.hero}}']]],
        ], 'edges' => [['id' => 'a', 'source' => 'trigger', 'target' => 'script'], ['id' => 'b', 'source' => 'script', 'target' => 'shot']]]);

        // The form offers every saved character by name and picture, nothing more.
        $this->get("/f/{$workflow->form_slug}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('fields.1.type', 'character')
            ->has('characters', 2)
            ->where('characters.0', fn ($c) => $c['name'] === 'Arjun' && str_starts_with((string) $c['image'], '/storage/') && ! isset($c['appearance'])));

        $this->post("/f/{$workflow->form_slug}", ['idea' => 'Temple', 'hero' => $stranger->id])->assertSessionHasErrors('hero');
        $this->post("/f/{$workflow->form_slug}", ['idea' => 'A fearless explorer finds a glowing door', 'hero' => $maya->id])->assertRedirect();
        $this->drainQueue();

        $execution = Execution::latest('id')->with('steps')->first();
        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic') && ($r['messages'][0]['content'] ?? null) === 'Write a script starring Maya: A fearless explorer finds a glowing door');

        $shot = $execution->steps->firstWhere('node_id', 'shot')->output;
        $this->assertSame([$maya->id], $shot['character_ids']);
        $this->assertStringStartsWith('CHARACTER CONSISTENCY', $shot['prompt_sent']);
        $this->assertStringContainsString('• Maya: shown in reference image 1.', $shot['prompt_sent']);
        $this->assertStringNotContainsString('Arjun', $shot['prompt_sent']);
        $this->assertSame(1, $shot['reference_count']);
    }

    public function test_a_character_answer_used_as_a_person_reference_becomes_the_character()
    {
        // The setup that failed with "Only https:// URLs are allowed.": a Saved character question
        // listed as a "Use as Person" reference, next to an optional image question left empty.
        $this->textModel();
        $gemini = $this->credential('google');
        $this->workspace->modelDefaults()->create(['task_type' => 'video', 'credential_id' => $gemini->id, 'model' => 'gemini-omni-1.1-flash']);
        $ishrafil = $this->character('Ishrafil');
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Ishrafil walks into the dhaba.']], 'usage' => []]),
            'generativelanguage.googleapis.com/v1beta/interactions' => Http::response(['id' => 'v1', 'status' => 'in_progress']),
            'generativelanguage.googleapis.com/v1beta/interactions/v1' => Http::response(['status' => 'completed', 'steps' => [['type' => 'model_output',
                'content' => [['type' => 'video', 'mime_type' => 'video/mp4', 'data' => base64_encode('MP4')]]]]]),
        ]);

        $graph = ['nodes' => [
            $this->trigger([
                ['key' => 'video_prompt', 'label' => 'Idea', 'type' => 'textarea', 'required' => true],
                ['key' => 'visual_reference', 'label' => 'Style image', 'type' => 'image'],
                ['key' => 'character_image', 'label' => 'Character', 'type' => 'character', 'required' => true],
            ]),
            ['id' => 'generate_video', 'type' => 'ai.video', 'position' => ['x' => 300, 'y' => 0], 'data' => ['label' => 'Video', 'prompt_mode' => 'ai',
                'brief' => '{{trigger.output.video_prompt}}', 'references' => [
                    ['src' => '{{trigger.output.visual_reference}}', 'role' => 'style'],
                    ['src' => '{{trigger.output.character_image}}', 'role' => 'person', 'note' => 'main character'],
                ]]],
        ], 'edges' => [['id' => 'a', 'source' => 'trigger', 'target' => 'generate_video']]];
        // Stored as it was published before the fix (not cleaned on save).
        $workflow = $this->workspace->workflows()->create(['name' => 'Video', 'draft_graph' => $graph]);
        $version = $workflow->snapshotDraft();
        $workflow->update(['active_version_id' => $version->id, 'form_enabled' => true, 'form_slug' => 'charvideo']);

        $this->post('/f/charvideo', ['video_prompt' => 'A trailer at a roadside dhaba', 'character_image' => $ishrafil->id])->assertRedirect();
        $this->drainQueue();

        $execution = Execution::latest('id')->first();
        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/interactions') && $r->method() === 'POST'
            && $r['input'][0]['type'] === 'image' // the character's sheet
            && str_contains(collect($r['input'])->firstWhere('type', 'text')['text'], '• Ishrafil: shown in <IMAGE_REF_0>.'));

        // Saving the flow in the editor turns the reference into "Character chosen on the form".
        $this->actingAs($this->user)->putJson("/workflows/{$workflow->id}", ['graph' => $graph])->assertOk();
        $video = collect($workflow->fresh()->draft_graph['nodes'])->firstWhere('id', 'generate_video')['data'];
        $this->assertSame(['{{trigger.output.character_image}}'], $video['character_ids']);
        $this->assertSame(['{{trigger.output.visual_reference}}'], array_column($video['references'], 'src'));
    }

    public function test_a_character_question_can_allow_several_picks()
    {
        $maya = $this->character('Maya');
        $arjun = $this->character('Arjun');
        $workflow = $this->publicFlow(graph: ['nodes' => [
            $this->trigger([['key' => 'cast', 'label' => 'Cast', 'type' => 'character', 'required' => true, 'multiple' => true]]),
            ['id' => 'shot', 'type' => 'ai.image', 'position' => ['x' => 300, 'y' => 0], 'data' => ['label' => 'Shot', 'prompt_mode' => 'ai', 'brief' => 'Group photo', 'character_ids' => ['{{trigger.output.cast}}']]],
        ], 'edges' => [['id' => 'a', 'source' => 'trigger', 'target' => 'shot']]]);

        $four = [$maya->id, $arjun->id, $this->character('C')->id, $this->character('D')->id];
        $this->post("/f/{$workflow->form_slug}", ['cast' => $four])->assertSessionHasErrors('cast');
        $this->post("/f/{$workflow->form_slug}", ['cast' => [$maya->id, $arjun->id]])->assertRedirect();

        $this->assertSame(['cast' => "{$maya->id},{$arjun->id}"], Execution::latest('id')->first()->trigger_data);
    }

    public function test_generator_turns_a_visitor_choice_of_character_into_a_character_question()
    {
        $this->textModel();
        $maya = $this->character('Maya');
        $plan = [
            'name' => 'Character video',
            'form' => ['fields' => [['key' => 'idea', 'label' => 'Idea', 'type' => 'textarea']]],
            'nodes' => [
                ['key' => 'script', 'type' => 'ai.text', 'data' => ['prompt' => 'Script for {{trigger.output.hero_name}}: {{trigger.output.idea}}']],
                ['key' => 'clip', 'type' => 'ai.video', 'data' => ['brief' => '{{script.output.text}}', 'character_ids' => ['{{trigger.output.hero}}', $maya->id, 999]]],
            ],
        ];
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($plan)]], 'usage' => []])]);

        $id = $this->actingAs($this->user)->postJson('/workflows/generate', ['description' => 'Visitors choose a character and describe a short video'])->assertOk()->json('id');
        $nodes = collect(Workflow::find($id)->draft_graph['nodes'])->keyBy('id');

        $fields = collect($nodes['trigger']['data']['form']['fields'])->keyBy('key');
        $this->assertSame(['idea', 'hero'], $fields->keys()->all(), 'hero_name is filled in automatically, not asked');
        $this->assertSame('character', $fields['hero']['type']);
        $this->assertSame(['{{trigger.output.hero}}', $maya->id], $nodes['clip']['data']['character_ids'], 'unknown ids are dropped');
        $this->assertSame((string) $maya->id, $nodes['trigger']['data']['input']['hero']);
    }

    private function publicFlow(int $dailyLimit = 50, ?array $graph = null): Workflow
    {
        $workflow = $this->formFlow(graph: $graph);
        $this->actingAs($this->user)->putJson("/workflows/{$workflow->id}/form", ['enabled' => true, 'title' => 'Blog post maker', 'daily_limit' => $dailyLimit])->assertOk();
        auth()->logout();

        return $workflow->fresh();
    }

    private function formFlow(?array $fields = null, ?array $graph = null): Workflow
    {
        $fields ??= [
            ['key' => 'topic', 'label' => 'Topic', 'type' => 'text', 'required' => true],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
        ];

        return $this->workspace->workflows()->create(['name' => 'Blog', 'draft_graph' => $graph ?? ['nodes' => [
            $this->trigger($fields, ['topic' => 'TEST TOPIC', 'notes' => 'TEST NOTES']),
            ['id' => 'outline', 'type' => 'ai.text', 'position' => ['x' => 300, 'y' => 0], 'data' => ['label' => 'Outline', 'hide_in_form' => true,
                'prompt' => 'Outline a post on {{trigger.output.topic}}. Notes: {{trigger.output.notes}}']],
            ['id' => 'post', 'type' => 'ai.text', 'position' => ['x' => 600, 'y' => 0], 'data' => ['label' => 'Blog post', 'prompt' => 'Write it: {{outline.output.text}}']],
        ], 'edges' => [
            ['id' => 'a', 'source' => 'trigger', 'target' => 'outline'],
            ['id' => 'b', 'source' => 'outline', 'target' => 'post'],
        ]]]);
    }

    private function trigger(array $fields, array $input = []): array
    {
        return ['id' => 'trigger', 'type' => 'trigger.manual', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Form', 'input' => $input, 'form' => ['fields' => $fields]]];
    }

    /** Run queued steps, including the ones finished steps queue next. */
    private function drainQueue(): void
    {
        $done = 0;
        while (count($jobs = Queue::pushed(RunStep::class)) > $done) {
            foreach (array_slice($jobs->all(), $done) as $job) {
                $done++;
                app()->call([$job, 'handle']);
            }
        }
    }

    private function character(string $name): Character
    {
        $path = "assets/{$this->workspace->id}/".md5($name.microtime()).'.png';
        Storage::disk('public')->put($path, 'SHEET');
        $sheet = Asset::create(['workspace_id' => $this->workspace->id, 'type' => 'image', 'storage_path' => $path, 'mime' => 'image/png', 'size' => 5]);

        return Character::create(['workspace_id' => $this->workspace->id, 'name' => $name, 'style' => 'photorealistic',
            'appearance' => 'Wavy black hair', 'sheet_asset_id' => $sheet->id, 'sheet_status' => 'ready']);
    }

    private function textModel(): void
    {
        $claude = $this->credential('anthropic');
        $this->workspace->modelDefaults()->create(['task_type' => 'text', 'credential_id' => $claude->id, 'model' => 'claude-opus-5-5']);
    }

    private function credential(string $provider): AiCredential
    {
        $credential = new AiCredential(['provider' => $provider, 'label' => $provider, 'status' => 'valid']);
        $credential->workspace()->associate($this->workspace);
        $credential->setSecret('sk-test-'.$provider);
        $credential->save();

        return $credential;
    }
}
