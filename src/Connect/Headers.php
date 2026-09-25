<?php

declare(strict_types=1);

namespace Weaviate\Client\Connect;

use Weaviate\Client\Exceptions\InvalidInputException;

/**
 * Header validation and redaction shared by REST and gRPC metadata.
 *
 * @internal
 */
final class Headers
{
    /** Keys the transports set themselves; user values would duplicate or override protocol headers. */
    private const RESERVED = ['content-type', 'content-length', 'te', 'user-agent', 'grpc-timeout', 'grpc-encoding', 'grpc-accept-encoding', 'host', 'connection', 'transfer-encoding'];

    private const REDACTED = '***';

    private function __construct() {}

    /**
     * Validates user headers and returns them with lowercase names.
     *
     * Names must be HTTP tokens; values must not contain CR, LF or NUL, which would let a value inject extra
     * headers on the wire (a gRPC metadata value "a\r\nauthorization: …" was sent as two headers otherwise).
     *
     * @param array<mixed> $headers
     *
     * @return array<string, string>
     */
    public static function normalize(#[\SensitiveParameter] array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!\is_string($name)) {
                throw new InvalidInputException('headers must be an array of name => value, e.g. [\'X-OpenAI-Api-Key\' => \'…\']');
            }
            $lower = strtolower($name);
            if (preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/', $lower) !== 1) {
                throw new InvalidInputException(\sprintf("Invalid header name '%s'.", $name));
            }
            if (\in_array($lower, self::RESERVED, true)) {
                throw new InvalidInputException(\sprintf("Header '%s' is set by the client and can't be overridden.", $name));
            }
            if ($value === null) {
                throw new InvalidInputException(\sprintf("Value for key '%s' in headers cannot be null.", $name));
            }
            if (!\is_string($value)) {
                throw new InvalidInputException(\sprintf("Value for key '%s' in headers must be a string.", $name));
            }
            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new InvalidInputException(\sprintf("Value for key '%s' in headers contains a line break or NUL byte.", $name));
            }
            $normalized[$lower] = $value;
        }

        return $normalized;
    }

    /**
     * Whether a header carries a credential and must never appear in logs, dumps or exception messages.
     */
    public static function isSensitive(string $name): bool
    {
        $lower = strtolower($name);

        return $lower === 'authorization'
            || $lower === 'proxy-authorization'
            || str_contains($lower, 'api-key')
            || str_contains($lower, 'apikey')
            || str_contains($lower, 'secret')
            || str_contains($lower, 'token')
            || str_contains($lower, 'password')
            || str_ends_with($lower, '-key');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    public static function redact(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (self::isSensitive($name)) {
                $headers[$name] = self::REDACTED;
            }
        }

        return $headers;
    }
}
