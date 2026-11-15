<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Asset extends Model
{
    protected $fillable = ['workspace_id', 'execution_id', 'type', 'storage_path', 'mime', 'size', 'tags'];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return ['tags' => 'array'];
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->storage_path);
    }
}
