<?php

namespace App\Services\Engine;

use App\Models\Asset;
use App\Services\Ai\GeneratedFile;
use App\Support\UrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Turns a node's reference list into image bytes plus each image's role. Each source is an uploaded
 * asset ("asset:12"), a URL from an earlier node ({{cover.output.url}}), or a public https URL.
 */
class ReferenceImages
{
    public const MAX = 4;

    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly UrlGuard $guard) {}

    /**
     * @param  list<string|array{src: string, role?: string, note?: string}>  $references
     * @return list<array{file: GeneratedFile, role: string, note: ?string, kind: 'image'|'video'}>
     */
    public function resolve(int $workspaceId, array $references): array
    {
        $entries = self::normalize($references);
        if (count($entries) > self::MAX) {
            throw new InvalidArgumentException('Use at most '.self::MAX.' references.');
        }

        return array_map(function (array $entry) use ($workspaceId) {
            $file = $this->load($workspaceId, $entry['src']);

            return $entry + ['file' => $file, 'kind' => str_starts_with($file->mime, 'video/') ? 'video' : 'image'];
        }, $entries);
    }

    /**
     * Older flows store plain strings; newer ones store {src, role, note}. Unknown roles fall back to "auto".
     *
     * @return list<array{src: string, role: string, note: ?string}>
     */
    public static function normalize(array $references): array
    {
        $entries = [];
        foreach ($references as $ref) {
            $src = trim((string) (is_array($ref) ? ($ref['src'] ?? '') : $ref));
            $role = is_array($ref) ? (string) ($ref['role'] ?? 'auto') : 'auto';
            $note = is_array($ref) ? mb_substr(trim((string) ($ref['note'] ?? '')), 0, 500) : '';
            if ($src !== '') {
                $entries[] = [
                    'src' => $src,
                    'role' => array_key_exists($role, ReferenceRoles::ROLES) || ReferenceRoles::isVideoRole($role) ? $role : 'auto',
                    'note' => $note !== '' ? $note : null,
                ];
            }
        }

        return $entries;
    }

    private function load(int $workspaceId, string $ref): GeneratedFile
    {
        if (preg_match('/^asset:(\d+)$/', $ref, $m)) {
            $asset = Asset::where('workspace_id', $workspaceId)->whereIn('type', ['image', 'video'])->find($m[1])
                ?? throw new InvalidArgumentException("Reference {$ref} was not found in this workspace.");

            return new GeneratedFile(Storage::disk('public')->get($asset->storage_path), $asset->mime);
        }

        // Our own stored files (e.g. output of an earlier Image node) are read from disk. They may be
        // root-relative ("/storage/assets/..") or absolute links saved under an older APP_URL.
        if ($path = $this->ownAssetPath($workspaceId, $ref)) {
            $asset = Asset::where('workspace_id', $workspaceId)->where('storage_path', $path)->first()
                ?? throw new InvalidArgumentException('Reference image was not found in this workspace.');

            return new GeneratedFile(Storage::disk('public')->get($path), $asset->mime);
        }

        if (! str_starts_with($ref, 'https://')) {
            throw new InvalidArgumentException('A reference is not an image: "'.mb_strimwidth($ref, 0, 60, '…').'". '
                .'If it comes from a form answer, that question must be an image upload (for a Saved character question, tick "Character chosen on the form" on the step instead).');
        }
        $this->guard->assertPublicHttps($ref);
        $response = Http::timeout(30)->withOptions(['allow_redirects' => false])->get($ref);
        $mime = strtok((string) $response->header('Content-Type'), ';');

        if ($response->failed() || ! str_starts_with((string) $mime, 'image/')) {
            throw new InvalidArgumentException("Could not load a reference image from {$ref}.");
        }
        if (strlen($response->body()) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Reference images must be under 10 MB.');
        }

        return new GeneratedFile($response->body(), $mime);
    }

    private function ownAssetPath(int $workspaceId, string $ref): ?string
    {
        $parts = parse_url($ref);
        $host = $parts['host'] ?? null;
        $local = $host === null || in_array($host, [parse_url((string) config('app.url'), PHP_URL_HOST), 'localhost', '127.0.0.1'], true);
        $prefix = '/storage/assets/'.$workspaceId.'/';

        if (! $local || ! str_starts_with($parts['path'] ?? '', $prefix)) {
            return null;
        }

        return 'assets/'.$workspaceId.'/'.basename($parts['path']);
    }
}
