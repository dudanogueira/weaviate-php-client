<?php

declare(strict_types=1);

namespace Weaviate\Client\Connect\Auth;

use Weaviate\Client\Exceptions\InvalidInputException;

/**
 * Sent as `Authorization: Bearer <key>` on REST and as `authorization` metadata on gRPC.
 *
 * The key isn't stored in a property: it's kept in a private static WeakMap keyed by this object, so it
 * never shows up in var_dump, print_r, var_export, json_encode or serialize output.
 */
final class ApiKey implements AuthCredentials, \JsonSerializable
{
    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $keys = null;

    public function __construct(#[\SensitiveParameter] string $apiKey)
    {
        if (trim($apiKey) === '') {
            throw new InvalidInputException('API key must not be empty');
        }
        self::$keys ??= new \WeakMap();
        self::$keys[$this] = $apiKey;
    }

    public function authorizationHeader(): string
    {
        return 'Bearer ' . (self::$keys[$this] ?? '');
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['apiKey' => '***'];
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return ['apiKey' => '***'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Credentials cannot be serialized');
    }

    /** A clone would lose the key (it isn't a property), so cloning is disallowed. */
    private function __clone() {}
}
