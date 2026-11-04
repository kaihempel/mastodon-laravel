<?php

namespace KaiHempel\Mastodon\Resources;

use KaiHempel\Mastodon\Data\AppRegistration;
use KaiHempel\Mastodon\Exceptions\OAuthException;
use KaiHempel\Mastodon\Exceptions\ValidationException;
use KaiHempel\Mastodon\Http\Connector;
use KaiHempel\Mastodon\Support\Scopes;

class AppsResource
{
    public function __construct(private readonly Connector $connector) {}

    /**
     * POST /api/v1/apps
     *
     * @param  list<string>|string  $redirectUris
     * @param  list<string>|string  $scopes
     *
     * @throws OAuthException on HTTP 422
     */
    public function register(string $clientName, array|string $redirectUris, array|string $scopes, ?string $website = null): AppRegistration
    {
        $form = array_filter([
            'client_name' => $clientName,
            // Newline separated string: accepted by all versions (arrays only since 4.3).
            'redirect_uris' => implode("\n", (array) $redirectUris),
            'scopes' => Scopes::toString($scopes),
            'website' => $website,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $response = $this->connector->post('/api/v1/apps', ['form' => $form, 'retry' => false]);
        } catch (ValidationException $e) {
            throw new OAuthException('App registration failed: '.($e->serverMessage ?? $e->getMessage()), 422, $e->serverMessage, $e->errorDescription, $e);
        }

        $registration = AppRegistration::fromArray((array) $response->json());

        // Some versions do not echo the scopes; fall back to the requested ones.
        if ($registration->scopes === []) {
            $data = (array) $response->json();
            $data['scopes'] = Scopes::parse($scopes);
            $registration = AppRegistration::fromArray($data);
        }

        return $registration;
    }

    /**
     * GET /api/v1/apps/verify_credentials (requires an app or user token on the connector).
     *
     * @return array<string, mixed>
     */
    public function verify(): array
    {
        return (array) $this->connector->get('/api/v1/apps/verify_credentials')->json();
    }
}
