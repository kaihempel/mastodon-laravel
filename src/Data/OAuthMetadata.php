<?php

namespace KaiHempel\Mastodon\Data;

/**
 * RFC 8414 authorization server metadata (Mastodon >= 4.3).
 */
final class OAuthMetadata
{
    /**
     * @param  list<string>  $scopesSupported
     * @param  list<string>  $codeChallengeMethodsSupported
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $issuer,
        public readonly ?string $authorizationEndpoint,
        public readonly ?string $tokenEndpoint,
        public readonly ?string $revocationEndpoint,
        public readonly ?string $registrationEndpoint,
        public readonly array $scopesSupported,
        public readonly array $codeChallengeMethodsSupported,
        public readonly array $raw = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $a = new Attributes($data);

        return new self(
            issuer: $a->nullableString('issuer'),
            authorizationEndpoint: $a->nullableString('authorization_endpoint'),
            tokenEndpoint: $a->nullableString('token_endpoint'),
            revocationEndpoint: $a->nullableString('revocation_endpoint'),
            registrationEndpoint: $a->nullableString('app_registration_endpoint'),
            scopesSupported: $a->strings('scopes_supported'),
            codeChallengeMethodsSupported: $a->strings('code_challenge_methods_supported'),
            raw: $data,
        );
    }

    public function supportsScope(string $scope): bool
    {
        return in_array($scope, $this->scopesSupported, true);
    }
}
