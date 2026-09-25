<?php

declare(strict_types=1);

namespace Weaviate\Client\Connect;

use Weaviate\Client\Connect\Auth\ApiKey;
use Weaviate\Client\Connect\Auth\AuthCredentials;
use Weaviate\Client\Exceptions\InvalidInputException;

/**
 * Credential factories. Python `Auth`; see docs/09-connection.md §3.
 *
 * P0 status: API key only. The OIDC flows (bearerToken, clientCredentials, clientPassword) land later in P0.
 */
final class Auth
{
    private function __construct() {}

    public static function apiKey(string $apiKey): ApiKey
    {
        return new ApiKey($apiKey);
    }

    /**
     * Python `__parse_auth_credentials`: a plain string is treated as an API key.
     */
    public static function parse(string|AuthCredentials|null $credentials): ?AuthCredentials
    {
        if (\is_string($credentials)) {
            if ($credentials === '') {
                throw new InvalidInputException('API key must not be empty');
            }

            return self::apiKey($credentials);
        }

        return $credentials;
    }
}
