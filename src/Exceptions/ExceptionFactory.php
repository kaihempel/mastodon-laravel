<?php

namespace KaiHempel\Mastodon\Exceptions;

use Illuminate\Http\Client\Response;

final class ExceptionFactory
{
    public static function fromResponse(Response $response): MastodonException
    {
        $status = $response->status();

        return match (true) {
            $status === 401 => AuthenticationException::fromResponse($response),
            $status === 403 => AuthorizationException::fromResponse($response),
            $status === 404 => NotFoundException::fromResponse($response),
            $status === 410 => GoneException::fromResponse($response),
            $status === 422 => ValidationException::fromResponse($response),
            $status === 429 => RateLimitException::fromResponse($response),
            $status >= 500 => ServerException::fromResponse($response),
            default => MastodonException::fromResponse($response),
        };
    }
}
