<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use RuntimeException;

class ProviderException extends RuntimeException
{
    public static function fromResponse(string $provider, Response $response): self
    {
        $message = $response->json('error.message') ?? $response->json('error') ?? $response->body();
        if (! is_string($message)) {
            $message = json_encode($message);
        }

        return new self(sprintf('%s API error (%d): %s', $provider, $response->status(), mb_substr($message, 0, 500)));
    }

    public static function unsupported(string $provider, string $capability): self
    {
        return new self("{$provider} does not support {$capability} in this platform yet.");
    }
}
