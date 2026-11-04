<?php

namespace KaiHempel\Mastodon\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use KaiHempel\Mastodon\Auth\AuthorizationFlow;
use KaiHempel\Mastodon\Events\MastodonAccountConnected;
use KaiHempel\Mastodon\Events\MastodonAccountDisconnected;
use KaiHempel\Mastodon\Exceptions\InvalidInstanceException;
use KaiHempel\Mastodon\Exceptions\OAuthException;
use KaiHempel\Mastodon\Facades\Mastodon;
use KaiHempel\Mastodon\Models\MastodonAccount;
use KaiHempel\Mastodon\Models\MastodonApp;
use KaiHempel\Mastodon\Support\Pkce;
use KaiHempel\Mastodon\Tests\TestCase;

class AuthorizationFlowTest extends TestCase
{
    private function fakeModernServer(array $tokenOverrides = []): void
    {
        Http::fake([
            self::INSTANCE.'/.well-known/oauth-authorization-server' => Http::response($this->fixture('oauth_metadata')),
            self::INSTANCE.'/api/v1/apps' => Http::response($this->fixture('app')),
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/oauth/token' => Http::response($this->fixture('token', $tokenOverrides)),
            self::INSTANCE.'/oauth/revoke' => Http::response([]),
            self::INSTANCE.'/api/v1/accounts/verify_credentials' => Http::response($this->fixture('account')),
        ]);
    }

    private function flow(): AuthorizationFlow
    {
        return Mastodon::authorization();
    }

    public function test_start_registers_app_and_builds_pkce_authorization_url(): void
    {
        $this->fakeModernServer();

        $request = $this->flow()->start('Example.Social');

        $app = MastodonApp::query()->sole();
        $this->assertSame(self::INSTANCE, $app->instance_url);
        $this->assertSame('TWhM-tNSuncnqN7DBJmoyeLnk6K3iJJ71KKXxgL1hPM', $app->client_id);
        $this->assertSame('4.4.0', $app->instance_version);
        $this->assertSame(['profile', 'write:statuses', 'write:media', 'read:statuses'], $request->scopes);

        Http::assertSent(fn (Request $r) => $r->url() === self::INSTANCE.'/api/v1/apps'
            && $r['client_name'] === 'Test App'
            && $r['redirect_uris'] === 'https://app.test/mastodon/callback'
            && $r['scopes'] === 'profile write:statuses write:media read:statuses');

        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith(self::INSTANCE.'/oauth/authorize?', $request->url);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame($app->client_id, $query['client_id']);
        $this->assertSame('https://app.test/mastodon/callback', $query['redirect_uri']);
        $this->assertSame('profile write:statuses write:media read:statuses', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame($request->state, $query['state']);

        $session = session(AuthorizationFlow::SESSION_KEY);
        $this->assertSame($request->state, $session['state']);
        $this->assertSame(Pkce::challenge($session['code_verifier']), $query['code_challenge']);
    }

    public function test_full_flow_stores_encrypted_account_and_fires_event(): void
    {
        Event::fake([MastodonAccountConnected::class]);
        $this->fakeModernServer();

        $request = $this->flow()->start('example.social');
        $verifier = session(AuthorizationFlow::SESSION_KEY)['code_verifier'];

        $account = $this->flow()->complete($request->state, 'the-code');

        $this->assertSame('14715', $account->remote_id);
        $this->assertSame('trwnh', $account->acct);
        $this->assertSame('ZA-Yj3aBD8U8Cm7lKUp-lm9O9BmDgdhHzDeqsY8tlL0', $account->access_token);
        $this->assertSame('@trwnh@example.social', $account->handle());
        $this->assertNotNull($account->last_verified_at);
        $this->assertNull(session(AuthorizationFlow::SESSION_KEY), 'State is single use.');

        Http::assertSent(fn (Request $r) => $r->url() === self::INSTANCE.'/oauth/token'
            && $r['grant_type'] === 'authorization_code'
            && $r['code'] === 'the-code'
            && $r['code_verifier'] === $verifier
            && $r['client_secret'] === 'ZEaFUFmF0umgBX1qKJDjaU99Q31lDkOU8NutzTOoliw'
            && $r['redirect_uri'] === 'https://app.test/mastodon/callback');

        Http::assertSent(fn (Request $r) => $r->url() === self::INSTANCE.'/api/v1/accounts/verify_credentials'
            && $r->hasHeader('Authorization', 'Bearer ZA-Yj3aBD8U8Cm7lKUp-lm9O9BmDgdhHzDeqsY8tlL0'));

        $raw = DB::table('mastodon_accounts')->first();
        $this->assertStringNotContainsString('ZA-Yj3aBD8U8Cm7lKUp', $raw->access_token);
        $rawApp = DB::table('mastodon_apps')->first();
        $this->assertStringNotContainsString('ZEaFUFmF0umgBX1qKJDjaU99Q31lDkOU8NutzTOoliw', $rawApp->client_secret);

        Event::assertDispatched(MastodonAccountConnected::class, fn ($e) => $e->account->is($account));
    }

    public function test_reconnecting_updates_the_existing_account(): void
    {
        $this->fakeModernServer();

        $first = $this->flow()->complete($this->flow()->start('example.social')->state, 'code-1');
        $second = $this->flow()->complete($this->flow()->start('example.social')->state, 'code-2');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, MastodonAccount::query()->count());
        $this->assertCount(1, Http::recorded(fn (Request $r) => $r->url() === self::INSTANCE.'/api/v1/apps'));
        $this->assertCount(2, Http::recorded(fn (Request $r) => $r->url() === self::INSTANCE.'/oauth/token'));
    }

    public function test_existing_app_is_reused(): void
    {
        $this->fakeModernServer();

        $this->flow()->start('example.social');
        $this->flow()->start('https://example.social/');

        $this->assertSame(1, MastodonApp::query()->count());
        $this->assertCount(1, Http::recorded(fn (Request $r) => $r->url() === self::INSTANCE.'/api/v1/apps'));
    }

    public function test_invalid_state_is_rejected(): void
    {
        $this->fakeModernServer();
        $this->flow()->start('example.social');

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('state');

        $this->flow()->complete('forged-state', 'code');
    }

    public function test_missing_session_is_rejected(): void
    {
        $this->expectException(OAuthException::class);

        $this->flow()->complete('state', 'code');
    }

    public function test_denied_authorization_is_reported(): void
    {
        $this->fakeModernServer();
        $state = $this->flow()->start('example.social')->state;

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('The resource owner or authorization server denied the request.');

        $this->flow()->complete($state, null, null, 'access_denied', 'The resource owner or authorization server denied the request.');
    }

    public function test_missing_granted_scopes_are_reported_and_token_revoked(): void
    {
        $this->fakeModernServer(['scope' => 'profile write:statuses']);
        $state = $this->flow()->start('example.social')->state;

        try {
            $this->flow()->complete($state, 'code');
            $this->fail('OAuthException expected');
        } catch (OAuthException $e) {
            $this->assertStringContainsString('write:media, read:statuses', $e->getMessage());
        }

        Http::assertSent(fn (Request $r) => $r->url() === self::INSTANCE.'/oauth/revoke');
        $this->assertSame(0, MastodonAccount::query()->count());
    }

    public function test_token_error_becomes_oauth_exception(): void
    {
        Http::fake([
            self::INSTANCE.'/.well-known/oauth-authorization-server' => Http::response($this->fixture('oauth_metadata')),
            self::INSTANCE.'/api/v1/apps' => Http::response($this->fixture('app')),
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/oauth/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'The provided authorization grant is invalid.'], 400),
        ]);

        $state = $this->flow()->start('example.social')->state;

        try {
            $this->flow()->complete($state, 'bad-code');
            $this->fail('OAuthException expected');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_grant', $e->serverMessage);
            $this->assertSame(400, $e->httpStatus);
            $this->assertStringNotContainsString('bad-code', $e->getMessage());
        }
    }

    public function test_registration_validation_error(): void
    {
        Http::fake([
            self::INSTANCE.'/.well-known/oauth-authorization-server' => Http::response($this->fixture('oauth_metadata')),
            self::INSTANCE.'/api/v1/apps' => Http::response(['error' => 'Validation failed: Redirect URI must be an absolute URI.'], 422),
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Redirect URI must be an absolute URI');

        $this->flow()->start('example.social');
    }

    public function test_legacy_server_uses_fallback_scopes_and_client_credentials(): void
    {
        Http::fake([
            self::INSTANCE.'/.well-known/oauth-authorization-server' => Http::response(['error' => 'Record not found'], 404),
            self::INSTANCE.'/api/v1/apps' => Http::response($this->fixture('app', ['scopes' => null]) + ['scopes' => null]),
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance', ['version' => '4.2.0'])),
            self::INSTANCE.'/oauth/token' => Http::response($this->fixture('token', ['scope' => 'read'])),
        ]);

        $request = $this->flow()->start('example.social');

        $this->assertSame(['read:accounts', 'write:statuses', 'write:media', 'read:statuses'], $request->scopes);
        $this->assertSame($request->scopes, MastodonApp::query()->sole()->scopes);
        Http::assertSent(fn (Request $r) => $r->url() === self::INSTANCE.'/oauth/token' && $r['grant_type'] === 'client_credentials');
    }

    public function test_unsafe_instances_are_rejected_before_any_request(): void
    {
        Http::fake();

        try {
            $this->flow()->start('https://169.254.169.254');
            $this->fail('InvalidInstanceException expected');
        } catch (InvalidInstanceException) {
        }

        Http::assertNothingSent();
    }

    public function test_disconnect_revokes_and_deletes(): void
    {
        Event::fake([MastodonAccountDisconnected::class]);
        Http::fake([self::INSTANCE.'/oauth/revoke' => Http::response([])]);
        $account = $this->createAccount();

        $this->flow()->disconnect($account);

        Http::assertSent(fn (Request $r) => $r->url() === self::INSTANCE.'/oauth/revoke'
            && $r['token'] === 'user-token' && $r['client_id'] === 'client-id' && $r['client_secret'] === 'client-secret');
        $this->assertSame(0, MastodonAccount::query()->count());
        Event::assertDispatched(MastodonAccountDisconnected::class, fn ($e) => $e->reason === 'revoked');
    }

    public function test_disconnect_with_token_of_other_app_throws(): void
    {
        Http::fake([self::INSTANCE.'/oauth/revoke' => Http::response(['error' => 'unauthorized_client'], 403)]);
        $account = $this->createAccount();

        $this->expectException(OAuthException::class);

        try {
            $this->flow()->disconnect($account);
        } finally {
            $this->assertSame(1, MastodonAccount::query()->count());
        }
    }
}
