<?php

namespace Tests\Feature;

use App\Jobs\RunStep;
use App\Models\AiCredential;
use App\Models\Asset;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Logos, several people, products and video clips, each with the user's "how & where to use it" note. */
class ReferenceUsesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private AiCredential $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        $this->user = User::factory()->create();
        $this->workspace = Workspace::createFor($this->user, 'Test');
        $this->gemini = new AiCredential(['provider' => 'google', 'label' => 'g', 'status' => 'valid']);
        $this->gemini->workspace()->associate($this->workspace);
        $this->gemini->setSecret('AIzaGeminiKeyForTests000000');
        $this->gemini->save();
    }

    public function test_logo_people_and_product_references_each_get_their_role_and_the_users_note()
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('OUT')]]]]]]])]);

        $execution = $this->runNode('ai.image', ['prompt' => 'Team photo ad', 'references' => [
            ['src' => 'asset:'.$this->asset('LOGO')->id, 'role' => 'logo', 'note' => 'Top-left corner, small, white version'],
            ['src' => 'asset:'.$this->asset('RAHUL')->id, 'role' => 'person', 'note' => 'This is Rahul, standing on the left'],
            ['src' => 'asset:'.$this->asset('PRIYA')->id, 'role' => 'person', 'note' => 'This is Priya, seated on the right'],
            ['src' => 'asset:'.$this->asset('BOTTLE')->id, 'role' => 'product', 'note' => ''],
        ]]);

        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        Http::assertSent(function (Request $r) {
            $parts = $r['contents'][0]['parts'];
            $text = end($parts)['text'];

            return array_map(fn ($p) => base64_decode($p['inlineData']['data'] ?? ''), array_slice($parts, 0, -1)) === ['LOGO', 'RAHUL', 'PRIYA', 'BOTTLE']
                && str_contains($text, '• Reference image 1: LOGO. Place this exact logo in the design, unchanged')
                && str_contains($text, 'How and where to use it: "Top-left corner, small, white version"')
                && str_contains($text, '• Reference image 2: PERSON. This person must appear in the result.')
                && str_contains($text, '"This is Rahul, standing on the left"')
                && str_contains($text, '• Reference image 3: PERSON.')
                && str_contains($text, '"This is Priya, seated on the right"')
                && str_contains($text, '• Reference image 4: PRODUCT. Show this exact product unchanged');
        });
    }

    public function test_an_other_reference_follows_the_users_explanation_and_notes_reach_the_prompt_writer()
    {
        $claude = new AiCredential(['provider' => 'anthropic', 'label' => 'c', 'status' => 'valid']);
        $claude->workspace()->associate($this->workspace);
        $claude->setSecret('sk-ant-key');
        $claude->save();
        $this->workspace->modelDefaults()->create(['task_type' => 'text', 'credential_id' => $claude->id, 'model' => 'claude-opus-5-5']);
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Festive banner with the QR code bottom-right.']], 'usage' => []]),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('OUT')]]]]]]]),
        ]);

        $execution = $this->runNode('ai.image', ['prompt_mode' => 'ai', 'brief' => 'Diwali banner', 'references' => [
            ['src' => 'asset:'.$this->asset('QR')->id, 'role' => 'auto', 'note' => 'Our payment QR code: put it bottom-right, 15% of the width'],
        ]]);

        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic')
            && str_contains(collect($r['messages'][0]['content'])->last()['text'], '- reference image 1: Other (explain below) — the user says how and where to use it: "Our payment QR code: put it bottom-right, 15% of the width"')
            && str_contains($r['system'], 'Every PERSON, PRODUCT and LOGO reference must appear'));
        $this->assertStringContainsString('• Reference image 1: Use it exactly as the user explains: "Our payment QR code', $execution->steps->first()->output['prompt_sent']);
    }

    public function test_a_video_clip_is_sent_to_omni_as_a_video_part_to_edit()
    {
        Http::fake(['generativelanguage.googleapis.com/v1beta/interactions' => Http::response(['id' => 'v1', 'status' => 'in_progress'])]);
        $clip = $this->asset(self::mp4(6), 'video/mp4', 'video');

        $this->runNode('ai.video', ['model' => 'gemini-omni-1.1-flash', 'prompt' => 'Make the sky golden', 'references' => [
            ['src' => 'asset:'.$this->asset('LOGO')->id, 'role' => 'logo', 'note' => 'Bottom-right watermark'],
            ['src' => "asset:{$clip->id}", 'role' => 'edit_video', 'note' => 'Keep the music'],
        ]]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/interactions')
            && $r['input'][0]['type'] === 'image'
            && $r['input'][1] === ['type' => 'video', 'data' => base64_encode(self::mp4(6)), 'mime_type' => 'video/mp4']
            && str_contains($r['input'][2]['text'], '• <IMAGE_REF_0>: LOGO.')
            && str_contains($r['input'][2]['text'], '• The attached video clip: EDIT THIS CLIP. Keep everything in it exactly as it is')
            && str_contains($r['input'][2]['text'], '"Keep the music"'));
    }

    public function test_video_clip_rules_are_explained_instead_of_failing_at_the_provider()
    {
        Http::fake();
        $short = $this->asset(self::mp4(6), 'video/mp4', 'video');
        $long = $this->asset(self::mp4(15), 'video/mp4', 'video');
        $image = $this->asset('IMG');

        $cases = [
            ['ai.image', [['src' => "asset:{$short->id}", 'role' => 'edit_video']], 'only be used as references on Video nodes'],
            ['ai.video', [['src' => "asset:{$long->id}", 'role' => 'edit_video']], 'is 15 seconds long'],
            ['ai.video', [['src' => "asset:{$short->id}", 'role' => 'edit_video'], ['src' => "asset:{$short->id}", 'role' => 'extend_video']], 'one video clip per Video node'],
            ['ai.video', [['src' => "asset:{$short->id}", 'role' => 'logo']], 'Choose what to do with the video clip'],
            ['ai.video', [['src' => "asset:{$image->id}", 'role' => 'extend_video']], 'needs a video clip, not an image'],
        ];

        foreach ($cases as [$type, $references, $message]) {
            $execution = $this->runNode($type, ['model' => 'gemini-omni-1.1-flash', 'prompt' => 'x', 'references' => $references]);
            $this->assertSame('failed', $execution->status, $message);
            $this->assertStringContainsString($message, $execution->steps->first()->error);
            $this->assertSame(1, $execution->steps->first()->attempts, 'setup errors are not retried');
        }
        Http::assertNothingSent();
    }

    public function test_short_clips_can_be_uploaded_and_long_ones_are_refused()
    {
        $this->actingAs($this->user)
            ->post('/assets/upload', ['file' => UploadedFile::fake()->createWithContent('clip.mp4', self::mp4(8))], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('type', 'video');

        $this->post('/assets/upload', ['file' => UploadedFile::fake()->createWithContent('long.mp4', self::mp4(30))], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'This clip is 30 seconds long. Video references can be up to 10 seconds.');

        $this->assertSame(['video'], Asset::pluck('type')->all());
    }

    /** A minimal MP4 (ftyp + moov/mvhd) with the given duration. */
    public static function mp4(float $seconds): string
    {
        $ftyp = pack('N', 24).'ftyp'.'mp42'.pack('N', 0).'mp42isom';
        $mvhd = 'mvhd'.chr(0)."\0\0\0".pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', (int) ($seconds * 1000)).str_repeat("\0", 80);

        return $ftyp.pack('N', 12 + strlen($mvhd)).'moov'.pack('N', 4 + strlen($mvhd)).$mvhd;
    }

    private function runNode(string $type, array $data): Execution
    {
        $workflow = $this->workspace->workflows()->create(['name' => 'T', 'draft_graph' => ['nodes' => [
            ['id' => 'n', 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'data' => ['credential_id' => $this->gemini->id, 'model' => 'gemini-image'] + $data],
        ], 'edges' => []]]);
        $id = $this->actingAs($this->user)->postJson("/workflows/{$workflow->id}/run")->assertOk()->json('execution_id');
        foreach (Queue::pushed(RunStep::class) as $job) {
            app()->call([$job, 'handle']);
        }

        return Execution::with('steps')->find($id);
    }

    private function asset(string $bytes, string $mime = 'image/png', string $type = 'image'): Asset
    {
        $path = "assets/{$this->workspace->id}/".md5($bytes.microtime()).($type === 'video' ? '.mp4' : '.png');
        Storage::disk('public')->put($path, $bytes);

        return Asset::create(['workspace_id' => $this->workspace->id, 'type' => $type, 'storage_path' => $path, 'mime' => $mime, 'size' => strlen($bytes)]);
    }
}
