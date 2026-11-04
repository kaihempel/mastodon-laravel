<?php

namespace KaiHempel\Mastodon\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use KaiHempel\Mastodon\MastodonClient;
use KaiHempel\Mastodon\MastodonManager;

/**
 * A connected Mastodon account.
 *
 * @property int $id
 * @property int $mastodon_app_id
 * @property string $remote_id
 * @property string $acct
 * @property string $username
 * @property string|null $display_name
 * @property string|null $url
 * @property string $access_token
 * @property list<string> $scopes
 * @property \Carbon\CarbonImmutable|null $last_verified_at
 * @property \Carbon\CarbonImmutable|null $disabled_at
 * @property-read MastodonApp $app
 */
class MastodonAccount extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'scopes' => 'array',
            'last_verified_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
        ];
    }

    public function getTable(): string
    {
        return config('mastodon.tables.accounts', 'mastodon_accounts');
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(MastodonApp::class, 'mastodon_app_id');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(MastodonPost::class, 'mastodon_account_id');
    }

    /** @param Builder<MastodonAccount> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('disabled_at');
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function instanceUrl(): string
    {
        return $this->app->instance_url;
    }

    /** Full handle including the instance, e.g. "@alice@example.social". */
    public function handle(): string
    {
        $acct = $this->acct;

        if (! str_contains($acct, '@')) {
            $acct .= '@'.parse_url($this->instanceUrl(), PHP_URL_HOST);
        }

        return '@'.$acct;
    }

    public function client(): MastodonClient
    {
        return app(MastodonManager::class)->for($this);
    }
}
