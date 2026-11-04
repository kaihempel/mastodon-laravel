<?php

namespace KaiHempel\Mastodon\Exceptions;

/**
 * OAuth flow failed (registration, state check, token exchange or scope mismatch).
 */
class OAuthException extends MastodonException
{
}
