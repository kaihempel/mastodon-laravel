<?php

namespace KaiHempel\Mastodon\Auth;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use KaiHempel\Mastodon\Data\AuthorizationRequest;
use KaiHempel\Mastodon\Events\MastodonAccountConnected;
use KaiHempel\Mastodon\Events\MastodonAccountDisconnected;
use KaiHempel\Mastodon\Exceptions\AuthenticationException;
use KaiHempel\Mastodon\Exceptions\MastodonException;
use KaiHempel\Mastodon\Exceptions\OAuthException;
use KaiHempel\Mastodon\MastodonManager;
use KaiHempel\Mastodon\Models\MastodonAccount;
use KaiHempel\Mastodon\Models\MastodonApp;
use KaiHempel\Mastodon\Support\InstanceUrl;
use KaiHempel\Mastodon\Support\Pkce;
use KaiHempel\Mastodon\Support\Scopes;

/**
 * OAuth 2 authorization code flow with PKCE (S256) against any instance:
 *
 *   $request = Mastodon::authorization()->start('example.social');
 *   return redirect()->away($request->url);
 *
 *   // callback
 *   $account = Mastodon::authorization()->complete($request->query('state'), $request->query('code'), auth()->user());
 */
class AuthorizationFlow
{
    public const SESSION_KEY = 'mastodon_oauth';

    public function __construct(
        private readonly MastodonManager $manager,
        private readonly Session $session,
        private readonly Dispatcher $events,
        private readonly UrlGenerator $url,
    ) {}

    /**
     * Steps 1-4: validate the instance, read the OAuth metadata, register the
     * app if needed and build the authorization URL. State and code verifier
     * are stored in the session.
     *
     * @param  list<string>|null  $scopes  defaults to mastodon.scopes or mastodon.fallback_scopes
     * @param  array<string, string>  $extra  additional authorize parameters (force_login, lang)
     */
    public function start(string $instance, ?array $scopes = null, ?string $redirectUri = null, array $extra = []): AuthorizationRequest
    {
        $instanceUrl = $this->manager->normalizeInstance($instance);
        $redirectUri ??= $this->redirectUri();

        $metadata = $this->manager->instance($instanceUrl)->oauthMetadata();
        $legacy = $metadata === null;

        if ($scopes === null) {
            $useFallback = $legacy || ($metadata->scopesSupported !== [] && ! $metadata->supportsScope('profile'));
            $scopes = Scopes::parse($this->manager->config($useFallback ? 'fallback_scopes' : 'scopes', []));
        }

        $scopes = Scopes::parse($scopes);

        if ($metadata !== null && $metadata->codeChallengeMethodsSupported !== []
            && ! in_array('S256', $metadata->codeChallengeMethodsSupported, true)) {
            throw new OAuthException('The instance does not support PKCE with S256.');
        }

        $app = $this->findOrRegisterApp($instanceUrl, $redirectUri, $scopes, $legacy);

        $state = Str::random(40);
        $pkce = Pkce::generate();

        $this->session->put(self::SESSION_KEY, [
            'state' => $state,
            'code_verifier' => $pkce['verifier'],
            'app_id' => $app->getKey(),
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes,
        ]);

        $url = $this->manager->oauth($app)->authorizationUrl($redirectUri, $scopes, $state, $pkce['challenge'], $extra);

        return new AuthorizationRequest($url, $state, $app->instance_url, (int) $app->getKey(), $scopes);
    }

    /**
     * Steps 5-7: check the state, exchange the code, compare the granted
     * scopes, verify the account and store it.
     */
    public function complete(?string $state, #[\SensitiveParameter] ?string $code, ?Model $owner = null, ?string $error = null, ?string $errorDescription = null): MastodonAccount
    {
        $pending = $this->session->pull(self::SESSION_KEY);

        if ($error !== null && $error !== '') {
            throw new OAuthException('Authorization was not granted: '.($errorDescription ?: $error), null, $error, $errorDescription);
        }

        if (! is_array($pending) || ! is_string($state) || ! hash_equals((string) ($pending['state'] ?? ''), $state)) {
            throw new OAuthException('Invalid or expired OAuth state.');
        }

        if ($code === null || $code === '') {
            throw new OAuthException('The authorization code is missing.');
        }

        /** @var MastodonApp|null $app */
        $app = MastodonApp::query()->find($pending['app_id']);
        if ($app === null) {
            throw new OAuthException('The registered application no longer exists.');
        }

        $oauth = $this->manager->oauth($app);
        $token = $oauth->exchangeCode($code, $pending['redirect_uri'], $pending['code_verifier']);

        $missing = Scopes::missing($token->scopes, $pending['scopes']);
        if ($missing !== []) {
            try {
                $oauth->revoke($token->accessToken);
            } catch (MastodonException) {
                // best effort
            }

            throw new OAuthException('The instance did not grant the required scopes: '.implode(', ', $missing));
        }

        $remote = $this->manager->accountsWithToken($app, $token->accessToken)->verify();

        $attributes = [
            'acct' => $remote->acct,
            'username' => $remote->username,
            'display_name' => $remote->displayName,
            'url' => $remote->url,
            'access_token' => $token->accessToken,
            'scopes' => $token->scopes,
            'last_verified_at' => now(),
            'disabled_at' => null,
        ];

        if ($owner !== null) {
            $attributes['owner_type'] = $owner->getMorphClass();
            $attributes['owner_id'] = $owner->getKey();
        }

        /** @var MastodonAccount $account */
        $account = MastodonAccount::query()->updateOrCreate(
            ['mastodon_app_id' => $app->getKey(), 'remote_id' => $remote->id],
            $attributes,
        );

        $this->events->dispatch(new MastodonAccountConnected($account));

        return $account;
    }

    /**
     * Step 8: revoke the token and delete the account locally. An already
     * invalid token (401) is ignored; a 403 (token of another app) throws.
     */
    public function disconnect(MastodonAccount $account): void
    {
        try {
            $this->manager->oauth($account->app)->revoke($account->access_token);
        } catch (AuthenticationException) {
            // Token is already invalid.
        }

        $account->delete();

        $this->events->dispatch(new MastodonAccountDisconnected($account, 'revoked'));
    }

    /**
     * Reuses the app for instance + redirect URI if its scopes cover the
     * requested ones, otherwise registers a new one.
     *
     * @param  list<string>  $scopes
     */
    public function findOrRegisterApp(InstanceUrl|string $instance, string $redirectUri, array $scopes, bool $legacy = false): MastodonApp
    {
        $instance = $this->manager->normalizeInstance($instance);

        /** @var MastodonApp|null $app */
        $app = MastodonApp::query()
            ->where('instance_url', $instance->baseUrl())
            ->where('redirect_uri', $redirectUri)
            ->first();

        if ($app !== null && Scopes::covers($app->scopes ?? [], $scopes) && ! $app->secretExpired()) {
            return $app;
        }

        $registration = $this->manager->apps($instance)->register(
            (string) $this->manager->config('client_name', 'Laravel'),
            [$redirectUri],
            $scopes,
            $this->manager->config('website'),
        );

        $version = null;
        try {
            $version = $this->manager->instance($instance)->info()->version ?: null;
        } catch (MastodonException) {
            // optional
        }

        $app ??= new MastodonApp(['instance_url' => $instance->baseUrl(), 'redirect_uri' => $redirectUri]);
        $app->fill([
            'client_id' => $registration->clientId,
            'client_secret' => $registration->clientSecret,
            'scopes' => $registration->scopes,
            'client_secret_expires_at' => $registration->clientSecretExpiresAt,
            'instance_version' => $version,
        ])->save();

        // Before 4.3 unused apps could be cleaned up; the docs recommended
        // obtaining a client_credentials token right after registration.
        if ($legacy) {
            $this->manager->oauth($app)->clientCredentials('read');
        }

        return $app;
    }

    public function redirectUri(): string
    {
        $configured = $this->manager->config('redirect_uri');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if ($this->manager->config('routes.enabled', true)) {
            return $this->url->route('mastodon.callback');
        }

        throw new OAuthException('No redirect URI configured (mastodon.redirect_uri).');
    }
}
