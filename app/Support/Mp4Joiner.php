<?php

namespace App\Support;

use RuntimeException;

/**
 * Joins MP4 clips end to end without re-encoding and without ffmpeg.
 *
 * Clips from one video model share their codecs, so their frames are copied as they are: each
 * clip's sample tables (read from a classic moov, or rebuilt from the moof fragments of a
 * fragmented MP4) are merged into one, the media data is appended, and every clip keeps its
 * own edit (e.g. the AAC encoder's start-up samples are skipped at every join, so sound never
 * drifts from the picture). The result is a classic, web-optimised ("fast start") MP4.
 *
 * Clips with different track layouts (e.g. one without sound) can't be joined this way.
 */
class Mp4Joiner
{
    private const CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'stbl', 'edts', 'mvex', 'moof', 'traf'];

    /**
     * @param  list<string>  $paths  clips, in order
     * @return float length of the joined video in seconds
     */
    public function join(array $paths, string $out): float
    {
        if ($paths === []) {
            throw new RuntimeException('No clips to join.');
        }
        $files = array_map(fn ($p) => $this->open($p), $paths);

        try {
            return $this->joinClassic($files, $out);
        } finally {
            foreach ($files as $file) {
                fclose($file['handle']);
            }
        }
    }

    // ---- reading -------------------------------------------------------------------------------

    /** Top-level boxes of a file; only ftyp and moov are read into memory. */
    private function open(string $path): array
    {
        $handle = fopen($path, 'rb') ?: throw new RuntimeException("Cannot read {$path}.");
        $length = filesize($path);
        $top = [];
        $offset = 0;
        while ($offset + 8 <= $length) {
            fseek($handle, $offset);
            $header = fread($handle, 16);
            [$size, $type, $headerSize] = $this->header($header, 0, $length - $offset);
            $top[] = ['type' => $type, 'start' => $offset, 'size' => $size, 'header' => $headerSize];
            $offset += $size;
        }

        $moov = $this->first($top, 'moov') ?? throw new RuntimeException('A clip has no MP4 header (moov box).');
        $ftyp = $this->first($top, 'ftyp');

        return [
            'handle' => $handle,
            'top' => $top,
            'ftyp' => $ftyp ? $this->read($handle, $ftyp['start'], $ftyp['size']) : null,
            'moov' => $this->read($handle, $moov['start'], $moov['size']),
            'fragmented' => (bool) array_filter($top, fn ($b) => $b['type'] === 'moof'),
        ];
    }

    /** @return array{0: int, 1: string, 2: int} size, type, header size */
    private function header(string $bytes, int $at, int $remaining): array
    {
        $size = unpack('N', substr($bytes, $at, 4))[1];
        $type = substr($bytes, $at + 4, 4);
        $headerSize = 8;
        if ($size === 1) {
            $size = unpack('J', substr($bytes, $at + 8, 8))[1];
            $headerSize = 16;
        } elseif ($size === 0) {
            $size = $remaining;
        }
        if ($size < $headerSize || $size > $remaining) {
            throw new RuntimeException("Damaged MP4 box \"{$type}\".");
        }

        return [$size, $type, $headerSize];
    }

    /** Child boxes of a box held in memory: list of [type, start, size, headerSize]. */
    private function children(string $bytes, int $start, int $end): array
    {
        $list = [];
        while ($start + 8 <= $end) {
            [$size, $type, $headerSize] = $this->header($bytes, $start, $end - $start);
            $list[] = ['type' => $type, 'start' => $start, 'size' => $size, 'header' => $headerSize];
            $start += $size;
        }

        return $list;
    }

    /** Payload of the first descendant at $path, e.g. "mdia/minf/stbl/stsz". */
    private function find(string $bytes, string $path, int $start = 0, ?int $end = null): ?string
    {
        $end ??= strlen($bytes);
        [$head, $rest] = array_pad(explode('/', $path, 2), 2, null);
        foreach ($this->children($bytes, $start, $end) as $box) {
            if ($box['type'] === $head) {
                $from = $box['start'] + $box['header'];
                $to = $box['start'] + $box['size'];

                return $rest === null ? substr($bytes, $from, $to - $from) : $this->find($bytes, $rest, $from, $to);
            }
        }

        return null;
    }

    private function first(array $boxes, string $type): ?array
    {
        foreach ($boxes as $box) {
            if ($box['type'] === $type) {
                return $box;
            }
        }

        return null;
    }

    private function read($handle, int $offset, int $length): string
    {
        fseek($handle, $offset);
        $data = '';
        while (strlen($data) < $length && ! feof($handle)) {
            $data .= fread($handle, $length - strlen($data));
        }

        return $data;
    }

    private function u32(string $b, int $at): int
    {
        return unpack('N', substr($b, $at, 4))[1];
    }

    private function u64(string $b, int $at): int
    {
        return unpack('J', substr($b, $at, 8))[1];
    }

    private function s32(string $b, int $at): int
    {
        $v = $this->u32($b, $at);

        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    // ---- classic MP4 -------------------------------------------------------------------------------

    private function joinClassic(array $files, string $out): float
    {
        $parsed = array_map(fn ($f) => $f['fragmented'] ? $this->parseFragmented($f) : $this->parseClassic($f), $files);
        $layout = array_map(fn ($t) => $t['handler'], $parsed[0]['tracks']);
        foreach ($parsed as $i => $p) {
            if (array_map(fn ($t) => $t['handler'], $p['tracks']) !== $layout) {
                throw new RuntimeException('Clip '.($i + 1).' has different tracks (for example video only vs video with sound).');
            }
        }

        // New media data = every clip's mdat payloads, in order.
        $segments = [];
        $position = 0;
        foreach ($files as $i => $file) {
            foreach ($file['top'] as $box) {
                if ($box['type'] === 'mdat') {
                    $from = $box['start'] + $box['header'];
                    $length = $box['size'] - $box['header'];
                    $segments[] = ['file' => $i, 'from' => $from, 'length' => $length, 'at' => $position];
                    $position += $length;
                }
            }
        }
        $mdatLength = $position;
        $moved = function (int $file, int $offset) use ($segments): int {
            foreach ($segments as $s) {
                if ($s['file'] === $file && $offset >= $s['from'] && $offset < $s['from'] + $s['length']) {
                    return $s['at'] + ($offset - $s['from']);
                }
            }
            throw new RuntimeException('A clip points at media data outside its mdat box.');
        };

        $movieTimescale = $parsed[0]['movie_timescale'];

        // Every track of a clip starts and ends together: the clip lasts as long as its picture.
        // Sound that runs a little longer is cut and shorter sound is padded, so a clip's sound
        // can never push the next clip's sound out of sync.
        $video = array_search('vide', $layout, true);
        $clipLengths = array_map(function ($p) use ($video, $movieTimescale) {
            $lengths = array_map(fn ($t) => $this->presentedLength($t, $movieTimescale), $p['tracks']);

            return $video !== false ? $lengths[$video] : min($lengths);
        }, $parsed);

        $tracks = [];
        foreach ($layout as $t => $handler) {
            $tracks[] = $this->mergeTrack(array_map(fn ($p) => $p['tracks'][$t], $parsed), $moved, $movieTimescale, $clipLengths);
        }

        $ftyp = $files[0]['ftyp'] ?? $this->box('ftyp', 'isom'.pack('N', 512).'isomiso2avc1mp41');
        $mdatHeader = $mdatLength + 8 > 0xFFFFFFFF ? 16 : 8;
        // Offsets don't change the size of the moov box, so build it once to measure, then for real.
        $moovSize = strlen($this->classicMoov($files[0]['moov'], $tracks, $movieTimescale, 0));
        $base = strlen($ftyp) + $moovSize + $mdatHeader;
        $moov = $this->classicMoov($files[0]['moov'], $tracks, $movieTimescale, $base);

        $output = fopen($out, 'wb') ?: throw new RuntimeException('Cannot write the joined video.');
        fwrite($output, $ftyp.$moov);
        fwrite($output, $mdatHeader === 8 ? pack('N', $mdatLength + 8).'mdat' : pack('N', 1).'mdat'.pack('J', $mdatLength + 16));
        foreach ($segments as $s) {
            fseek($files[$s['file']]['handle'], $s['from']);
            stream_copy_to_stream($files[$s['file']]['handle'], $output, $s['length']);
        }
        fclose($output);

        return max(array_column($tracks, 'movie_duration')) / $movieTimescale;
    }

    private function parseClassic(array $file): array
    {
        $moov = $file['moov'];
        $inner = $this->children($moov, 8, strlen($moov));
        $mvhd = $this->find($moov, 'moov/mvhd');
        $tracks = [];
        foreach ($inner as $box) {
            if ($box['type'] !== 'trak') {
                continue;
            }
            $trak = substr($moov, $box['start'], $box['size']);
            $stbl = 'trak/mdia/minf/stbl/';
            $mdhd = $this->find($trak, 'trak/mdia/mdhd');
            $hdlr = $this->find($trak, 'trak/mdia/hdlr');
            $stsz = $this->find($trak, $stbl.'stsz') ?? throw new RuntimeException('Unsupported MP4 sample table (stz2).');
            $chunks = $this->find($trak, $stbl.'stco');
            $wide = $chunks === null;
            $chunks ??= $this->find($trak, $stbl.'co64') ?? throw new RuntimeException('A clip has no chunk offsets.');

            $sampleCount = $this->u32($stsz, 8);
            $constant = $this->u32($stsz, 4);
            $ctts = $this->find($trak, $stbl.'ctts');
            $stss = $this->find($trak, $stbl.'stss');
            $elst = $this->find($trak, 'trak/edts/elst');

            $tracks[] = [
                'trak' => $trak,
                'handler' => substr($hdlr, 8, 4),
                'timescale' => $mdhd[0] === "\1" ? $this->u32($mdhd, 20) : $this->u32($mdhd, 12),
                'stsd' => $this->stsdEntries($this->find($trak, $stbl.'stsd')),
                'stts' => $this->pairs($this->find($trak, $stbl.'stts'), signed: false),
                'ctts' => $ctts === null ? null : $this->pairs($ctts, signed: $ctts[0] === "\1"),
                'stss' => $stss === null ? null : array_values(unpack('N*', substr($stss, 8, 4 * $this->u32($stss, 4))) ?: []),
                'stsc' => $this->triples($this->find($trak, $stbl.'stsc')),
                'edits' => $elst === null ? null : $this->edits($elst),
                'sizes' => $constant ? array_fill(0, $sampleCount, $constant) : array_values(unpack('N*', substr($stsz, 12, 4 * $sampleCount)) ?: []),
                'sample_count' => $sampleCount,
                'chunks' => $wide
                    ? array_map(fn ($i) => $this->u64($chunks, 8 + 8 * $i), range(0, $this->u32($chunks, 4) - 1))
                    : array_values(unpack('N*', substr($chunks, 8, 4 * $this->u32($chunks, 4))) ?: []),
            ];
        }

        $movieTimescale = $mvhd[0] === "\1" ? $this->u32($mvhd, 20) : $this->u32($mvhd, 12);
        foreach ($tracks as &$track) {
            $track['movie_timescale'] = $movieTimescale;
        }

        return ['movie_timescale' => $movieTimescale, 'tracks' => $tracks];
    }

    /** One track's tables from every clip, merged into one. */
    private function mergeTrack(array $parts, callable $moved, int $movieTimescale, array $clipLengths): array
    {
        $timescale = $parts[0]['timescale'];
        $merged = ['trak' => $parts[0]['trak'], 'timescale' => $timescale, 'stsd' => [], 'stts' => [], 'ctts' => [], 'stss' => [], 'stsc' => [], 'sizes' => [], 'chunks' => [], 'duration' => 0, 'edits' => []];
        $anyCtts = (bool) array_filter($parts, fn ($p) => $p['ctts'] !== null);
        $anyStss = (bool) array_filter($parts, fn ($p) => $p['stss'] !== null);
        $samples = 0;

        foreach ($parts as $file => $part) {
            // Sample descriptions: reuse identical ones, add the rest.
            $map = [];
            foreach ($part['stsd'] as $i => $entry) {
                $found = array_search($entry, $merged['stsd'], true);
                if ($found === false) {
                    $merged['stsd'][] = $entry;
                    $found = count($merged['stsd']) - 1;
                }
                $map[$i + 1] = $found + 1;
            }

            // Times are kept in the first clip's timescale.
            $scale = $part['timescale'] === $timescale ? 1.0 : $timescale / $part['timescale'];

            // Each clip keeps its own edit (e.g. skipping the AAC encoder's start-up samples), so
            // sound and picture stay in sync at every join instead of drifting a little each time.
            $startsAt = $merged['duration'];
            $remaining = $clipLengths[$file];
            foreach ($this->clipEdits($part, $movieTimescale) as [$length, $mediaTime]) {
                $length = min($length, $remaining);
                if ($length > 0) {
                    $merged['edits'][] = [$length, $mediaTime < 0 ? -1 : $startsAt + (int) round($mediaTime * $scale)];
                    $remaining -= $length;
                }
            }
            if ($remaining > 0) {
                $merged['edits'][] = [$remaining, -1]; // an empty edit: this track is silent/still for the rest of the clip
            }
            foreach ($part['stts'] as [$count, $delta]) {
                $merged['stts'][] = [$count, (int) round($delta * $scale)];
                $merged['duration'] += $count * (int) round($delta * $scale);
            }
            if ($anyCtts) {
                foreach ($part['ctts'] ?? [[$part['sample_count'], 0]] as [$count, $offset]) {
                    $merged['ctts'][] = [$count, (int) round($offset * $scale)];
                }
            }
            if ($anyStss) {
                foreach ($part['stss'] ?? range(1, max(1, $part['sample_count'])) as $number) {
                    $merged['stss'][] = $number + $samples;
                }
            }
            foreach ($part['stsc'] as [$firstChunk, $perChunk, $description]) {
                $merged['stsc'][] = [$firstChunk + count($merged['chunks']), $perChunk, $map[$description] ?? 1];
            }
            foreach ($part['chunks'] as $offset) {
                $merged['chunks'][] = $moved($file, $offset);
            }
            array_push($merged['sizes'], ...$part['sizes']);
            $samples += $part['sample_count'];
        }

        $merged['stss'] = $anyStss ? $merged['stss'] : null;
        $merged['ctts'] = $anyCtts ? $merged['ctts'] : null;
        $merged['movie_duration'] = array_sum(array_column($merged['edits'], 0));

        return $merged;
    }

    private function classicMoov(string $moov, array $tracks, int $movieTimescale, int $base): string
    {
        $trackIndex = 0;
        $duration = max(array_column($tracks, 'movie_duration'));

        return $this->rebuild($moov, function (string $type, string $payload, array $path) use ($tracks, &$trackIndex, $duration, $base) {
            $track = $tracks[$trackIndex] ?? null;

            return match ($type) {
                'mvhd' => $this->setDuration($payload, 12, $duration),
                'tkhd' => $this->setDuration($payload, 16, $track['movie_duration']),
                'mdhd' => $this->setDuration($payload, 12, $track['duration']),
                'elst' => $this->editListBox($track['edits']),
                'stsd' => pack('N', 0).pack('N', count($track['stsd'])).implode('', $track['stsd']),
                'stts' => $this->pairsBox($track['stts'], 0),
                'ctts' => $track['ctts'] === null ? null : $this->pairsBox($track['ctts'], min(array_column($track['ctts'], 1)) < 0 ? 1 : 0),
                'stss' => $track['stss'] === null ? null : pack('NN', 0, count($track['stss'])).pack('N*', ...$track['stss']),
                'stsc' => pack('NN', 0, count($track['stsc'])).implode('', array_map(fn ($e) => pack('NNN', ...$e), $track['stsc'])),
                'stsz' => pack('NNN', 0, 0, count($track['sizes'])).($track['sizes'] ? pack('N*', ...$track['sizes']) : ''),
                'stco', 'co64' => $this->chunkBox($track['chunks'], $base),
                // Tables that number samples and would no longer match.
                'sdtp', 'sgpd', 'sbgp', 'stps', 'subs' => null,
                default => $payload,
            };
        }, afterTrak: function () use (&$trackIndex) {
            $trackIndex++;
        });
    }

    /** Rewrite a box tree: $leaf returns a new payload for a leaf box, or null to drop it. */
    private function rebuild(string $bytes, callable $leaf, callable $afterTrak, array $path = []): string
    {
        [$size, $type, $headerSize] = $this->header($bytes, 0, strlen($bytes));
        if ($type === 'mvex') {
            return ''; // the joined file is not fragmented
        }
        $payload = '';
        if (in_array($type, self::CONTAINERS, true)) {
            foreach ($this->children($bytes, $headerSize, $size) as $child) {
                $payload .= $this->rebuild(substr($bytes, $child['start'], $child['size']), $leaf, $afterTrak, [...$path, $type]);
            }
            // A merged track may need a table its first clip didn't have.
            if ($type === 'stbl') {
                $payload .= $this->missingTables($bytes, $headerSize, $size, $leaf);
            }
            // Every merged track gets an edit list (one edit per clip), right after its tkhd.
            if ($type === 'trak' && ! in_array('edts', array_column($this->children($bytes, $headerSize, $size), 'type'), true)) {
                $tkhd = $this->children($payload, 0, strlen($payload))[0];
                $edts = $this->box('edts', $this->box('elst', $leaf('elst', '', [])));
                $payload = substr($payload, 0, $tkhd['size']).$edts.substr($payload, $tkhd['size']);
            }
            $box = $this->box($type, $payload);
            if ($type === 'trak') {
                $afterTrak();
            }

            return $box;
        }

        $new = $leaf($type, substr($bytes, $headerSize, $size - $headerSize), $path);
        if ($new === null) {
            return '';
        }
        if ($type === 'stco' || $type === 'co64') {
            // chunkBox() writes 8-byte offsets only when the file is over 4 GB.
            $count = $this->u32($new, 4);

            return $this->box($count > 0 && (strlen($new) - 8) === 8 * $count ? 'co64' : 'stco', $new);
        }

        return $this->box($type, $new);
    }

    private function missingTables(string $stbl, int $from, int $to, callable $leaf): string
    {
        $present = array_column($this->children($stbl, $from, $to), 'type');
        $extra = '';
        foreach (['ctts', 'stss'] as $type) {
            if (! in_array($type, $present, true) && ($payload = $leaf($type, '', [])) !== null) {
                $extra .= $this->box($type, $payload);
            }
        }

        return $extra;
    }

    private function chunkBox(array $chunks, int $base): string
    {
        $absolute = array_map(fn ($c) => $c + $base, $chunks);
        if ($absolute && max($absolute) > 0xFFFFFFFF) {
            return pack('NN', 0, count($absolute)).implode('', array_map(fn ($c) => pack('J', $c), $absolute));
        }

        return pack('NN', 0, count($absolute)).($absolute ? pack('N*', ...$absolute) : '');
    }

    /** Duration fields: v0 = 32-bit at $v0At, v1 = 64-bit 4 bytes later (after two 64-bit dates). */
    private function setDuration(string $payload, int $v0At, int $duration): string
    {
        if ($payload[0] === "\1") {
            // v1: flags(4) creation(8) modification(8), then tkhd: track_ID(4) reserved(4); mvhd/mdhd: timescale(4)
            $at = $v0At === 16 ? 28 : 24;

            return substr_replace($payload, pack('J', $duration), $at, 8);
        }

        return substr_replace($payload, pack('N', min($duration, 0xFFFFFFFF)), $v0At + 4, 4);
    }

    /** A clip's edits for one track, with lengths in the joined file's movie timescale. */
    private function clipEdits(array $part, int $movieTimescale): array
    {
        $movieScale = $movieTimescale / $part['movie_timescale'];
        $mediaLength = array_sum(array_map(fn ($e) => $e[0] * $e[1], $part['stts']));
        $edits = $part['edits'] ?? [[(int) round($mediaLength * $part['movie_timescale'] / $part['timescale']), 0]];

        return array_map(fn ($e) => [(int) round($e[0] * $movieScale), $e[1]], $edits);
    }

    /** How long a track of a clip plays, in the joined file's movie timescale. */
    private function presentedLength(array $part, int $movieTimescale): int
    {
        return array_sum(array_column($this->clipEdits($part, $movieTimescale), 0));
    }

    /** @return list<array{0: int, 1: int}> [segment duration (movie timescale), media time (-1 = empty)] */
    private function edits(string $elst): array
    {
        $wide = $elst[0] === "\1";
        $size = $wide ? 20 : 12;
        $list = [];
        for ($i = 0, $n = $this->u32($elst, 4); $i < $n; $i++) {
            $at = 8 + $i * $size;
            $list[] = $wide
                ? [$this->u64($elst, $at), unpack('q', strrev(substr($elst, $at + 8, 8)))[1]]
                : [$this->u32($elst, $at), $this->s32($elst, $at + 4)];
        }

        return $list;
    }

    private function editListBox(array $edits): string
    {
        $wide = (bool) array_filter($edits, fn ($e) => $e[0] > 0xFFFFFFFF || $e[1] > 0x7FFFFFFF);
        $entries = '';
        foreach ($edits as [$length, $mediaTime]) {
            $entries .= $wide
                ? pack('J', $length).strrev(pack('q', $mediaTime)).pack('N', 0x00010000)
                : pack('N', $length).pack('N', $mediaTime & 0xFFFFFFFF).pack('N', 0x00010000);
        }

        return pack('NN', ($wide ? 1 : 0) << 24, count($edits)).$entries;
    }

    private function stsdEntries(string $stsd): array
    {
        $entries = [];
        foreach ($this->children($stsd, 8, strlen($stsd)) as $box) {
            $entries[] = substr($stsd, $box['start'], $box['size']);
        }

        return $entries;
    }

    private function pairs(string $payload, bool $signed): array
    {
        $list = [];
        for ($i = 0, $n = $this->u32($payload, 4); $i < $n; $i++) {
            $list[] = [$this->u32($payload, 8 + 8 * $i), $signed ? $this->s32($payload, 12 + 8 * $i) : $this->u32($payload, 12 + 8 * $i)];
        }

        return $list;
    }

    private function triples(string $payload): array
    {
        $list = [];
        for ($i = 0, $n = $this->u32($payload, 4); $i < $n; $i++) {
            $list[] = [$this->u32($payload, 8 + 12 * $i), $this->u32($payload, 12 + 12 * $i), $this->u32($payload, 16 + 12 * $i)];
        }

        return $list;
    }

    private function pairsBox(array $pairs, int $version): string
    {
        return pack('NN', $version << 24, count($pairs)).implode('', array_map(fn ($p) => pack('N', $p[0]).pack('N', $p[1] & 0xFFFFFFFF), $pairs));
    }

    private function box(string $type, string $payload): string
    {
        return strlen($payload) + 8 > 0xFFFFFFFF
            ? pack('N', 1).$type.pack('J', strlen($payload) + 16).$payload
            : pack('N', strlen($payload) + 8).$type.$payload;
    }

    // ---- fragmented MP4 ---------------------------------------------------------------------------

    /** Rebuild classic sample tables from a fragmented MP4's moof boxes (one chunk per trun). */
    private function parseFragmented(array $file): array
    {
        $moov = $file['moov'];
        $mvhd = $this->find($moov, 'moov/mvhd');
        $movieTimescale = $mvhd[0] === "\1" ? $this->u32($mvhd, 20) : $this->u32($mvhd, 12);

        $trex = [];
        $mvex = $this->find($moov, 'moov/mvex') ?? '';
        foreach ($this->children($mvex, 0, strlen($mvex)) as $box) {
            if ($box['type'] === 'trex') {
                $p = substr($mvex, $box['start'] + 8, $box['size'] - 8);
                $trex[$this->u32($p, 4)] = ['sdi' => $this->u32($p, 8), 'duration' => $this->u32($p, 12), 'size' => $this->u32($p, 16), 'flags' => $this->u32($p, 20)];
            }
        }

        $tracks = [];
        foreach ($this->children($moov, 8, strlen($moov)) as $box) {
            if ($box['type'] !== 'trak') {
                continue;
            }
            $trak = substr($moov, $box['start'], $box['size']);
            $tkhd = $this->find($trak, 'trak/tkhd');
            $mdhd = $this->find($trak, 'trak/mdia/mdhd');
            $elst = $this->find($trak, 'trak/edts/elst');
            $id = $tkhd[0] === "\1" ? $this->u32($tkhd, 20) : $this->u32($tkhd, 12);
            $tracks[$id] = [
                'trak' => $trak,
                'handler' => substr($this->find($trak, 'trak/mdia/hdlr'), 8, 4),
                'timescale' => $mdhd[0] === "\1" ? $this->u32($mdhd, 20) : $this->u32($mdhd, 12),
                'movie_timescale' => $movieTimescale,
                'stsd' => $this->stsdEntries($this->find($trak, 'trak/mdia/minf/stbl/stsd')),
                'edits' => $elst === null ? null : $this->edits($elst),
                'durations' => [], 'offsets' => [], 'sync' => [], 'sizes' => [], 'stsc' => [], 'chunks' => [],
            ];
        }

        foreach ($file['top'] as $top) {
            if ($top['type'] !== 'moof') {
                continue;
            }
            $moof = $this->read($file['handle'], $top['start'], $top['size']);
            foreach ($this->children($moof, 8, strlen($moof)) as $traf) {
                if ($traf['type'] !== 'traf') {
                    continue;
                }
                $id = null;
                $defaults = [];
                $base = $top['start'];
                $next = null;
                foreach ($this->children($moof, $traf['start'] + $traf['header'], $traf['start'] + $traf['size']) as $child) {
                    $c = $child['start'] + $child['header'];
                    $version = ord($moof[$c]);
                    $flags = $this->u32($moof, $c) & 0xFFFFFF;
                    if ($child['type'] === 'tfhd') {
                        $id = $this->u32($moof, $c + 4);
                        $defaults = $trex[$id] ?? ['sdi' => 1, 'duration' => 0, 'size' => 0, 'flags' => 0];
                        $at = $c + 8;
                        if ($flags & 0x1) {
                            $base = $this->u64($moof, $at);
                            $at += 8;
                        }
                        foreach ([0x2 => 'sdi', 0x8 => 'duration', 0x10 => 'size', 0x20 => 'flags'] as $flag => $key) {
                            if ($flags & $flag) {
                                $defaults[$key] = $this->u32($moof, $at);
                                $at += 4;
                            }
                        }
                    } elseif ($child['type'] === 'trun' && isset($tracks[$id])) {
                        $count = $this->u32($moof, $c + 4);
                        $at = $c + 8;
                        $position = $next ?? $base;
                        if ($flags & 0x1) {
                            $position = $base + $this->s32($moof, $at);
                            $at += 4;
                        }
                        $firstFlags = null;
                        if ($flags & 0x4) {
                            $firstFlags = $this->u32($moof, $at);
                            $at += 4;
                        }
                        $track = &$tracks[$id];
                        $track['chunks'][] = $position;
                        $track['stsc'][] = [count($track['chunks']), $count, $defaults['sdi']];
                        for ($i = 0; $i < $count; $i++) {
                            $duration = $defaults['duration'];
                            $size = $defaults['size'];
                            $sampleFlags = $i === 0 && $firstFlags !== null ? $firstFlags : $defaults['flags'];
                            $offset = 0;
                            if ($flags & 0x100) {
                                $duration = $this->u32($moof, $at);
                                $at += 4;
                            }
                            if ($flags & 0x200) {
                                $size = $this->u32($moof, $at);
                                $at += 4;
                            }
                            if ($flags & 0x400) {
                                $sampleFlags = $this->u32($moof, $at);
                                $at += 4;
                            }
                            if ($flags & 0x800) {
                                $offset = $version === 1 ? $this->s32($moof, $at) : $this->u32($moof, $at);
                                $at += 4;
                            }
                            $track['durations'][] = $duration;
                            $track['sizes'][] = $size;
                            $track['offsets'][] = $offset;
                            $track['sync'][] = ! ($sampleFlags & 0x10000); // sample_is_non_sync_sample
                            $position += $size;
                        }
                        $next = $position;
                        unset($track);
                    }
                }
            }
        }

        // Same shape as parseClassic().
        $result = [];
        foreach ($tracks as $track) {
            $count = count($track['sizes']);
            $syncs = array_keys(array_filter($track['sync']));
            $result[] = [
                'trak' => $track['trak'],
                'handler' => $track['handler'],
                'timescale' => $track['timescale'],
                'movie_timescale' => $track['movie_timescale'],
                'stsd' => $track['stsd'],
                'edits' => $track['edits'],
                'stts' => $this->runs($track['durations']),
                'ctts' => array_filter($track['offsets']) ? $this->runs($track['offsets']) : null,
                'stss' => count($syncs) === $count ? null : array_map(fn ($i) => $i + 1, $syncs),
                'stsc' => $track['stsc'],
                'sizes' => $track['sizes'],
                'sample_count' => $count,
                'chunks' => $track['chunks'],
            ];
        }

        return ['movie_timescale' => $movieTimescale, 'tracks' => $result];
    }

    /** [5, 5, 5, 7] => [[3, 5], [1, 7]] */
    private function runs(array $values): array
    {
        $runs = [];
        foreach ($values as $value) {
            if ($runs && $runs[count($runs) - 1][1] === $value) {
                $runs[count($runs) - 1][0]++;
            } else {
                $runs[] = [1, $value];
            }
        }

        return $runs;
    }
}
