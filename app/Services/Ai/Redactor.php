<?php

namespace App\Services\Ai;

/** Strips API keys and tokens from anything we store or log. */
class Redactor
{
    private const PATTERNS = [
        '/sk-[A-Za-z0-9_\-]{8,}/',      // OpenAI, Anthropic (sk-ant-...), DeepSeek
        '/AIza[0-9A-Za-z_\-]{20,}/',    // Google
        '/Bearer\s+[A-Za-z0-9._\-]+/i',
    ];

    public static function clean(string $text, array $secrets = []): string
    {
        foreach (array_filter($secrets) as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }

        return preg_replace(self::PATTERNS, '[redacted]', $text);
    }
}
