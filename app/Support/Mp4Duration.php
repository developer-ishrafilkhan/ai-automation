<?php

namespace App\Support;

/** Reads a clip's length from the "mvhd" box of an MP4/MOV file, without ffmpeg. */
class Mp4Duration
{
    public static function seconds(string $bytes): ?float
    {
        $pos = strpos($bytes, 'mvhd');
        if ($pos === false || strlen($bytes) < $pos + 32) {
            return null;
        }

        $version = ord($bytes[$pos + 4]);
        if ($version === 1) {
            // version(1) flags(3) creation(8) modification(8) timescale(4) duration(8)
            $timescale = unpack('N', substr($bytes, $pos + 24, 4))[1];
            $duration = unpack('J', substr($bytes, $pos + 28, 8))[1];
        } else {
            // version(1) flags(3) creation(4) modification(4) timescale(4) duration(4)
            $timescale = unpack('N', substr($bytes, $pos + 16, 4))[1];
            $duration = unpack('N', substr($bytes, $pos + 20, 4))[1];
        }

        return $timescale > 0 ? $duration / $timescale : null;
    }
}
