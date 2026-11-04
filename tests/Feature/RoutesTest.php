<?php

namespace KaiHempel\Mastodon\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Http;
use KaiHempel\Mastodon\Auth\AuthorizationFlow;
use KaiHempel\Mastodon\Models\MastodonAccount;
use KaiHempel\Mastodon\Tests\TestCase;

class RoutesTest extends TestCase
{
    private function user(int $id = 1): User
    {
        $user = new User;
        $user->forceFill(['id' => $id]);
        $user->exists = true;

        return $user;
    }

    public function test_routes_require_authentication(): void
    {
        $this->postJson('/mastodon/connect', ['instance' => 'example.social'])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_connect_and_callback(): void
    {
        Http::fake([
            self::INSTANCE.'/.well-known/oauth-authorization-server' => Http::response($this->fixture('oauth_metadata')),
            self::INSTANCE.'/api/v1/apps' => Http::response($this->fixture('app')),
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/oauth/token' => Http::response($this->fixture('token')),
            self::INSTANCE.'/api/v1/accounts/verify_credentials' => Http::response($this->fixture('account')),
        ]);
        $user = $this->user(7);

        $response = $this->actingAs($user)->post('/mastodon/connect', ['instance' => 'example.social']);
        $response->assertRedirectContains(self::INSTANCE.'/oauth/authorize');

        $state = session(AuthorizationFlow::SESSION_KEY)['state'];

        $this->actingAs($user)->get('/mastodon/callback?code=abc&state='.$state)
            ->assertRedirect('/')
            ->assertSessionHas('mastodon.connected', '@trwnh@example.social');

        $account = MastodonAccount::query()->sole();
        $this->assertSame('7', (string) $account->owner_id);
        $this->assertSame($user->getMorphClass(), $account->owner_type);
    }

    public function test_callback_with_invalid_state_redirects_with_error(): void
    {
        $this->actingAs($this->user())->get('/mastodon/callback?code=abc&state=nope')
            ->assertRedirect('/')
            ->assertSessionHas('mastodon.error');
    }

    public function test_connect_with_invalid_instance_returns_error(): void
    {
        $this->actingAs($this->user())->from('/settings')->post('/mastodon/connect', ['instance' => 'http://127.0.0.1'])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors('instance');
    }

    public function test_only_owner_can_disconnect(): void
    {
        Http::fake([self::INSTANCE.'/oauth/revoke' => Http::response([])]);
        $owner = $this->user(1);
        $account = $this->createAccount(['owner_type' => $owner->getMorphClass(), 'owner_id' => 1]);

        $this->actingAs($this->user(2))->delete('/mastodon/accounts/'.$account->id)->assertForbidden();
        $this->assertSame(1, MastodonAccount::query()->count());

        $this->actingAs($owner)->from('/settings')->delete('/mastodon/accounts/'.$account->id)->assertRedirect('/settings');
        $this->assertSame(0, MastodonAccount::query()->count());
    }
}
