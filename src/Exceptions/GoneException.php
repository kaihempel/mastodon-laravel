<?php

namespace KaiHempel\Mastodon\Exceptions;

/**
 * Account suspended (HTTP 410, older Mastodon versions).
 */
class GoneException extends MastodonException
{
}
