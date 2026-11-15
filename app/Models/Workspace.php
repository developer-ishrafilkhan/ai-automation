<?php

namespace App\Models;

use App\Services\Vault\Vault;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    protected $fillable = ['name', 'plan', 'owner_id', 'limits', 'encrypted_data_key'];

    protected $hidden = ['encrypted_data_key'];

    protected function casts(): array
    {
        return ['limits' => 'array'];
    }

    public static function createFor(User $user, string $name): self
    {
        $workspace = self::create([
            'name' => $name,
            'owner_id' => $user->id,
            'encrypted_data_key' => app(Vault::class)->newWrappedDataKey(),
        ]);
        $workspace->members()->attach($user->id, ['role' => 'owner']);
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        return $workspace;
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')->withPivot('role')->withTimestamps();
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(AiCredential::class);
    }

    public function modelDefaults(): HasMany
    {
        return $this->hasMany(ModelDefault::class);
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    public function mcpServers(): HasMany
    {
        return $this->hasMany(McpServer::class);
    }

    public function promptGuides(): HasMany
    {
        return $this->hasMany(PromptGuide::class);
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
