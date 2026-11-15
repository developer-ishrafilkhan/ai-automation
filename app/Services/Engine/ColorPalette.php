<?php

namespace App\Services\Engine;

use App\Services\Ai\GeneratedFile;
use InvalidArgumentException;

/**
 * Extracts an image's colour palette as text, so a "Colour theme only" reference can be used
 * without showing the image to any model (which would make it copy the layout and content).
 */
class ColorPalette
{
    private const SAMPLE_WIDTH = 96;

    private const MAX_COLOURS = 6;

    /** @return list<array{hex: string, name: string, share: int}> */
    public function extract(GeneratedFile $image): array
    {
        $source = @imagecreatefromstring($image->bytes);
        if (! $source) {
            throw new InvalidArgumentException('Could not read the colour-theme reference image.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $sample = imagescale($source, self::SAMPLE_WIDTH, max(1, (int) round($height * self::SAMPLE_WIDTH / max(1, $width))));
        imagedestroy($source);

        // Count pixels in coarse colour buckets (16 levels per channel), keeping each bucket's average colour.
        $buckets = [];
        $total = 0;
        for ($y = 0, $h = imagesy($sample); $y < $h; $y++) {
            for ($x = 0; $x < self::SAMPLE_WIDTH; $x++) {
                $rgba = imagecolorsforindex($sample, imagecolorat($sample, $x, $y));
                if ($rgba['alpha'] > 100) {
                    continue; // mostly transparent
                }
                $key = ($rgba['red'] >> 4).'-'.($rgba['green'] >> 4).'-'.($rgba['blue'] >> 4);
                $bucket = &$buckets[$key];
                $bucket ??= ['n' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
                $bucket['n']++;
                $bucket['r'] += $rgba['red'];
                $bucket['g'] += $rgba['green'];
                $bucket['b'] += $rgba['blue'];
                unset($bucket);
                $total++;
            }
        }
        imagedestroy($sample);

        if ($total === 0) {
            throw new InvalidArgumentException('The colour-theme reference image has no visible pixels.');
        }

        usort($buckets, fn ($a, $b) => $b['n'] <=> $a['n']);

        // Merge visually similar colours so the palette lists distinct colours, not shades of one.
        $palette = [];
        foreach ($buckets as $bucket) {
            $rgb = [$bucket['r'] / $bucket['n'], $bucket['g'] / $bucket['n'], $bucket['b'] / $bucket['n']];
            foreach ($palette as &$colour) {
                if ($this->distance($colour['rgb'], $rgb) < 48) {
                    $colour['n'] += $bucket['n'];

                    continue 2;
                }
            }
            unset($colour);
            $palette[] = ['rgb' => $rgb, 'n' => $bucket['n']];
        }

        usort($palette, fn ($a, $b) => $b['n'] <=> $a['n']);

        return array_values(array_filter(array_map(fn ($c) => [
            'hex' => sprintf('#%02X%02X%02X', ...array_map(fn ($v) => (int) round($v), $c['rgb'])),
            'name' => $this->name($c['rgb']),
            'share' => (int) round(100 * $c['n'] / $total),
        ], array_slice($palette, 0, self::MAX_COLOURS)), fn ($c) => $c['share'] >= 2)); // smaller shares are mostly edge blending, not real colours
    }

    /** "#1C1C1E near-black (48%), #F39A2B orange (14%), …" */
    public function describe(GeneratedFile $image): string
    {
        return implode(', ', array_map(fn ($c) => "{$c['hex']} {$c['name']} ({$c['share']}%)", $this->extract($image)));
    }

    private function distance(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    private function name(array $rgb): string
    {
        [$r, $g, $b] = array_map(fn ($v) => $v / 255, $rgb);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $s = $max === $min ? 0 : ($l > 0.5 ? ($max - $min) / (2 - $max - $min) : ($max - $min) / ($max + $min));

        if ($l < 0.12) {
            return 'near-black';
        }
        if ($l > 0.92) {
            return 'near-white';
        }
        if ($s < 0.15) {
            return $l < 0.35 ? 'dark grey' : ($l < 0.7 ? 'grey' : 'light grey');
        }

        $hue = match (true) {
            $max === $r => fmod((($g - $b) / ($max - $min)) * 60 + 360, 360),
            $max === $g => (($b - $r) / ($max - $min)) * 60 + 120,
            default => (($r - $g) / ($max - $min)) * 60 + 240,
        };
        $name = match (true) {
            $hue < 15 || $hue >= 345 => 'red',
            $hue < 40 => 'orange',
            $hue < 65 => 'yellow',
            $hue < 160 => 'green',
            $hue < 195 => 'teal',
            $hue < 255 => 'blue',
            $hue < 290 => 'purple',
            default => 'pink',
        };

        return ($l < 0.3 ? 'dark ' : ($l > 0.75 ? 'light ' : '')).$name;
    }
}
