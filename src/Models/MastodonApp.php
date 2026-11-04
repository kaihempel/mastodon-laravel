<?php

namespace KaiHempel\Mastodon\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OAuth application registered at one instance for one redirect URI.
 *
 * @property int $id
 * @property string $instance_url
 * @property string $client_id
 * @property string $client_secret
 * @property list<string> $scopes
 * @property string $redirect_uri
 * @property \Carbon\CarbonImmutable|null $client_secret_expires_at
 * @property string|null $instance_version
 */
class MastodonApp extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['client_id', 'client_secret'];

    protected function casts(): array
    {
        return [
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'scopes' => 'array',
            'client_secret_expires_at' => 'immutable_datetime',
        ];
    }

    public function getTable(): string
    {
        return config('mastodon.tables.apps', 'mastodon_apps');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(MastodonAccount::class, 'mastodon_app_id');
    }

    public function secretExpired(): bool
    {
        return $this->client_secret_expires_at !== null && $this->client_secret_expires_at->isPast();
    }
}
