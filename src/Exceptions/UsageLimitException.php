<?php

declare(strict_types=1);

namespace Weaviate\Client\Exceptions;

/**
 * The server refused the request because of a rate or usage limit (HTTP 429), e.g. Weaviate Cloud's
 * `USAGE_LIMIT_EXCEEDED` ("collections count limit of 1 reached"). `$errorCode` tells a hard usage limit
 * apart from a transient rate limit; retry policies must not blindly retry the former.
 */
class UsageLimitException extends UnexpectedStatusCodeException
{
    public function errorCode(): ?string
    {
        $code = \is_array($this->body) ? ($this->body['errorCode'] ?? null) : null;

        return \is_string($code) ? $code : null;
    }
}
