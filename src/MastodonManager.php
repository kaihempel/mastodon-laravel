<?php

namespace KaiHempel\Mastodon;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use KaiHempel\Mastodon\Auth\AuthorizationFlow;
use KaiHempel\Mastodon\Events\MastodonAccountDisconnected;
use KaiHempel\Mastodon\Http\Connector;
use KaiHempel\Mastodon\Http\PendingRequestFactory;
use KaiHempel\Mastodon\Models\MastodonAccount;
use KaiHempel\Mastodon\Models\MastodonApp;
use KaiHempel\Mastodon\Publishing\Publisher;
use KaiHempel\Mastodon\Resources\AccountsResource;
use KaiHempel\Mastodon\Resources\AppsResource;
use KaiHempel\Mastodon\Resources\InstanceResource;
use KaiHempel\Mastodon\Resources\OAuthResource;
use KaiHempel\Mastodon\Support\HostResolver;
use KaiHempel\Mastodon\Support\InstanceUrl;
use KaiHempel\Mastodon\Support\LocalThrottle;

/**
 * Entry point of the package (target of the Mastodon facade).
 */
class MastodonManager
{
    public function __construct(protected readonly Container $container) {}

    /** Client for a connected account. */
    public function for(MastodonAccount $account): MastodonClient
    {
        return new MastodonClient($this, $account);
    }

    /**
     * Normalize and validate user input (host name or URL) against the SSRF rules.
     */
    public function normalizeInstance(string|InstanceUrl $instance): InstanceUrl
    {
        if ($instance instanceof InstanceUrl) {
            return $instance;
        }

        return InstanceUrl::parse(
            $instance,
            $this->container->make(HostResolver::class),
            (bool) $this->config('allow_http_instances', false),
        );
    }

    /** Public instance endpoints (info, OAuth metadata). */
    public function instance(string|InstanceUrl $instance): InstanceResource
    {
        $url = $this->normalizeInstance($instance);

        return $this->instanceResource($this->requests()->connector($url->baseUrl(), rateLimitKey: 'instance:'.$url->host));
    }

    public function apps(string|InstanceUrl $instance): AppsResource
    {
        $url = $this->normalizeInstance($instance);

        return new AppsResource($this->requests()->connector($url->baseUrl(), rateLimitKey: 'instance:'.$url->host));
    }

    public function oauth(MastodonApp $app): OAuthResource
    {
        return new OAuthResource($this->requests()->connector($app->instance_url), $app);
    }

    /** Accounts resource for a token that is not stored yet (OAuth callback). */
    public function accountsWithToken(MastodonApp $app, #[\SensitiveParameter] string $token): AccountsResource
    {
        return new AccountsResource($this->requests()->connector($app->instance_url, $token));
    }

    public function authorization(): AuthorizationFlow
    {
        return $this->container->make(AuthorizationFlow::class);
    }

    public function publisher(): Publisher
    {
        return $this->container->make(Publisher::class);
    }

    /**
     * Connector with the account token. A 401 response disables the account
     * and fires MastodonAccountDisconnected.
     */
    public function connectorFor(MastodonAccount $account): Connector
    {
        return $this->requests()->connector(
            $account->instanceUrl(),
            $account->access_token,
            'account:'.$account->getKey(),
            function () use ($account): void {
                if ($account->exists && $account->disabled_at === null) {
                    $account->forceFill(['disabled_at' => now()])->save();
                    $this->container->make(Dispatcher::class)->dispatch(new MastodonAccountDisconnected($account, 'unauthorized'));
                }
            },
        );
    }

    public function instanceResource(Connector $connector): InstanceResource
    {
        return new InstanceResource(
            $connector,
            $this->container->make(Repository::class),
            (int) $this->config('instance_cache_ttl', 3600),
        );
    }

    public function throttleFor(MastodonAccount $account): LocalThrottle
    {
        return new LocalThrottle(
            $this->container->make(RateLimiter::class),
            $this->container->make(Dispatcher::class),
            (array) $this->config('throttle', []),
            'account:'.$account->getKey(),
        );
    }

    public function requests(): PendingRequestFactory
    {
        return $this->container->make(PendingRequestFactory::class);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->container->make('config')->get('mastodon.'.$key, $default);
    }
}
