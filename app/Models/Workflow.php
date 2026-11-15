<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workflow extends Model
{
    protected $fillable = ['workspace_id', 'name', 'status', 'draft_graph', 'active_version_id', 'form_slug', 'form_enabled', 'form_settings'];

    protected function casts(): array
    {
        return ['draft_graph' => 'array', 'form_enabled' => 'boolean', 'form_settings' => 'array'];
    }

    public static function emptyGraph(): array
    {
        return [
            'nodes' => [[
                'id' => 'trigger',
                'type' => 'trigger.manual',
                'position' => ['x' => 0, 'y' => 0],
                'data' => ['label' => 'Manual trigger', 'input' => ['topic' => 'AI automation for small businesses']],
            ]],
            'edges' => [],
        ];
    }

    /** Daily cap on public form runs, which are paid for with this workspace's keys. */
    public const DEFAULT_FORM_DAILY_LIMIT = 50;

    public function formSettings(): array
    {
        return ($this->form_settings ?? []) + [
            'title' => $this->name,
            'description' => '',
            'submit_label' => 'Generate',
            'daily_limit' => self::DEFAULT_FORM_DAILY_LIMIT,
        ];
    }

    public function formUrl(): ?string
    {
        return $this->form_slug ? url('/f/'.$this->form_slug) : null;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'active_version_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }

    /** Snapshot the draft into a version row, reusing the latest one if the graph is unchanged. */
    public function snapshotDraft(): WorkflowVersion
    {
        $hash = hash('sha256', json_encode($this->draft_graph));
        $latest = $this->versions()->latest('version')->first();

        if ($latest && $latest->graph_hash === $hash) {
            return $latest;
        }

        return $this->versions()->create([
            'version' => ($latest?->version ?? 0) + 1,
            'graph' => $this->draft_graph,
            'graph_hash' => $hash,
        ]);
    }
}
