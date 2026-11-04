<?php

namespace KaiHempel\Mastodon\Exceptions;

use Illuminate\Http\Client\Response;

/**
 * Validation failed, either locally (StatusValidator, before any request is
 * sent) or on the server (HTTP 422).
 */
class ValidationException extends MastodonException
{
    /** @var array<string, list<string>> */
    public array $errors = [];

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function withErrors(array $errors): static
    {
        $lines = [];
        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $lines[] = "{$field}: {$message}";
            }
        }

        $exception = new static('Validation failed: '.implode('; ', $lines));
        $exception->errors = $errors;

        return $exception;
    }

    public static function fromResponse(Response $response, ?string $fallback = null): static
    {
        $exception = parent::fromResponse($response, $fallback);
        $exception->errors = ['server' => [$exception->serverMessage ?? $exception->getMessage()]];

        return $exception;
    }
}
