<?php

namespace KaiHempel\Mastodon\Publishing;

use Carbon\CarbonImmutable;
use KaiHempel\Mastodon\Data\InstanceInfo;
use KaiHempel\Mastodon\Enums\Visibility;
use KaiHempel\Mastodon\Exceptions\ValidationException;

/**
 * Validates a status against the limits of the target instance before any
 * request is sent.
 */
class StatusValidator
{
    /** Minimum lead time for scheduled_at required by the API. */
    public const MIN_SCHEDULE_SECONDS = 300;

    /**
     * @throws ValidationException
     */
    public function validate(PendingStatus $status, InstanceInfo $instance): void
    {
        $errors = $this->errors($status, $instance);

        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(PendingStatus $status, InstanceInfo $instance): array
    {
        $errors = [];
        $add = function (string $field, string $message) use (&$errors): void {
            $errors[$field][] = $message;
        };

        $text = $status->getStatus();
        $media = $status->getMedia();
        $poll = $status->getPoll();

        // Text and length
        if (trim($text) === '' && $media === []) {
            $add('status', 'The text may only be empty when media is attached.');
        }

        $length = CharacterCounter::count($text, $instance->charactersReservedPerUrl)
            + mb_strlen($status->getSpoilerText() ?? '', 'UTF-8');

        if ($length > $instance->maxCharacters) {
            $add('status', "The text is {$length} characters long, the instance allows {$instance->maxCharacters}.");
        }

        // Visibility and language
        if ($status->getVisibility() !== null && ! in_array($status->getVisibility(), Visibility::values(), true)) {
            $add('visibility', 'The visibility must be one of: '.implode(', ', Visibility::values()).'.');
        }

        if ($status->getLanguage() !== null && ! preg_match('/^[a-z]{2}$/', $status->getLanguage())) {
            $add('language', 'The language must be an ISO 639-1 code (two lower-case letters).');
        }

        // Media
        if ($media !== [] && $poll !== null) {
            $add('poll', 'A status cannot contain both media and a poll.');
        }

        if (count($media) > $instance->maxMediaAttachments) {
            $add('media', 'At most '.$instance->maxMediaAttachments.' media attachments are allowed, '.count($media).' given.');
        }

        foreach ($media as $index => $item) {
            $this->validateMedia($item, $instance, fn (string $message) => $add("media.{$index}", $message));
        }

        // Poll
        if ($poll !== null) {
            $this->validatePoll($poll, $instance, fn (string $message) => $add('poll', $message));
        }

        // Scheduling
        $scheduledAt = $status->getScheduledAt();
        if ($scheduledAt !== null && $scheduledAt->lt(CarbonImmutable::now()->addSeconds(self::MIN_SCHEDULE_SECONDS))) {
            $add('scheduled_at', 'The scheduled time must be at least 5 minutes in the future.');
        }

        return $errors;
    }

    /**
     * @param  array{file: ?MediaFile, id: ?string, description: ?string, focus: ?array<int, float>, thumbnail: ?MediaFile}  $item
     * @param  callable(string): void  $add
     */
    private function validateMedia(array $item, InstanceInfo $instance, callable $add): void
    {
        $file = $item['file'];

        if ($file !== null) {
            if (! $instance->isMimeTypeSupported($file->mimeType)) {
                $add("The MIME type {$file->mimeType} is not supported by the instance.");
            }

            $limit = match (true) {
                $file->isImage() => $instance->imageSizeLimit,
                $file->isVideoOrAudio() => $instance->videoSizeLimit,
                default => null,
            };

            if ($limit !== null && $file->size > $limit) {
                $add("The file is {$file->size} bytes, the limit is {$limit} bytes.");
            }
        }

        $description = $item['description'];
        if ($description !== null && $instance->descriptionLimit !== null && mb_strlen($description, 'UTF-8') > $instance->descriptionLimit) {
            $add("The description may have at most {$instance->descriptionLimit} characters.");
        }

        if ($item['focus'] !== null) {
            $focus = array_values($item['focus']);
            if (count($focus) !== 2 || ! is_numeric($focus[0]) || ! is_numeric($focus[1])
                || abs((float) $focus[0]) > 1.0 || abs((float) $focus[1]) > 1.0) {
                $add('The focus must be two numbers between -1.0 and 1.0.');
            }
        }
    }

    /**
     * @param  array{options: list<string>, expires_in: int, multiple: bool, hide_totals: bool}  $poll
     * @param  callable(string): void  $add
     */
    private function validatePoll(array $poll, InstanceInfo $instance, callable $add): void
    {
        $options = $poll['options'];

        if (count($options) < 2) {
            $add('A poll needs at least 2 options.');
        }

        if (count($options) > $instance->pollMaxOptions) {
            $add("A poll may have at most {$instance->pollMaxOptions} options.");
        }

        foreach ($options as $i => $option) {
            if (trim($option) === '') {
                $add('Poll option '.($i + 1).' must not be empty.');
            } elseif (mb_strlen($option, 'UTF-8') > $instance->pollMaxCharactersPerOption) {
                $add('Poll option '.($i + 1)." may have at most {$instance->pollMaxCharactersPerOption} characters.");
            }
        }

        if (count(array_unique($options)) !== count($options)) {
            $add('Poll options must be unique.');
        }

        if ($poll['expires_in'] < $instance->pollMinExpiration || $poll['expires_in'] > $instance->pollMaxExpiration) {
            $add("The poll duration must be between {$instance->pollMinExpiration} and {$instance->pollMaxExpiration} seconds.");
        }
    }
}
