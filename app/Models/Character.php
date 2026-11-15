<?php

namespace App\Models;

use App\Services\Ai\GeneratedFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/** A reusable character whose identity is kept the same across every image and video. */
class Character extends Model
{
    public const STYLES = [
        'photorealistic' => 'photorealistic, natural skin texture, real-camera look',
        '3d-animation' => 'high-end 3D animated film style, soft global illumination',
        'anime' => 'clean anime style, crisp line art, cel shading',
        'illustration' => 'flat vector illustration, bold shapes, clean outlines',
        'comic' => 'comic book style, inked outlines, halftone shading',
        'claymation' => 'stop-motion clay style, handmade texture',
    ];

    /** Images per character sent to the model: the sheet first, then the best photos. */
    public const MAX_REFERENCES = 4;

    protected $fillable = ['workspace_id', 'name', 'appearance', 'style', 'voice', 'reference_asset_ids', 'sheet_asset_id', 'sheet_status', 'sheet_error'];

    protected $appends = ['sheet_url', 'reference_urls'];

    protected function casts(): array
    {
        return ['reference_asset_ids' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'sheet_asset_id');
    }

    /** @return Collection<int, Asset> sheet first, then reference photos, in order */
    public function referenceAssets()
    {
        $ids = array_values(array_unique(array_filter([$this->sheet_asset_id, ...($this->reference_asset_ids ?? [])])));
        $assets = Asset::where('workspace_id', $this->workspace_id)->where('type', 'image')->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $assets->get($id))->filter()->take(self::MAX_REFERENCES)->values();
    }

    /** @return list<GeneratedFile> */
    public function referenceImages(): array
    {
        return $this->referenceAssets()
            ->map(fn (Asset $asset) => new GeneratedFile(Storage::disk('public')->get($asset->storage_path), $asset->mime))
            ->all();
    }

    public function styleDescription(): string
    {
        return self::STYLES[$this->style] ?? $this->style;
    }

    public function getSheetUrlAttribute(): ?string
    {
        return $this->sheet_asset_id ? Asset::find($this->sheet_asset_id)?->url : null;
    }

    public function getReferenceUrlsAttribute(): array
    {
        return Asset::where('workspace_id', $this->workspace_id)->whereIn('id', $this->reference_asset_ids ?? [])
            ->get()->map(fn (Asset $a) => ['id' => $a->id, 'url' => $a->url])->values()->all();
    }
}
