<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionStep extends Model
{
    protected $fillable = ['execution_id', 'node_id', 'node_type', 'status', 'input', 'output', 'state', 'error', 'attempts', 'tokens', 'started_at', 'finished_at'];

    protected $hidden = ['state'];

    protected $appends = ['progress'];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'state' => 'array',
            'tokens' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /** Long videos: how many scenes are ready, e.g. {done: 3, total: 8}. The rest of the state stays private. */
    public function getProgressAttribute(): ?array
    {
        $scenes = $this->state['scenes'] ?? null;

        return is_array($scenes) && $this->status !== 'succeeded'
            ? ['done' => count(array_filter($scenes, fn ($s) => ($s['status'] ?? null) === 'done')), 'total' => count($scenes)]
            : null;
    }
}
