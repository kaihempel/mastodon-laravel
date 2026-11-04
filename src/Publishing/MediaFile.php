<?php

namespace KaiHempel\Mastodon\Publishing;

use finfo;
use Illuminate\Support\Facades\Storage;
use KaiHempel\Mastodon\Exceptions\InvalidMediaException;

/**
 * A file to upload. Files are only read from allowed disks
 * (mastodon.media.allowed_disks) or allowed directories
 * (mastodon.media.allowed_paths). The MIME type is detected from the file
 * contents, never from the file name.
 */
final class MediaFile
{
    private ?string $contents = null;

    /** @param array<string, string> $source serializable origin of the file */
    private function __construct(
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $size,
        private readonly array $source,
        ?string $contents = null,
    ) {
        $this->contents = $contents;
    }

    public static function fromPath(string $path): self
    {
        $real = realpath($path);

        if ($real === false || ! is_file($real) || ! is_readable($real)) {
            throw new InvalidMediaException("The file {$path} does not exist or is not readable.");
        }

        if (! self::isAllowedPath($real)) {
            throw new InvalidMediaException("The file {$path} is outside of the allowed media paths.");
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($real) ?: 'application/octet-stream';

        return new self(basename($real), $mime, (int) filesize($real), ['type' => 'path', 'path' => $real]);
    }

    public static function fromDisk(string $disk, string $path): self
    {
        $allowed = (array) config('mastodon.media.allowed_disks', []);

        if (! in_array($disk, $allowed, true)) {
            throw new InvalidMediaException("The disk {$disk} is not allowed for media uploads.");
        }

        $filesystem = Storage::disk($disk);

        if (! $filesystem->exists($path)) {
            throw new InvalidMediaException("The file {$path} does not exist on disk {$disk}.");
        }

        $contents = (string) $filesystem->get($path);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream';

        return new self(basename($path), $mime, strlen($contents), ['type' => 'disk', 'disk' => $disk, 'path' => $path], $contents);
    }

    /** In-memory contents, e.g. generated images. */
    public static function fromContents(string $contents, string $filename): self
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream';

        return new self(basename($filename), $mime, strlen($contents), ['type' => 'contents', 'filename' => basename($filename)], $contents);
    }

    public function contents(): string
    {
        if ($this->contents === null) {
            $contents = @file_get_contents($this->source['path']);

            if ($contents === false) {
                throw new InvalidMediaException("The file {$this->filename} could not be read.");
            }

            $this->contents = $contents;
        }

        return $this->contents;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    public function isVideoOrAudio(): bool
    {
        return str_starts_with($this->mimeType, 'video/') || str_starts_with($this->mimeType, 'audio/');
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->source['type'] === 'contents'
            ? $this->source + ['contents' => base64_encode($this->contents())]
            : $this->source;
    }

    /** @param array<string, string> $data */
    public static function fromArray(array $data): self
    {
        return match ($data['type'] ?? null) {
            'path' => self::fromPath($data['path']),
            'disk' => self::fromDisk($data['disk'], $data['path']),
            'contents' => self::fromContents((string) base64_decode($data['contents'], true), $data['filename']),
            default => throw new InvalidMediaException('Unknown media source.'),
        };
    }

    private static function isAllowedPath(string $real): bool
    {
        foreach ((array) config('mastodon.media.allowed_paths', []) as $root) {
            $root = realpath((string) $root);

            if ($root !== false && ($real === $root || str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))) {
                return true;
            }
        }

        return false;
    }
}
