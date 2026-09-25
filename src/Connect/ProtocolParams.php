<?php

declare(strict_types=1);

namespace Weaviate\Client\Connect;

use Weaviate\Client\Exceptions\InvalidInputException;

/**
 * One endpoint (REST or gRPC): host, port and whether it uses TLS. Python `ProtocolParams`.
 */
final readonly class ProtocolParams
{
    public function __construct(
        public string $host,
        public int $port,
        public bool $secure,
    ) {
        if ($host === '') {
            throw new InvalidInputException('host must not be empty');
        }
        if ($port < 0 || $port > 65535) {
            throw new InvalidInputException(\sprintf('port must be between 0 and 65535, got %d', $port));
        }
    }
}
