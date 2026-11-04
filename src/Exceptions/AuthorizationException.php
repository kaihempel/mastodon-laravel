<?php

namespace KaiHempel\Mastodon\Exceptions;

/**
 * Missing scope, disabled/unconfirmed account or action not allowed (HTTP 403).
 */
class AuthorizationException extends MastodonException
{
}
