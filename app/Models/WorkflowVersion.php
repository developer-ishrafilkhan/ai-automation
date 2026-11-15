<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowVersion extends Model
{
    protected $fillable = ['workflow_id', 'version', 'graph', 'graph_hash', 'published_at'];

    protected function casts(): array
    {
        return ['graph' => 'array', 'published_at' => 'datetime'];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
