<?php

namespace KaiHempel\Mastodon\Resources;

use Closure;
use Illuminate\Support\Sleep;
use KaiHempel\Mastodon\Data\InstanceInfo;
use KaiHempel\Mastodon\Data\MediaAttachment;
use KaiHempel\Mastodon\Exceptions\MediaProcessingException;
use KaiHempel\Mastodon\Exceptions\MediaProcessingTimeout;
use KaiHempel\Mastodon\Exceptions\UnsupportedFeatureException;
use KaiHempel\Mastodon\Exceptions\ValidationException;
use KaiHempel\Mastodon\Http\Connector;
use KaiHempel\Mastodon\Publishing\MediaFile;
use KaiHempel\Mastodon\Support\LocalThrottle;

class MediaResource
{
    /**
     * @param  Closure(): InstanceInfo  $instance
     */
    public function __construct(
        private readonly Connector $connector,
        private readonly Closure $instance,
        private readonly LocalThrottle $throttle,
        private readonly int $pollIntervalMs = 1500,
        private readonly int $pollTimeoutSeconds = 180,
    ) {}

    /**
     * POST /api/v2/media. The result may still be processing (HTTP 202,
     * url = null); use waitUntilProcessed() or upload(..., wait: true).
     *
     * @param  array{0: float, 1: float}|null  $focus  x,y between -1.0 and 1.0
     */
    public function upload(MediaFile $file, ?string $description = null, ?array $focus = null, ?MediaFile $thumbnail = null, bool $wait = false): MediaAttachment
    {
        // /api/v2/media is throttled like /api/v1/media (30 per 30 minutes).
        $this->throttle->hit('media_uploads');

        $files = [['file', $file->contents(), $file->filename, ['Content-Type' => $file->mimeType]]];
        if ($thumbnail !== null) {
            $files[] = ['thumbnail', $thumbnail->contents(), $thumbnail->filename, ['Content-Type' => $thumbnail->mimeType]];
        }

        $response = $this->connector->post('/api/v2/media', [
            'multipart' => array_filter([
                'description' => $description,
                'focus' => $focus !== null ? self::focus($focus) : null,
            ], fn ($value) => $value !== null),
            'files' => $files,
        ]);

        $media = MediaAttachment::fromArray((array) $response->json());

        return $wait ? $this->waitUntilProcessed($media) : $media;
    }

    /**
     * GET /api/v1/media/:id. 200 = processed, 206 = still processing.
     *
     * @throws MediaProcessingException on HTTP 422
     */
    public function get(string $id): MediaAttachment
    {
        try {
            $response = $this->connector->get('/api/v1/media/'.rawurlencode($id));
        } catch (ValidationException $e) {
            throw new MediaProcessingException("Processing of media {$id} failed: ".($e->serverMessage ?? $e->getMessage()), 422, $e->serverMessage, null, $e);
        }

        return MediaAttachment::fromArray((array) $response->json());
    }

    /**
     * Polls GET /api/v1/media/:id until the URL is available.
     *
     * @throws MediaProcessingTimeout
     */
    public function waitUntilProcessed(MediaAttachment $media): MediaAttachment
    {
        $maxPolls = max(1, (int) ceil($this->pollTimeoutSeconds * 1000 / max(1, $this->pollIntervalMs)));
        $polls = 0;

        while (! $media->isProcessed()) {
            if ($polls >= $maxPolls) {
                throw new MediaProcessingTimeout("Media {$media->id} was not processed within {$this->pollTimeoutSeconds} seconds.");
            }

            Sleep::usleep($this->pollIntervalMs * 1000);
            $media = $this->get($media->id);
            $polls++;
        }

        return $media;
    }

    /**
     * PUT /api/v1/media/:id (only possible before the media is attached to a status).
     *
     * @param  array{0: float, 1: float}|null  $focus
     */
    public function update(string $id, ?string $description = null, ?array $focus = null, ?MediaFile $thumbnail = null): MediaAttachment
    {
        $fields = array_filter([
            'description' => $description,
            'focus' => $focus !== null ? self::focus($focus) : null,
        ], fn ($value) => $value !== null);

        $options = $thumbnail !== null
            ? ['multipart' => $fields, 'files' => [['thumbnail', $thumbnail->contents(), $thumbnail->filename, ['Content-Type' => $thumbnail->mimeType]]]]
            : ['json' => $fields];

        return MediaAttachment::fromArray((array) $this->connector->put('/api/v1/media/'.rawurlencode($id), $options)->json());
    }

    /**
     * DELETE /api/v1/media/:id (Mastodon >= 4.4.0, only unattached media).
     *
     * @throws UnsupportedFeatureException
     */
    public function delete(string $id): void
    {
        $info = ($this->instance)();

        if (! $info->supportsMediaDeletion()) {
            throw new UnsupportedFeatureException("Deleting media requires Mastodon 4.4.0 (instance runs {$info->version}).");
        }

        $this->connector->delete('/api/v1/media/'.rawurlencode($id));
    }

    /** @param array{0: float, 1: float}|array<int|string, float> $focus */
    public static function focus(array $focus): string
    {
        [$x, $y] = array_values($focus) + [0.0, 0.0];

        return sprintf('%s,%s', self::number((float) $x), self::number((float) $y));
    }

    private static function number(float $value): string
    {
        $value = max(-1.0, min(1.0, $value));

        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }

}
