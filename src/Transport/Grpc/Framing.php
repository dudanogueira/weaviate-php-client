<?php

declare(strict_types=1);

namespace Weaviate\Client\Transport\Grpc;

use Weaviate\Client\Exceptions\ConnectionException;

/**
 * gRPC length-prefixed message framing: 1 byte "compressed" flag, 4 bytes big-endian length, then the payload.
 *
 * @internal
 */
final class Framing
{
    private const HEADER_LENGTH = 5;

    public static function encode(string $payload): string
    {
        return "\x00" . pack('N', \strlen($payload)) . $payload;
    }

    /**
     * Splits a response body into message payloads.
     *
     * @return list<string>
     */
    public static function decode(string $body, int $maxMessageLength): array
    {
        $messages = [];
        $offset = 0;
        $total = \strlen($body);

        while ($offset < $total) {
            if ($total - $offset < self::HEADER_LENGTH) {
                throw new ConnectionException('Truncated gRPC frame header');
            }
            $compressed = \ord($body[$offset]);
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('N', $body, $offset + 1);
            $length = $unpacked[1];

            if ($compressed !== 0) {
                // We never send grpc-accept-encoding, so a compliant server doesn't compress.
                throw new ConnectionException('Received a compressed gRPC message, which is not supported');
            }
            if ($length > $maxMessageLength) {
                throw new ConnectionException(\sprintf(
                    'gRPC message of %d bytes exceeds the maximum of %d bytes',
                    $length,
                    $maxMessageLength,
                ));
            }
            if ($total - $offset - self::HEADER_LENGTH < $length) {
                throw new ConnectionException('Truncated gRPC message');
            }

            $messages[] = substr($body, $offset + self::HEADER_LENGTH, $length);
            $offset += self::HEADER_LENGTH + $length;
        }

        return $messages;
    }

    /**
     * Formats a `grpc-timeout` header value: at most 8 digits plus a unit (m = milliseconds, S = seconds, M = minutes).
     */
    public static function timeoutHeader(float $seconds): string
    {
        $milliseconds = (int) ceil($seconds * 1000);
        if ($milliseconds < 100_000_000) {
            return max(1, $milliseconds) . 'm';
        }
        $wholeSeconds = (int) ceil($seconds);
        if ($wholeSeconds < 100_000_000) {
            return $wholeSeconds . 'S';
        }

        return min(99_999_999, (int) ceil($seconds / 60)) . 'M';
    }
}
