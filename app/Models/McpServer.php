<?php

namespace App\Models;

use App\Services\Mcp\McpOAuth;
use App\Services\Vault\Vault;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A remote MCP server (Streamable HTTP) the workspace has connected, e.g. Notion, GitHub, Drive. */
class McpServer extends Model
{
    protected $fillable = ['workspace_id', 'catalog_id', 'name', 'url', 'auth_type', 'status', 'status_message', 'tools_cache', 'checked_at'];

    protected $hidden = ['encrypted_token', 'encrypted_oauth'];

    protected $appends = ['has_token'];

    protected function casts(): array
    {
        return ['tools_cache' => 'array', 'checked_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function setToken(?string $token): void
    {
        $this->encrypted_token = $token ? app(Vault::class)->encrypt($this->workspace, $token) : null;
    }

    /** The bearer token to send: a pasted token, or a fresh OAuth access token. */
    public function token(): ?string
    {
        if ($this->auth_type === 'oauth') {
            return app(McpOAuth::class)->accessToken($this);
        }

        return $this->encrypted_token ? app(Vault::class)->decrypt($this->workspace, $this->encrypted_token) : null;
    }

    public function oauthData(): ?array
    {
        return $this->encrypted_oauth ? json_decode(app(Vault::class)->decrypt($this->workspace, $this->encrypted_oauth), true) : null;
    }

    public function setOAuthData(array $data): void
    {
        $this->auth_type = 'oauth';
        $this->encrypted_oauth = app(Vault::class)->encrypt($this->workspace, json_encode($data));
    }

    public function getHasTokenAttribute(): bool
    {
        return $this->encrypted_token !== null || $this->encrypted_oauth !== null;
    }

    /** Name sent to providers: lowercase letters, digits and dashes. */
    public function slug(): string
    {
        return Str::slug($this->name).'-'.$this->id;
    }
}
