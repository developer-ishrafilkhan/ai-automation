<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelDefault extends Model
{
    public const TASK_TYPES = ['text', 'research', 'image', 'video'];

    protected $fillable = ['workspace_id', 'task_type', 'credential_id', 'model', 'fallback_credential_id', 'fallback_model'];

    public function credential(): BelongsTo
    {
        return $this->belongsTo(AiCredential::class, 'credential_id');
    }

    public function fallbackCredential(): BelongsTo
    {
        return $this->belongsTo(AiCredential::class, 'fallback_credential_id');
    }
}
