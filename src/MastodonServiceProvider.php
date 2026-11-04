<?php

namespace KaiHempel\Mastodon;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use KaiHempel\Mastodon\Auth\AuthorizationFlow;
use KaiHempel\Mastodon\Console\InstanceCommand;
use KaiHempel\Mastodon\Console\RegisterCommand;
use KaiHempel\Mastodon\Console\SyncScheduledCommand;
use KaiHempel\Mastodon\Console\VerifyCommand;
use KaiHempel\Mastodon\Http\PendingRequestFactory;
use KaiHempel\Mastodon\Publishing\Publisher;
use KaiHempel\Mastodon\Publishing\StatusPublisher;
use KaiHempel\Mastodon\Support\DnsHostResolver;
use KaiHempel\Mastodon\Support\HostResolver;
use KaiHempel\Mastodon\Support\RateLimitStore;

class MastodonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mastodon.php', 'mastodon');

        $this->app->singleton(MastodonManager::class, fn (Container $app) => new MastodonManager($app));
        $this->app->alias(MastodonManager::class, 'mastodon');

        $this->app->bindIf(HostResolver::class, DnsHostResolver::class);
        $this->app->bindIf(Publisher::class, StatusPublisher::class);

        $this->app->bind(RateLimitStore::class, fn (Container $app) => new RateLimitStore($app->make('cache.store')));

        $this->app->bind(PendingRequestFactory::class, fn (Container $app) => new PendingRequestFactory(
            $app->make(Factory::class),
            $app->make(RateLimitStore::class),
            $app->make(Dispatcher::class),
            $app->make('log'),
            (array) $app->make('config')->get('mastodon', []) + ['debug' => (bool) $app->make('config')->get('app.debug')],
        ));

        $this->app->bind(AuthorizationFlow::class, fn (Container $app) => new AuthorizationFlow(
            $app->make(MastodonManager::class),
            $app->make('session.store'),
            $app->make(Dispatcher::class),
            $app->make('url'),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->make('config')->get('mastodon.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/mastodon.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/mastodon.php' => config_path('mastodon.php')], 'mastodon-config');
            $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'mastodon-migrations');

            $this->commands([
                RegisterCommand::class,
                VerifyCommand::class,
                InstanceCommand::class,
                SyncScheduledCommand::class,
            ]);
        }
    }
}
