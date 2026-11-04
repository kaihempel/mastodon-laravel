<?php

namespace KaiHempel\Mastodon\Facades;

use Illuminate\Support\Facades\Facade;
use KaiHempel\Mastodon\Data\InstanceInfo;
use KaiHempel\Mastodon\MastodonManager;
use KaiHempel\Mastodon\Testing\MastodonFake;

/**
 * @method static \KaiHempel\Mastodon\MastodonClient for(\KaiHempel\Mastodon\Models\MastodonAccount $account)
 * @method static \KaiHempel\Mastodon\Resources\InstanceResource instance(string|\KaiHempel\Mastodon\Support\InstanceUrl $instance)
 * @method static \KaiHempel\Mastodon\Resources\AppsResource apps(string|\KaiHempel\Mastodon\Support\InstanceUrl $instance)
 * @method static \KaiHempel\Mastodon\Resources\OAuthResource oauth(\KaiHempel\Mastodon\Models\MastodonApp $app)
 * @method static \KaiHempel\Mastodon\Auth\AuthorizationFlow authorization()
 * @method static \KaiHempel\Mastodon\Support\InstanceUrl normalizeInstance(string $instance)
 * @method static void assertPublished(?\Closure $callback = null)
 * @method static void assertScheduled(?\Closure $callback = null)
 * @method static void assertNothingPublished()
 * @method static void assertPublishedCount(int $count)
 *
 * @see MastodonManager
 * @see MastodonFake
 */
class Mastodon extends Facade
{
    /**
     * Replace the manager with a fake that records published statuses
     * instead of sending HTTP requests.
     */
    public static function fake(?InstanceInfo $instance = null): MastodonFake
    {
        $fake = new MastodonFake(static::getFacadeApplication(), $instance);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return MastodonManager::class;
    }
}
