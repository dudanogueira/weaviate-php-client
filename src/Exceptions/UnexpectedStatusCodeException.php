<?php

declare(strict_types=1);

namespace Weaviate\Client\Exceptions;

/**
 * A REST call returned a status code the operation doesn't expect. Carries the status and the decoded body.
 */
class UnexpectedStatusCodeException extends WeaviateException
{
    /**
     * @param mixed $body decoded JSON body, or the raw string when it isn't JSON
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly mixed $body = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('%s: unexpected status code %d', $message, $statusCode), $statusCode, $previous);
    }
}
