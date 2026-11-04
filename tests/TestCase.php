<?php

namespace KaiHempel\Mastodon\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use KaiHempel\Mastodon\MastodonServiceProvider;
use KaiHempel\Mastodon\Models\MastodonAccount;
use KaiHempel\Mastodon\Models\MastodonApp;
use KaiHempel\Mastodon\Support\HostResolver;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    public const INSTANCE = 'https://example.social';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-04 12:00:00');
        CarbonImmutable::setTestNow('2026-11-04 12:00:00');
        Http::preventStrayRequests();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        Sleep::fake(false);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [MastodonServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Mastodon' => \KaiHempel\Mastodon\Facades\Mastodon::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('mastodon.redirect_uri', 'https://app.test/mastodon/callback');
        $app['config']->set('mastodon.client_name', 'Test App');
        $app['config']->set('mastodon.http.backoff_ms', 10);
        $app['config']->set('mastodon.media.allowed_paths', [__DIR__.'/Fixtures']);

        // Every host resolves to a public address unless a test says otherwise.
        $app->instance(HostResolver::class, new class implements HostResolver
        {
            /** @var array<string, list<string>> */
            public array $map = [];

            public function resolve(string $host): array
            {
                return $this->map[$host] ?? ['93.184.216.34'];
            }
        });
    }

    /** @return array<string, mixed> */
    protected function fixture(string $name, array $overrides = []): array
    {
        $data = json_decode((string) file_get_contents(__DIR__."/Fixtures/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);

        return array_replace_recursive($data, $overrides);
    }

    protected function createApp(array $attributes = []): MastodonApp
    {
        return MastodonApp::query()->create($attributes + [
            'instance_url' => self::INSTANCE,
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'scopes' => ['profile', 'write:statuses', 'write:media', 'read:statuses'],
            'redirect_uri' => 'https://app.test/mastodon/callback',
            'instance_version' => '4.4.0',
        ]);
    }

    protected function createAccount(array $attributes = []): MastodonAccount
    {
        $app = $this->createApp();

        return MastodonAccount::query()->create($attributes + [
            'mastodon_app_id' => $app->id,
            'remote_id' => '14715',
            'acct' => 'trwnh',
            'username' => 'trwnh',
            'display_name' => 'Test',
            'url' => self::INSTANCE.'/@trwnh',
            'access_token' => 'user-token',
            'scopes' => ['profile', 'write:statuses', 'write:media', 'read:statuses'],
        ]);
    }

    protected function fakeInstance(array $overrides = []): void
    {
        Http::fake([self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance', $overrides))]);
    }
}
