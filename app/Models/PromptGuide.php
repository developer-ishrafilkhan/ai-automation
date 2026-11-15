<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The workspace's "training" for the AI prompt writer, per task type. */
class PromptGuide extends Model
{
    public const TASK_TYPES = ['image', 'video'];

    public const MAX_EXAMPLES = 12;

    protected $fillable = ['workspace_id', 'task_type', 'instructions', 'examples', 'credential_id', 'model'];

    protected function casts(): array
    {
        return ['examples' => 'array'];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(AiCredential::class, 'credential_id');
    }

    public function addExample(string $prompt, ?string $note = null): void
    {
        $examples = array_values(array_filter($this->examples ?? [], fn ($e) => $e['prompt'] !== $prompt));
        array_unshift($examples, ['prompt' => $prompt, 'note' => $note, 'saved_at' => now()->toIso8601String()]);
        $this->examples = array_slice($examples, 0, self::MAX_EXAMPLES);
    }
}
