<?php

namespace Tests\Feature;

use App\Support\Mp4Duration;
use App\Support\Mp4Joiner;
use RuntimeException;
use Tests\TestCase;

/** Joins real H.264 + AAC clips (tests/Fixtures/video) without ffmpeg. */
class Mp4JoinerTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = tempnam(sys_get_temp_dir(), 'joined').'.mp4';
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
        parent::tearDown();
    }

    public function test_classic_clips_join_frame_exact_with_one_edit_per_clip()
    {
        $seconds = (new Mp4Joiner)->join($this->clips('classic-4s', 'classic-5s', 'classic-6s'), $this->out);

        $this->assertEqualsWithDelta(15.0, $seconds, 0.01);
        $this->assertEqualsWithDelta(15.0, Mp4Duration::seconds(file_get_contents($this->out)), 0.01);
        $tables = $this->tables($this->out);
        $this->assertSame([360, 3], [$tables[0]['samples'], count($tables[0]['edits'])], 'every frame of 4+5+6 s at 24 fps; one edit per clip');
        // Sound: each clip keeps skipping its own 2112 AAC start-up samples, so nothing drifts.
        $this->assertSame(2112, $tables[1]['edits'][0][1]);
        $this->assertSame($this->audioSamples('classic-4s') + 2112, $tables[1]['edits'][1][1]);
        // Picture and sound of every clip have the same length, so clip 2 starts at the same moment in both.
        $this->assertSame(array_column($tables[0]['edits'], 0), array_column($tables[1]['edits'], 0));
    }

    public function test_fragmented_clips_become_one_classic_web_ready_file()
    {
        $seconds = (new Mp4Joiner)->join($this->clips('fragmented-4s', 'fragmented-5s', 'fragmented-6s'), $this->out);

        $this->assertEqualsWithDelta(15.0, $seconds, 0.01);
        $boxes = $this->topLevel($this->out);
        $this->assertSame(['ftyp', 'moov', 'mdat'], $boxes, 'fast start: header first, no fragments');
        $tables = $this->tables($this->out);
        $this->assertSame(360, $tables[0]['samples']);
        // Fragmented sound runs a bit longer than the picture; it is cut so the next clip starts in sync.
        $this->assertSame(array_column($tables[0]['edits'], 0), array_column($tables[1]['edits'], 0));
    }

    public function test_mixed_layouts_and_a_single_clip()
    {
        $this->assertEqualsWithDelta(15.0, (new Mp4Joiner)->join($this->clips('classic-4s', 'fragmented-5s', 'classic-6s'), $this->out), 0.05);
        $this->assertEqualsWithDelta(4.0, (new Mp4Joiner)->join($this->clips('classic-4s'), $this->out), 0.01);
    }

    public function test_files_that_are_not_mp4_are_refused()
    {
        $junk = tempnam(sys_get_temp_dir(), 'junk');
        file_put_contents($junk, 'not a video at all');

        $this->expectException(RuntimeException::class);
        try {
            (new Mp4Joiner)->join([$junk], $this->out);
        } finally {
            unlink($junk);
        }
    }

    private function clips(string ...$names): array
    {
        return array_map(fn ($n) => base_path("tests/Fixtures/video/{$n}.mp4"), $names);
    }

    private function topLevel(string $path): array
    {
        $b = file_get_contents($path);
        $types = [];
        for ($o = 0; $o + 8 <= strlen($b);) {
            $size = unpack('N', substr($b, $o, 4))[1];
            $size = $size === 1 ? unpack('J', substr($b, $o + 8, 8))[1] : $size;
            $types[] = substr($b, $o + 4, 4);
            $o += $size;
        }

        return $types;
    }

    /** Per track (in order): sample count (stsz) and edit list entries [length, media time]. */
    private function tables(string $path): array
    {
        $b = file_get_contents($path);
        $tracks = [];
        foreach (explode('trak', $b) as $i => $part) {
            if ($i === 0 || ! str_contains($part, 'stsz')) {
                continue;
            }
            $stsz = strpos($part, 'stsz');
            $elst = strpos($part, 'elst');
            $edits = [];
            for ($e = 0, $n = unpack('N', substr($part, $elst + 8, 4))[1]; $e < $n; $e++) {
                $at = $elst + 12 + 12 * $e;
                $edits[] = [unpack('N', substr($part, $at, 4))[1], unpack('l', strrev(substr($part, $at + 4, 4)))[1]];
            }
            $tracks[] = ['samples' => unpack('N', substr($part, $stsz + 12, 4))[1], 'edits' => $edits];
        }

        return $tracks;
    }

    private function audioSamples(string $clip): int
    {
        // Media samples in the clip's sound track = sum of its sample durations (stts).
        $b = file_get_contents(base_path("tests/Fixtures/video/{$clip}.mp4"));
        $soun = strpos($b, 'soun');
        $stts = strpos($b, 'stts', $soun);
        $total = 0;
        for ($i = 0, $n = unpack('N', substr($b, $stts + 8, 4))[1]; $i < $n; $i++) {
            $total += unpack('N', substr($b, $stts + 12 + 8 * $i, 4))[1] * unpack('N', substr($b, $stts + 16 + 8 * $i, 4))[1];
        }

        return $total;
    }
}
