<?php

namespace App\Services\Engine\Nodes;

use App\Models\AiCredential;
use App\Models\Asset;
use App\Models\ExecutionStep;
use App\Services\Ai\ProviderException;
use App\Services\Ai\ProviderRegistry;
use App\Services\Ai\Providers\Provider;
use App\Services\Engine\PromptAssembler;
use App\Services\Engine\StepResult;
use App\Support\VideoStitcher;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * One clip: submit the job, park the step as "waiting", poll until the video is ready.
 *
 * A long video (data.duration = 30/60/90/120 seconds): the prompt writer splits the brief into
 * scenes of about 8 seconds, each scene is generated with the same characters and references
 * (a few at a time), and the clips are joined into one MP4 (in PHP; ffmpeg only as a fallback). Progress lives in the step's state, so
 * a restarted worker carries on without paying for a scene twice.
 */
class VideoNode implements NodeHandler
{
    use StoresAssets, UsesModels;

    public const DURATIONS = [30, 60, 90, 120];

    public const SECONDS_PER_SCENE = 8;

    private const POLL_SECONDS = 15;

    private const TIMEOUT_SECONDS = 1800;

    private const LONG_TIMEOUT_SECONDS = 5400;

    /** Scenes generated at the same time (video APIs rate-limit concurrent jobs). */
    private const CONCURRENCY = 3;

    private const SCENE_TRIES = 3;

    public function __construct(
        private readonly PromptAssembler $assembler,
        private readonly VideoStitcher $stitcher,
    ) {}

    public static function sceneCount(int $seconds): int
    {
        return (int) ceil($seconds / self::SECONDS_PER_SCENE);
    }

    public function handle(ExecutionStep $step, array $data, ?array $state): StepResult
    {
        $duration = in_array((int) ($data['duration'] ?? 0), self::DURATIONS, true) ? (int) $data['duration'] : 0;

        if ($state === null) {
            return $duration ? $this->startLong($step, $data, $duration) : $this->startClip($step, $data);
        }

        return ! empty($state['long']) ? $this->continueLong($step, $data, $state) : $this->pollClip($step, $state);
    }

    // ---- one clip -------------------------------------------------------------------------------

    private function startClip(ExecutionStep $step, array $data): StepResult
    {
        ['references' => $references, 'prompt' => $prompt, 'written_by' => $writtenBy] = $this->assembler->build($step->execution->workflow->workspace_id, $data, refStyle: 'omni');

        [$operation, $credential, $model] = $this->withModel($step, 'video', $data,
            fn (Provider $provider, string $key, string $model) => $provider->startVideo($key, $model, $prompt, $references, $this->aspectRatio($data)));

        return StepResult::wait([
            'prompt_sent' => $prompt,
            'prompt_written_by' => $writtenBy,
            'reference_count' => count($references),
            'operation' => $operation,
            'credential_id' => $credential->id,
            'model' => $model,
            'submitted_at' => now()->timestamp,
        ], self::POLL_SECONDS);
    }

    private function pollClip(ExecutionStep $step, array $state): StepResult
    {
        if (now()->timestamp - $state['submitted_at'] > self::TIMEOUT_SECONDS) {
            throw new ProviderException('Video generation timed out after 30 minutes.');
        }

        $credential = AiCredential::findOrFail($state['credential_id']);
        $file = app(ProviderRegistry::class)->for($credential->provider)->pollVideo($credential->secret(), $state['operation']);

        if ($file === null) {
            return StepResult::wait($state, self::POLL_SECONDS);
        }

        $asset = $this->storeAsset($step, 'video', $file);

        return StepResult::done([
            'asset_id' => $asset->id,
            'url' => $asset->url,
            'provider' => $credential->provider,
            'model' => $state['model'],
            'reference_count' => $state['reference_count'] ?? 0,
            'prompt_sent' => $state['prompt_sent'] ?? null,
            'prompt_written_by' => $state['prompt_written_by'] ?? null,
        ]);
    }

    // ---- long video -----------------------------------------------------------------------------

    private function startLong(ExecutionStep $step, array $data, int $duration): StepResult
    {
        $plan = $this->assembler->buildScenes($step->execution->workflow->workspace_id, $data, self::sceneCount($duration), self::SECONDS_PER_SCENE);

        $state = [
            'long' => true,
            'duration' => $duration,
            'prompt_written_by' => $plan['written_by'],
            'reference_count' => count($plan['references']),
            'submitted_at' => now()->timestamp,
            'scenes' => array_map(fn ($prompt) => ['prompt' => $prompt, 'status' => 'pending', 'tries' => 0], $plan['prompts']),
        ];

        return StepResult::wait($this->submitScenes($step, $data, $state, $plan['references']), self::POLL_SECONDS);
    }

    private function continueLong(ExecutionStep $step, array $data, array $state): StepResult
    {
        if (now()->timestamp - $state['submitted_at'] > self::LONG_TIMEOUT_SECONDS) {
            throw new ProviderException('The long video timed out after 90 minutes ('.$this->doneCount($state).' of '.count($state['scenes']).' scenes were ready).');
        }

        foreach ($state['scenes'] as $i => &$scene) {
            if ($scene['status'] !== 'running') {
                continue;
            }
            try {
                $credential = AiCredential::findOrFail($scene['credential_id']);
                $file = app(ProviderRegistry::class)->for($credential->provider)->pollVideo($credential->secret(), $scene['operation']);
                unset($scene['poll_errors']);
                if ($file !== null) {
                    $asset = $this->storeAsset($step, 'video', $file);
                    $asset->update(['tags' => [$step->node_id, 'scene-'.($i + 1)]]);
                    $scene = ['status' => 'done', 'asset_id' => $asset->id] + $scene;
                    unset($scene['operation'], $file); // free this clip before checking the next
                }
            } catch (Throwable $e) {
                // A failed check is often a network blip: keep the job, and give up on it (paying
                // for a new one) only after several failed checks in a row.
                $scene['poll_errors'] = ($scene['poll_errors'] ?? 0) + 1;
                if ($scene['poll_errors'] >= 3) {
                    unset($scene['poll_errors']);
                    $this->sceneFailed($scene, $i, $e);
                }
            }
        }
        unset($scene);

        if ($this->doneCount($state) < count($state['scenes'])) {
            return StepResult::wait($this->submitScenes($step, $data, $state), self::POLL_SECONDS);
        }

        return $this->finishLong($step, $state);
    }

    /** Start pending scenes until CONCURRENCY are running. References are loaded only if needed. */
    private function submitScenes(ExecutionStep $step, array $data, array $state, ?array $references = null): array
    {
        $running = count(array_filter($state['scenes'], fn ($s) => $s['status'] === 'running'));

        foreach ($state['scenes'] as $i => &$scene) {
            if ($running >= self::CONCURRENCY) {
                break;
            }
            if ($scene['status'] !== 'pending') {
                continue;
            }
            $references ??= $this->assembler->references($step->execution->workflow->workspace_id, $data);
            try {
                [$operation, $credential, $model] = $this->withModel($step, 'video', $data,
                    fn (Provider $provider, string $key, string $model) => $provider->startVideo($key, $model, $scene['prompt'], $references, $this->aspectRatio($data)));
                $scene = ['status' => 'running', 'operation' => $operation, 'credential_id' => $credential->id, 'model' => $model] + $scene;
                $running++;
            } catch (Throwable $e) {
                $this->sceneFailed($scene, $i, $e);
            }
        }

        return $state;
    }

    /** A scene that keeps failing (e.g. blocked by the provider's safety filter) fails the step. */
    private function sceneFailed(array &$scene, int $index, Throwable $e): void
    {
        $scene['tries'] = ($scene['tries'] ?? 0) + 1;
        $scene['status'] = 'pending';
        $scene['error'] = mb_substr($e->getMessage(), 0, 300);
        unset($scene['operation']);

        if ($scene['tries'] >= self::SCENE_TRIES) {
            throw new ProviderException('Scene '.($index + 1)." failed {$scene['tries']} times: {$scene['error']}");
        }
    }

    private function finishLong(ExecutionStep $step, array $state): StepResult
    {
        $assets = Asset::whereIn('id', array_column($state['scenes'], 'asset_id'))->get()->keyBy('id');
        $clips = array_map(fn ($s) => $assets[$s['asset_id']], $state['scenes']);

        // Join straight into storage, so a 2-minute video is never held in memory.
        $path = 'assets/'.$step->execution->workflow->workspace_id.'/'.Str::uuid().'.mp4';
        $disk = Storage::disk('public');
        $disk->makeDirectory(dirname($path));
        $seconds = $this->stitcher->joinTo(array_map(fn (Asset $a) => $disk->path($a->storage_path), $clips), $disk->path($path));
        $asset = Asset::create([
            'workspace_id' => $step->execution->workflow->workspace_id,
            'execution_id' => $step->execution_id,
            'type' => 'video',
            'storage_path' => $path,
            'mime' => 'video/mp4',
            'size' => filesize($disk->path($path)),
            'tags' => [$step->node_id],
        ]);
        $first = $state['scenes'][0];

        return StepResult::done([
            'asset_id' => $asset->id,
            'url' => $asset->url,
            'provider' => AiCredential::find($first['credential_id'])?->provider,
            'model' => $first['model'],
            'duration_seconds' => round($seconds, 1),
            'scene_count' => count($clips),
            'scenes' => array_map(fn (Asset $a) => ['asset_id' => $a->id, 'url' => $a->url], $clips),
            'reference_count' => $state['reference_count'],
            'prompt_sent' => implode("\n\n---\n\n", array_column($state['scenes'], 'prompt')),
            'prompt_written_by' => $state['prompt_written_by'],
        ]);
    }

    private function doneCount(array $state): int
    {
        return count(array_filter($state['scenes'], fn ($s) => $s['status'] === 'done'));
    }

    private function aspectRatio(array $data): string
    {
        return in_array($data['aspect_ratio'] ?? null, ['9:16', '16:9'], true) ? $data['aspect_ratio'] : '9:16'; // matches the editor's default
    }
}
