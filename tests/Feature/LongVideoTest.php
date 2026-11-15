<?php

namespace Tests\Feature;

use App\Jobs\RunStep;
use App\Models\AiCredential;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Mp4Duration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LongVideoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    /** Scene number of each video job, by job id */
    private array $jobs = [];

    private int $postsWhenFirstPolled = 0;

    private array $failPollOnce = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        $this->user = User::factory()->create();
        $this->workspace = Workspace::createFor($this->user, 'Test');

        $claude = $this->credential('anthropic');
        $gemini = $this->credential('google');
        $this->workspace->modelDefaults()->create(['task_type' => 'text', 'credential_id' => $claude->id, 'model' => 'claude-opus-5-5']);
        $this->workspace->modelDefaults()->create(['task_type' => 'video', 'credential_id' => $gemini->id, 'model' => 'gemini-omni-1.1-flash']);
    }

    public function test_a_30_second_video_is_planned_as_scenes_generated_three_at_a_time_and_joined()
    {
        Process::fake(); // joining happens in PHP; ffmpeg must not be needed
        $maya = $this->character('Maya');
        $this->fakeApis(scenes: ['Maya finds a map.', 'Maya enters the jungle.', 'Maya reaches the temple.', 'Maya opens the glowing door.']);
        $this->failPollOnce = ['s2' => true]; // a network blip while checking scene 2 must not pay for it again

        $execution = $this->runVideo(['prompt_mode' => 'ai', 'brief' => 'Maya, an explorer, finds a glowing door in a jungle temple',
            'duration' => 30, 'aspect_ratio' => '16:9', 'character_ids' => [$maya->id]]);

        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        $output = $execution->steps->first()->output;

        // The writer planned one story in 4 scenes (30 s / 8 s), with the user's brief and cast.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic')
            && str_contains($r['system'], 'The video is made of 4 separate clips of about 8 seconds')
            && str_contains($r['messages'][0]['content'][1]['text'] ?? '', "BRIEF:\nMaya, an explorer"));

        // Every scene went to the video model with the character's picture and identity block, in story order.
        $posts = Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/interactions'));
        $this->assertCount(4, $posts, 'one paid generation per scene; the failed check was not re-submitted');
        foreach ($posts->values() as $i => [$request]) {
            $text = collect($request['input'])->firstWhere('type', 'text')['text'];
            $this->assertStringStartsWith('CHARACTER CONSISTENCY', $text);
            $this->assertStringContainsString('This is scene '.($i + 1).' of 4 of one continuous film.', $text);
            $this->assertSame('image', $request['input'][0]['type']);
            $this->assertSame('16:9', $request['response_format']['aspect_ratio']);
        }
        $this->assertSame(3, $this->postsWhenFirstPolled, 'at most 3 scenes run at once');

        // The four real clips (4 + 5 + 6 + 4 s) were joined, in order, into one MP4 without ffmpeg.
        Process::assertNothingRan();
        $joined = Storage::disk('public')->get(Asset::find($output['asset_id'])->storage_path);
        $this->assertEqualsWithDelta(19.0, Mp4Duration::seconds($joined), 0.01);
        $this->assertEqualsWithDelta(19.0, $output['duration_seconds'], 0.05);
        $this->assertSame(4, $output['scene_count']);
        $this->assertCount(4, $output['scenes']);
        $this->assertStringContainsString('Maya opens the glowing door.', $output['prompt_sent']);
        $this->assertNull($execution->steps->first()->progress);
    }

    public function test_clips_that_cannot_be_joined_fail_with_a_clear_message()
    {
        Process::fake(['*' => Process::result(exitCode: 127)]); // no ffmpeg to fall back on
        $this->fakeApis(scenes: ['a', 'b', 'c', 'd'], realClips: false);

        $execution = $this->runVideo(['prompt_mode' => 'ai', 'brief' => 'Anything', 'duration' => 30]);

        $this->assertSame('failed', $execution->status);
        $this->assertStringContainsString('Could not join the scenes', $execution->error);
    }

    public function test_one_clip_mode_is_unchanged()
    {
        Process::fake();
        $this->fakeApis(scenes: []);

        $execution = $this->runVideo(['prompt_mode' => 'manual', 'prompt' => 'A cat yawns']);

        $this->assertSame('succeeded', $execution->status, (string) $execution->error);
        $this->assertCount(1, Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/interactions')));
        $this->assertArrayNotHasKey('scene_count', $execution->steps->first()->output);
        Process::assertNothingRan();
    }

    /** Real H.264 + AAC clips as the video model's output, in turn: 4 s, 5 s, 6 s, 4 s... */
    private function fakeApis(array $scenes, bool $realClips = true): void
    {
        $clips = ['classic-4s', 'classic-5s', 'classic-6s'];
        Http::fake(function (Request $request) use ($scenes, $clips, $realClips) {
            if (str_contains($request->url(), 'anthropic')) {
                return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['scenes' => $scenes])]], 'usage' => []]);
            }
            if ($request->method() === 'POST') {
                $id = 's'.(count($this->jobs) + 1);
                $this->jobs[$id] = count($this->jobs) + 1;

                return Http::response(['id' => $id, 'status' => 'in_progress']);
            }
            $id = basename($request->url());
            $this->postsWhenFirstPolled = $this->postsWhenFirstPolled ?: count($this->jobs);
            if (! empty($this->failPollOnce[$id])) {
                unset($this->failPollOnce[$id]);

                return Http::response(['error' => ['message' => 'backend unavailable']], 503);
            }

            return Http::response(['status' => 'completed', 'steps' => [['type' => 'model_output',
                'content' => [['type' => 'video', 'mime_type' => 'video/mp4', 'data' => base64_encode($realClips
                    ? file_get_contents(base_path('tests/Fixtures/video/'.$clips[($this->jobs[$id] - 1) % 3].'.mp4'))
                    : 'CLIP-'.$this->jobs[$id])]]]]]);
        });
    }

    private function runVideo(array $data): Execution
    {
        $workflow = $this->workspace->workflows()->create(['name' => 'T', 'draft_graph' => ['nodes' => [
            ['id' => 'v', 'type' => 'ai.video', 'position' => ['x' => 0, 'y' => 0], 'data' => $data],
        ], 'edges' => []]]);
        $id = $this->actingAs($this->user)->postJson("/workflows/{$workflow->id}/run")->assertOk()->json('execution_id');

        // Run queued steps, including each re-check the waiting step schedules.
        $done = 0;
        while (count($jobs = Queue::pushed(RunStep::class)) > $done) {
            foreach (array_slice($jobs->all(), $done) as $job) {
                $done++;
                app()->call([$job, 'handle']);
            }
        }

        return Execution::with('steps')->find($id);
    }

    private function character(string $name): Character
    {
        $path = "assets/{$this->workspace->id}/{$name}.png";
        Storage::disk('public')->put($path, 'SHEET');
        $sheet = Asset::create(['workspace_id' => $this->workspace->id, 'type' => 'image', 'storage_path' => $path, 'mime' => 'image/png', 'size' => 5]);

        return Character::create(['workspace_id' => $this->workspace->id, 'name' => $name, 'style' => 'photorealistic',
            'appearance' => 'Short black hair, khaki explorer jacket', 'sheet_asset_id' => $sheet->id, 'sheet_status' => 'ready']);
    }

    private function credential(string $provider): AiCredential
    {
        $credential = new AiCredential(['provider' => $provider, 'label' => $provider, 'status' => 'valid']);
        $credential->workspace()->associate($this->workspace);
        $credential->setSecret('test-key-'.$provider.'-0000000000');
        $credential->save();

        return $credential;
    }
}
