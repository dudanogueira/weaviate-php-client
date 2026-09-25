<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weaviate\Client\Connect\Auth;
use Weaviate\Client\Connect\ConnectionParams;
use Weaviate\Client\Exceptions\InvalidInputException;
use Weaviate\Client\ServerVersion;
use Weaviate\Client\Version;
use Weaviate\Client\Weaviate;
use Weaviate\Client\WeaviateClient;

final class WeaviateTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function cloudUrls(): iterable
    {
        yield 'bare host' => ['abc123.c0.europe-west3.gcp.weaviate.cloud', 'abc123.c0.europe-west3.gcp.weaviate.cloud', 'grpc-abc123.c0.europe-west3.gcp.weaviate.cloud'];
        yield 'pasted URL' => ['https://abc123.weaviate.cloud/', 'abc123.weaviate.cloud', 'grpc-abc123.weaviate.cloud'];
        yield 'legacy WCS domain' => ['my-cluster.weaviate.network', 'my-cluster.weaviate.network', 'my-cluster.grpc.weaviate.network'];
        yield 'uppercase scheme' => ['HTTPS://abc.weaviate.cloud', 'abc.weaviate.cloud', 'grpc-abc.weaviate.cloud'];
        yield 'uppercase legacy domain' => ['MY-CLUSTER.WEAVIATE.NETWORK', 'my-cluster.weaviate.network', 'my-cluster.grpc.weaviate.network'];
        yield 'bare host starting with http' => ['httpbin.weaviate.cloud', 'httpbin.weaviate.cloud', 'grpc-httpbin.weaviate.cloud'];
        yield 'whitespace and newline from env files' => ["  abc.weaviate.cloud\n", 'abc.weaviate.cloud', 'grpc-abc.weaviate.cloud'];
        yield 'trailing slash on URL' => ['https://abc.weaviate.cloud/', 'abc.weaviate.cloud', 'grpc-abc.weaviate.cloud'];
    }

    #[DataProvider('cloudUrls')]
    public function testCloudUrlParsing(string $input, string $httpHost, string $grpcHost): void
    {
        self::assertSame([$httpHost, $grpcHost], Weaviate::parseCloudUrl($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCloudUrls(): iterable
    {
        yield 'path' => ['https://abc.weaviate.cloud/v1', 'without port, path or query'];
        yield 'port' => ['https://abc.weaviate.cloud:8443', 'without port, path or query'];
        yield 'query' => ['https://abc.weaviate.cloud/?x=1', 'without port, path or query'];
        yield 'gRPC host pasted' => ['grpc-abc.weaviate.cloud', 'not the gRPC host'];
        yield 'empty' => ['  ', 'Invalid cluster URL'];
        yield 'bare host with path' => ['abc.weaviate.cloud/v1', 'Invalid cluster URL'];
    }

    #[DataProvider('invalidCloudUrls')]
    public function testInvalidCloudUrlsAreRejected(string $input, string $message): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage($message);
        Weaviate::parseCloudUrl($input);
    }

    public function testHeadersAreLowercasedAndForwardedAsGrpcMetadata(): void
    {
        $client = new WeaviateClient(
            ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 50051, false),
            auth: 'secret-key',
            headers: ['X-OpenAI-Api-Key' => 'sk-test'],
        );

        self::assertSame([
            'x-weaviate-client' => 'weaviate-client-php/' . Version::CLIENT . '-sync',
            'x-openai-api-key' => 'sk-test',
            'authorization' => 'Bearer secret-key',
        ], $client->grpcMetadata());
    }

    public function testClusterUrlHeaderOnlyForWeaviateDomains(): void
    {
        $cloud = new WeaviateClient(ConnectionParams::fromParams('abc.weaviate.cloud', 443, true, 'grpc-abc.weaviate.cloud', 443, true));
        $local = new WeaviateClient(ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 50051, false));

        self::assertSame('https://abc.weaviate.cloud', $cloud->grpcMetadata()['x-weaviate-cluster-url'] ?? null);
        self::assertArrayNotHasKey('x-weaviate-cluster-url', $local->grpcMetadata());
    }

    public function testAuthCredentialsWinOverAnAuthorizationHeader(): void
    {
        $client = new WeaviateClient(
            ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 50051, false),
            auth: Auth::apiKey('from-auth'),
            headers: ['Authorization' => 'Bearer from-header'],
        );

        self::assertSame('Bearer from-auth', $client->grpcMetadata()['authorization']);
    }

    public function testServerVersionComparison(): void
    {
        $version = ServerVersion::parse('1.39.7-rc.1');

        self::assertSame('1.39.7', (string) $version);
        self::assertTrue($version->isAtLeastVersion(Version::MIN_SERVER));
        self::assertTrue($version->isAtLeast(1, 39, 7));
        self::assertFalse($version->isAtLeast(1, 39, 8));
        self::assertFalse(ServerVersion::parse('1.28.4')->isAtLeastVersion(Version::MIN_SERVER));
    }
}
