<?php

namespace App\Services\Engine\Nodes;

use App\Models\Asset;
use App\Models\ExecutionStep;
use App\Services\Ai\GeneratedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait StoresAssets
{
    protected function storeAsset(ExecutionStep $step, string $type, GeneratedFile $file): Asset
    {
        $execution = $step->execution;
        $workspaceId = $execution->workflow->workspace_id;
        $extension = Str::after($file->mime, '/') ?: 'bin';
        $path = "assets/{$workspaceId}/".Str::uuid().".{$extension}";

        Storage::disk('public')->put($path, $file->bytes);

        return Asset::create([
            'workspace_id' => $workspaceId,
            'execution_id' => $execution->id,
            'type' => $type,
            'storage_path' => $path,
            'mime' => $file->mime,
            'size' => strlen($file->bytes),
            'tags' => [$step->node_id],
        ]);
    }
}
