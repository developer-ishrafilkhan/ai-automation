<?php

namespace App\Models;

use App\Services\Vault\Vault;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCredential extends Model
{
    public const PROVIDERS = ['anthropic', 'openai', 'google', 'deepseek'];

    protected $fillable = ['workspace_id', 'provider', 'label', 'encrypted_key', 'key_last4', 'status', 'status_message', 'models', 'checked_at'];

    // The key never leaves the server, not even encrypted.
    protected $hidden = ['encrypted_key'];

    protected function casts(): array
    {
        return ['models' => 'array', 'checked_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function setSecret(string $plainKey): void
    {
        $this->encrypted_key = app(Vault::class)->encrypt($this->workspace, $plainKey);
        $this->key_last4 = substr($plainKey, -4);
    }

    /** Decrypt in memory at the moment of the API call only. */
    public function secret(): string
    {
        return app(Vault::class)->decrypt($this->workspace, $this->encrypted_key);
    }
}
