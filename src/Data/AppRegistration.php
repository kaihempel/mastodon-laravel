<?php

namespace KaiHempel\Mastodon\Data;

use Carbon\CarbonImmutable;
use KaiHempel\Mastodon\Support\Scopes;

final class AppRegistration
{
    /**
     * @param  list<string>  $redirectUris
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $name,
        public readonly ?string $website,
        public readonly string $clientId,
        #[\SensitiveParameter] public readonly string $clientSecret,
        public readonly array $redirectUris,
        public readonly array $scopes,
        public readonly ?CarbonImmutable $clientSecretExpiresAt,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $a = new Attributes($data);

        $redirectUris = $a->get('redirect_uris');
        if (! is_array($redirectUris)) {
            $redirectUris = preg_split('/\s+/', $a->string('redirect_uri'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        // Since 4.4.0; 0 means the secret does not expire.
        $expiresAt = $a->nullableInt('client_secret_expires_at');

        return new self(
            id: $a->nullableString('id'),
            name: $a->string('name'),
            website: $a->nullableString('website'),
            clientId: $a->string('client_id'),
            clientSecret: $a->string('client_secret'),
            redirectUris: array_values(array_map('strval', $redirectUris)),
            scopes: Scopes::parse($a->get('scopes')),
            clientSecretExpiresAt: $expiresAt ? CarbonImmutable::createFromTimestamp($expiresAt) : null,
        );
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'clientId' => $this->clientId, 'clientSecret' => '***', 'scopes' => $this->scopes];
    }
}
