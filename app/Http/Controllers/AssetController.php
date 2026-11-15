<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\Ai\GeneratedFile;
use App\Services\Engine\ColorPalette;
use App\Support\Mp4Duration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AssetController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('assets/index', [
            'assets' => $this->workspace($request)->assets()->latest()->paginate(48),
        ]);
    }

    /** Upload a reference: an image (logo, person, product, style...) or a short video clip for Video nodes. */
    public function upload(Request $request): JsonResponse
    {
        $workspace = $this->guard($request, write: true);
        $request->validate([
            'image' => ['required_without:file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
            'file' => ['required_without:image', 'file', 'mimes:png,jpg,jpeg,webp,mp4,mov', 'max:20480'],
        ]);

        $file = $request->file('file') ?? $request->file('image');
        // Judge by the file's actual bytes; uploads can report MP4 as "application/mp4".
        $sniffed = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $isVideo = in_array($sniffed, ['video/mp4', 'video/quicktime', 'application/mp4'], true);
        $mime = $isVideo ? ($sniffed === 'video/quicktime' ? 'video/quicktime' : 'video/mp4') : $sniffed;
        if ($isVideo && ($seconds = Mp4Duration::seconds((string) file_get_contents($file->getRealPath()))) !== null && $seconds > 10.5) {
            return response()->json(['message' => sprintf('This clip is %.0f seconds long. Video references can be up to 10 seconds.', $seconds)], 422);
        }
        if (! $isVideo && $file->getSize() > 10 * 1024 * 1024) {
            return response()->json(['message' => 'Images must be under 10 MB.'], 422);
        }

        $path = $file->storeAs("assets/{$workspace->id}", Str::uuid().'.'.$file->extension(), 'public');

        $asset = Asset::create([
            'workspace_id' => $workspace->id,
            'type' => $isVideo ? 'video' : 'image',
            'storage_path' => $path,
            'mime' => $mime,
            'size' => $file->getSize(),
            'tags' => ['reference'],
        ]);

        return response()->json(['id' => $asset->id, 'url' => $asset->url, 'type' => $asset->type]);
    }

    /** The colour palette a "Colour theme only" reference contributes (shown as swatches in the editor). */
    public function palette(Request $request, Asset $asset, ColorPalette $palette): JsonResponse
    {
        $this->guard($request, $asset->workspace_id);
        abort_unless($asset->type === 'image', 422);

        return response()->json(['colours' => $palette->extract(new GeneratedFile(Storage::disk('public')->get($asset->storage_path), $asset->mime))]);
    }
}
