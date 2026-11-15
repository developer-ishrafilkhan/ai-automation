<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Execution extends Model
{
    protected $fillable = ['workflow_id', 'version_id', 'status', 'source', 'public_token', 'trigger_data', 'error', 'cost_estimate', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['trigger_data' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'version_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ExecutionStep::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    protected $hidden = ['public_token'];

    public function isFinished(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }
}
