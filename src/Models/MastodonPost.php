<?php

namespace KaiHempel\Mastodon\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use KaiHempel\Mastodon\Enums\PostState;

/**
 * Local record of one publication. Prevents duplicate posts, because the
 * server only remembers an Idempotency-Key for up to one hour.
 *
 * @property int $id
 * @property int $mastodon_account_id
 * @property string $idempotency_key
 * @property PostState $state
 * @property string|null $remote_status_id
 * @property string|null $remote_scheduled_id
 * @property string|null $url
 * @property \Carbon\CarbonImmutable|null $scheduled_at
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property string|null $error
 * @property array<string, mixed>|null $payload
 * @property-read MastodonAccount $account
 */
class MastodonPost extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'state' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'state' => PostState::class,
            'payload' => 'array',
            'scheduled_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function getTable(): string
    {
        return config('mastodon.tables.posts', 'mastodon_posts');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MastodonAccount::class, 'mastodon_account_id');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
