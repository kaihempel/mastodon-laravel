<?php

namespace KaiHempel\Mastodon\Tests\Feature;

use Illuminate\Support\Facades\Http;
use KaiHempel\Mastodon\Models\MastodonApp;
use KaiHempel\Mastodon\Tests\TestCase;

class CommandsTest extends TestCase
{
    public function test_instance_command_shows_limits(): void
    {
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/.well-known/oauth-authorization-server' => Http::response($this->fixture('oauth_metadata')),
        ]);

        $this->artisan('mastodon:instance', ['instance' => 'example.social'])
            ->expectsOutputToContain('4.4.0')
            ->expectsOutputToContain('statuses.max_characters')
            ->assertSuccessful();
    }

    public function test_instance_command_rejects_unsafe_instances(): void
    {
        $this->artisan('mastodon:instance', ['instance' => 'http://10.0.0.1'])
            ->expectsOutputToContain('https')
            ->assertFailed();
    }

    public function test_register_command(): void
    {
        Http::fake([
            self::INSTANCE.'/.well-known/oauth-authorization-server' => Http::response($this->fixture('oauth_metadata')),
            self::INSTANCE.'/api/v1/apps' => Http::response($this->fixture('app')),
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
        ]);

        $this->artisan('mastodon:register', ['instance' => 'example.social'])
            ->expectsOutputToContain('is registered')
            ->assertSuccessful();

        $this->assertSame('https://app.test/mastodon/callback', MastodonApp::query()->sole()->redirect_uri);
    }

    public function test_verify_command(): void
    {
        Http::fake([self::INSTANCE.'/api/v1/accounts/verify_credentials' => Http::response($this->fixture('account'))]);
        $account = $this->createAccount();

        $this->artisan('mastodon:verify', ['account' => $account->id])
            ->expectsOutputToContain('@trwnh@example.social')
            ->assertSuccessful();

        $this->assertNotNull($account->fresh()->last_verified_at);
    }

    public function test_verify_command_reports_failures(): void
    {
        Http::fake([self::INSTANCE.'/api/v1/accounts/verify_credentials' => Http::response(['error' => 'revoked'], 401)]);
        $this->createAccount();

        $this->artisan('mastodon:verify')->expectsOutputToContain('FAILED')->assertFailed();
    }
}
