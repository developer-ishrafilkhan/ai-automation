<?php

namespace App\Services\Ai;

final class GeneratedFile
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
    ) {}
}
