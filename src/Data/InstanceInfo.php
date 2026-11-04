<?php

namespace KaiHempel\Mastodon\Data;

/**
 * Limits and version of an instance from GET /api/v2/instance.
 *
 * All limits are read from the server. The fallbacks below are only used when
 * a (forked or outdated) server omits a field and correspond to the Mastodon
 * defaults.
 */
final class InstanceInfo
{
    /**
     * @param  list<string>  $supportedMimeTypes
     * @param  array<string, int>  $apiVersions
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $domain,
        public readonly string $title,
        public readonly string $version,
        public readonly array $apiVersions,
        public readonly int $maxCharacters,
        public readonly int $maxMediaAttachments,
        public readonly int $charactersReservedPerUrl,
        public readonly array $supportedMimeTypes,
        public readonly ?int $descriptionLimit,
        public readonly ?int $imageSizeLimit,
        public readonly ?int $imageMatrixLimit,
        public readonly ?int $videoSizeLimit,
        public readonly ?int $videoMatrixLimit,
        public readonly ?int $videoFrameRateLimit,
        public readonly int $pollMaxOptions,
        public readonly int $pollMaxCharactersPerOption,
        public readonly int $pollMinExpiration,
        public readonly int $pollMaxExpiration,
        public readonly array $raw = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $a = new Attributes($data);
        $apiVersions = array_map('intval', array_filter($a->array('api_versions'), 'is_numeric'));

        return new self(
            domain: $a->string('domain', $a->string('uri')),
            title: $a->string('title'),
            version: $a->string('version'),
            apiVersions: $apiVersions,
            maxCharacters: $a->int('configuration.statuses.max_characters', 500),
            maxMediaAttachments: $a->int('configuration.statuses.max_media_attachments', 4),
            charactersReservedPerUrl: $a->int('configuration.statuses.characters_reserved_per_url', 23),
            supportedMimeTypes: $a->strings('configuration.media_attachments.supported_mime_types'),
            descriptionLimit: $a->nullableInt('configuration.media_attachments.description_limit') ?? 1500,
            imageSizeLimit: $a->nullableInt('configuration.media_attachments.image_size_limit'),
            imageMatrixLimit: $a->nullableInt('configuration.media_attachments.image_matrix_limit'),
            videoSizeLimit: $a->nullableInt('configuration.media_attachments.video_size_limit'),
            videoMatrixLimit: $a->nullableInt('configuration.media_attachments.video_matrix_limit'),
            videoFrameRateLimit: $a->nullableInt('configuration.media_attachments.video_frame_rate_limit'),
            pollMaxOptions: $a->int('configuration.polls.max_options', 4),
            pollMaxCharactersPerOption: $a->int('configuration.polls.max_characters_per_option', 50),
            pollMinExpiration: $a->int('configuration.polls.min_expiration', 300),
            pollMaxExpiration: $a->int('configuration.polls.max_expiration', 2629746),
            raw: $data,
        );
    }

    /** Version number without suffixes, e.g. "4.3.0" for "4.3.0+glitch". */
    public function semanticVersion(): string
    {
        return preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $this->version, $m)
            ? sprintf('%d.%d.%d', $m[1], $m[2], $m[3] ?? 0)
            : '0.0.0';
    }

    /** Capability check against the server version. */
    public function supports(string $minimumVersion): bool
    {
        return version_compare($this->semanticVersion(), $minimumVersion, '>=');
    }

    /** api_versions.mastodon (0 if the server does not report it). */
    public function apiVersion(): int
    {
        return $this->apiVersions['mastodon'] ?? 0;
    }

    public function supportsMediaDeletion(): bool
    {
        return $this->supports('4.4.0');
    }

    public function supportsProfileScope(): bool
    {
        return $this->supports('4.3.0');
    }

    public function isMimeTypeSupported(string $mimeType): bool
    {
        return $this->supportedMimeTypes === [] || in_array(strtolower($mimeType), array_map('strtolower', $this->supportedMimeTypes), true);
    }
}
