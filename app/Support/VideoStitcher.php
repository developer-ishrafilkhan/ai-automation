<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/** Joins video clips, in order, into one MP4: in PHP when the clips match, else with ffmpeg if installed. */
class VideoStitcher
{
    public function __construct(private readonly Mp4Joiner $joiner) {}

    private ?string $binary = null;

    /** FFMPEG_PATH, else ffmpeg from PATH, else Homebrew's or the system's (queue workers may have a short PATH). */
    public function binary(): string
    {
        if ($this->binary !== null) {
            return $this->binary;
        }
        if ($configured = config('services.ffmpeg.path')) {
            return $this->binary = $configured;
        }
        foreach (['ffmpeg', '/opt/homebrew/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg'] as $candidate) {
            if ($this->runs($candidate)) {
                return $this->binary = $candidate;
            }
        }

        return $this->binary = 'ffmpeg';
    }

    public function available(): bool
    {
        return $this->runs($this->binary());
    }

    private function runs(string $binary): bool
    {
        try {
            return Process::timeout(10)->run([$binary, '-version'])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Join clips, in order, into one MP4 at $out. Clips from one model are joined losslessly in
     * PHP (no ffmpeg needed); ffmpeg, when installed, handles clips that don't match.
     *
     * @param  list<string>  $paths  absolute paths of the clips
     * @return float length in seconds
     */
    public function joinTo(array $paths, string $out): float
    {
        try {
            return $this->joiner->join($paths, $out);
        } catch (RuntimeException $e) {
            if (! $this->available()) {
                throw new RuntimeException('Could not join the scenes: '.$e->getMessage().' Installing ffmpeg (or setting FFMPEG_PATH) lets the app re-encode clips that don\'t match.');
            }
        }

        $this->ffmpegJoin($paths, $out);

        return Mp4Duration::seconds($this->head($out)) ?? 0.0;
    }

    private function ffmpegJoin(array $paths, string $out): void
    {
        $dir = storage_path('app/stitch/'.Str::uuid());
        File::ensureDirectoryExists($dir);
        $list = $dir.'/clips.txt';
        File::put($list, implode("\n", array_map(fn ($p) => "file '".str_replace("'", "'\\''", $p)."'", $paths))."\n");

        try {
            $copy = Process::timeout(300)->run([$this->binary(), '-y', '-v', 'error', '-f', 'concat', '-safe', '0', '-i', $list, '-c', 'copy', '-movflags', '+faststart', $out]);
            if (! $copy->successful() || ! is_file($out) || filesize($out) === 0) {
                $encode = Process::timeout(900)->run([$this->binary(), '-y', '-v', 'error', '-f', 'concat', '-safe', '0', '-i', $list,
                    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '160k', '-movflags', '+faststart', $out]);
                if (! $encode->successful() || ! is_file($out)) {
                    throw new RuntimeException('Could not join the scenes: '.Str::limit(trim($encode->errorOutput() ?: $copy->errorOutput()), 300));
                }
            }
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** The first few MB, where a fast-start MP4 keeps its header. */
    private function head(string $path): string
    {
        return (string) file_get_contents($path, false, null, 0, 8 * 1024 * 1024);
    }
}
