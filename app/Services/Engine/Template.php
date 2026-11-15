<?php

namespace App\Services\Engine;

/** Fills {{nodeKey.output.path}} references from earlier step outputs. */
class Template
{
    public static function render(?string $template, array $context): string
    {
        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_\-\.]+)\s*\}\}/', function (array $match) use ($context) {
            $value = data_get($context, $match[1]);

            return match (true) {
                $value === null => '',
                is_scalar($value) => (string) $value,
                default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };
        }, (string) $template);
    }
}
