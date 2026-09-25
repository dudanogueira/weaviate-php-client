<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Unit\Connect;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weaviate\Client\Connect\ConnectionParams;
use Weaviate\Client\Connect\Headers;
use Weaviate\Client\Exceptions\InvalidInputException;
use Weaviate\Client\WeaviateClient;

/**
 * QA F1/F14: header names and values are validated before any request, so a value can't inject extra
 * headers on the wire (gRPC metadata went out as two headers for "a\r\nx-injected: b").
 */
final class HeadersTest extends TestCase
{
    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidHeaders(): iterable
    {
        yield 'CRLF in value' => [['X-Key' => "a\r\nx-injected: b"], 'line break'];
        yield 'LF in value' => [['X-Key' => "a\nauthorization: Bearer evil"], 'line break'];
        yield 'CR in value' => [['X-Key' => "a\rb"], 'line break'];
        yield 'NUL in value' => [['X-Key' => "a\0b"], 'NUL'];
        yield 'space in name' => [['X Key' => 'v'], 'Invalid header name'];
        yield 'colon in name' => [['X-Key:' => 'v'], 'Invalid header name'];
        yield 'empty name' => [['' => 'v'], 'Invalid header name'];
        yield 'list-style header' => [['X-Foo: bar'], 'name => value'];
        yield 'int value' => [['X-Key' => 42], 'must be a string'];
        yield 'null value' => [['X-Key' => null], 'cannot be null'];
        yield 'reserved te' => [['TE' => 'trailers'], "can't be overridden"];
        yield 'reserved content-type' => [['Content-Type' => 'text/plain'], "can't be overridden"];
        yield 'reserved grpc-timeout' => [['grpc-timeout' => '1S'], "can't be overridden"];
    }

    /**
     * @param array<mixed> $headers
     */
    #[DataProvider('invalidHeaders')]
    public function testInvalidHeadersAreRejectedAtConstruction(array $headers, string $message): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage($message);

        // Deliberately invalid input, so it doesn't match the declared array<string, string|null>.
        new WeaviateClient(ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 50051, false), headers: $headers); // @phpstan-ignore argument.type
    }

    public function testNamesAreLowercasedAndValuesKept(): void
    {
        self::assertSame(['x-openai-api-key' => 'sk-1', 'x-custom' => 'a b;c=d'], Headers::normalize(['X-OpenAI-Api-Key' => 'sk-1', 'X-Custom' => 'a b;c=d']));
    }

    public function testUserCannotSpoofTheClientHeader(): void
    {
        $client = new WeaviateClient(
            ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 50051, false),
            headers: ['X-Weaviate-Client' => 'spoofed'],
        );

        self::assertStringStartsWith('weaviate-client-php/', $client->grpcMetadata()['x-weaviate-client']);
    }

    public function testRedaction(): void
    {
        $redacted = Headers::redact([
            'authorization' => 'Bearer secret',
            'x-openai-api-key' => 'sk-secret',
            'x-cohere-api-key' => 'c-secret',
            'x-aws-secret-key' => 's',
            'x-azure-deployment-id' => 'not-secret',
            'x-weaviate-client' => 'weaviate-client-php/x',
        ]);

        self::assertSame('***', $redacted['authorization']);
        self::assertSame('***', $redacted['x-openai-api-key']);
        self::assertSame('***', $redacted['x-cohere-api-key']);
        self::assertSame('***', $redacted['x-aws-secret-key']);
        self::assertSame('not-secret', $redacted['x-azure-deployment-id']);
        self::assertSame('weaviate-client-php/x', $redacted['x-weaviate-client']);
    }
}
