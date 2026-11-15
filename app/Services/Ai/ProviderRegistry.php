<?php

namespace App\Services\Ai;

use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\GoogleProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Ai\Providers\Provider;
use InvalidArgumentException;

class ProviderRegistry
{
    public function for(string $provider): Provider
    {
        return match ($provider) {
            'anthropic' => new AnthropicProvider,
            'openai' => new OpenAiProvider,
            'google' => new GoogleProvider,
            'deepseek' => new OpenAiProvider('DeepSeek', 'https://api.deepseek.com', supportsImages: false),
            default => throw new InvalidArgumentException("Unknown provider [{$provider}]."),
        };
    }
}
