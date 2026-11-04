<?php

namespace KaiHempel\Mastodon\Resources;

use KaiHempel\Mastodon\Data\Token;
use KaiHempel\Mastodon\Exceptions\AuthorizationException;
use KaiHempel\Mastodon\Exceptions\MastodonException;
use KaiHempel\Mastodon\Exceptions\OAuthException;
use KaiHempel\Mastodon\Http\Connector;
use KaiHempel\Mastodon\Models\MastodonApp;
use KaiHempel\Mastodon\Support\Scopes;

class OAuthResource
{
    public function __construct(
        private readonly Connector $connector,
        private readonly MastodonApp $app,
    ) {}

    /**
     * URL for GET /oauth/authorize (browser redirect).
     *
     * @param  list<string>|string  $scopes
     * @param  array<string, string>  $extra  e.g. ['force_login' => 'true', 'lang' => 'de']
     */
    public function authorizationUrl(string $redirectUri, array|string $scopes, string $state, string $codeChallenge, array $extra = []): string
    {
        $query = [
            'response_type' => 'code',
            'client_id' => $this->app->client_id,
            'redirect_uri' => $redirectUri,
            'scope' => Scopes::toString($scopes),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ] + $extra;

        return rtrim($this->connector->baseUrl(), '/').'/oauth/authorize?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * POST /oauth/token with grant_type=authorization_code.
     */
    public function exchangeCode(#[\SensitiveParameter] string $code, string $redirectUri, #[\SensitiveParameter] string $codeVerifier): Token
    {
        return $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->app->client_id,
            'client_secret' => $this->app->client_secret,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * POST /oauth/token with grant_type=client_credentials (app token).
     *
     * @param  list<string>|string  $scopes
     */
    public function clientCredentials(array|string $scopes = 'read'): Token
    {
        return $this->token([
            'grant_type' => 'client_credentials',
            'client_id' => $this->app->client_id,
            'client_secret' => $this->app->client_secret,
            'redirect_uri' => $this->app->redirect_uri,
            'scope' => Scopes::toString($scopes),
        ]);
    }

    /**
     * POST /oauth/revoke. HTTP 403 means the token does not belong to this app.
     */
    public function revoke(#[\SensitiveParameter] string $token): void
    {
        try {
            $this->connector->post('/oauth/revoke', ['form' => [
                'client_id' => $this->app->client_id,
                'client_secret' => $this->app->client_secret,
                'token' => $token,
            ]]);
        } catch (AuthorizationException $e) {
            throw new OAuthException('The token does not belong to this application.', 403, $e->serverMessage, $e->errorDescription, $e);
        }
    }

    /** @param array<string, string> $form */
    private function token(#[\SensitiveParameter] array $form): Token
    {
        try {
            $response = $this->connector->post('/oauth/token', ['form' => $form, 'retry' => false]);
        } catch (OAuthException $e) {
            throw $e;
        } catch (MastodonException $e) {
            if ($e->httpStatus !== null && $e->httpStatus >= 400 && $e->httpStatus < 500) {
                throw new OAuthException('Token request failed: '.($e->errorDescription ?? $e->serverMessage ?? 'unknown error'), $e->httpStatus, $e->serverMessage, $e->errorDescription, $e);
            }

            throw $e;
        }

        $token = Token::fromArray((array) $response->json());

        if ($token->accessToken === '') {
            throw new OAuthException('The token response does not contain an access_token.');
        }

        return $token;
    }
}
