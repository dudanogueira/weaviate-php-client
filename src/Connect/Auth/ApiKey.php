<?php

declare(strict_types=1);

namespace Weaviate\Client\Connect\Auth;

/**
 * Sent as `Authorization: Bearer <key>` on REST and as `authorization` metadata on gRPC.
 */
final readonly class ApiKey implements AuthCredentials
{
    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
    ) {}

    public function authorizationHeader(): string
    {
        return 'Bearer ' . $this->apiKey;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['apiKey' => '***'];
    }
}
