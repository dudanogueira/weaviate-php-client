<?php

declare(strict_types=1);

namespace Weaviate\Client\Transport\Rest;

/**
 * @internal
 */
final readonly class RestResponse
{
    /**
     * @param mixed $body decoded JSON, the raw string when the body isn't JSON, or null when empty
     */
    public function __construct(
        public int $statusCode,
        public mixed $body,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
