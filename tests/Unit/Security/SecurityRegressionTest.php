<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Weaviate\Client\Connect\AdditionalConfig;
use Weaviate\Client\Connect\Auth;
use Weaviate\Client\Connect\ConnectionParams;
use Weaviate\Client\Connect\GrpcTransportChoice;
use Weaviate\Client\Connect\SecretHeaders;
use Weaviate\Client\Connect\Timeout;
use Weaviate\Client\Exceptions\ConnectionException;
use Weaviate\Client\Exceptions\InvalidInputException;
use Weaviate\Client\Exceptions\WeaviateException;
use Weaviate\Client\Exceptions\WeaviateStartUpException;
use Weaviate\Client\Tests\Support\FakeHttpClient;
use Weaviate\Client\Tests\Support\LocalHttpServer;
use Weaviate\Client\Transport\Grpc\CurlGrpcTransport;
use Weaviate\Client\Transport\Rest\RestTransport;
use Weaviate\Client\Weaviate;
use Weaviate\Client\WeaviateClient;

/**
 * Regression tests for the 2026-09-25 security review (docs/security/2026-09-25-review.md). Each test names
 * the finding it covers.
 */
final class SecurityRegressionTest extends TestCase
{
    private const KEY = 'TOPSECRET-weaviate-key';
    private const PROVIDER_KEY = 'sk-TOPSECRET-openai';

    /** S1: a redirect must never carry provider keys (or anything else) to another host. */
    public function testRedirectsAreNotFollowed(): void
    {
        $attacker = new LocalHttpServer(['MODE' => 'meta']);
        $weaviate = new LocalHttpServer(['MODE' => 'redirect', 'REDIRECT_TO' => 'http://127.0.0.1:' . $attacker->port]);

        try {
            Weaviate::connectToLocal(
                host: '127.0.0.1',
                port: $weaviate->port,
                grpcPort: 1,
                headers: ['X-OpenAI-Api-Key' => self::PROVIDER_KEY],
                skipInitChecks: true,
                auth: self::KEY,
            );
            self::fail('a redirect must not be followed');
        } catch (WeaviateStartUpException $e) {
            self::assertStringContainsString('redirect (307)', $e->getMessage());
        }

        self::assertSame('', $attacker->requestLog(), 'the redirect target received a request');
        self::assertStringContainsString(self::PROVIDER_KEY, $weaviate->requestLog(), 'sanity check: the original server got the headers');
    }

    /** S2: a small gzip response must not inflate in memory. */
    public function testCompressedResponsesAreNotInflated(): void
    {
        $server = new LocalHttpServer(['MODE' => 'gzip-bomb']);
        $before = memory_get_peak_usage(true);

        try {
            Weaviate::connectToLocal(host: '127.0.0.1', port: $server->port, grpcPort: 1, skipInitChecks: true);
            self::fail('the compressed body is not a Weaviate meta response');
        } catch (WeaviateException) {
        }

        self::assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $before, 'the gzip body was decompressed in memory');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function oversizedModes(): iterable
    {
        yield 'Content-Length announced' => ['big-declared'];
        yield 'chunked, no Content-Length' => ['big-chunked'];
    }

    /** S2/S8: REST bodies are capped whether or not the server announces their size. */
    #[DataProvider('oversizedModes')]
    public function testOversizedRestResponsesAreRejected(string $mode): void
    {
        $server = new LocalHttpServer(['MODE' => $mode]);

        try {
            Weaviate::connectToLocal(
                host: '127.0.0.1',
                port: $server->port,
                grpcPort: 1,
                additionalConfig: new AdditionalConfig(maxResponseBytes: 1024 * 1024),
                skipInitChecks: true,
            );
            self::fail('expected the size cap to trigger');
        } catch (WeaviateStartUpException $e) {
            self::assertStringContainsString('exceeds the maximum of 1048576 bytes', $e->getMessage());
        }
    }

    /** S8: the server can't raise the gRPC message limit above the client's ceiling. */
    public function testServerCannotRaiseTheGrpcLimitAboveTheCeiling(): void
    {
        $http = FakeHttpClient::weaviate('1.39.7', ['grpcMaxMessageSize' => 10 * 1024 * 1024 * 1024]);
        $client = new WeaviateClient(
            ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 50051, false),
            additionalConfig: new AdditionalConfig(grpcTransport: GrpcTransportChoice::Curl, httpClient: $http, maxResponseBytes: 8 * 1024 * 1024),
            skipInitChecks: true,
        );
        $client->connect();

        $grpc = $client->grpcTransport();
        self::assertInstanceOf(CurlGrpcTransport::class, $grpc);
        self::assertSame(8 * 1024 * 1024, $grpc->maxMessageLength());
    }

    /** S3: nested exceptions (the gRPC failure behind a startup error) must not hold metadata in their traces. */
    public function testNestedGrpcExceptionTracesHaveNoSecrets(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $client = new WeaviateClient(
                ConnectionParams::fromParams('127.0.0.1', 8080, false, '127.0.0.1', 1, false),
                auth: self::KEY,
                headers: ['X-OpenAI-Api-Key' => self::PROVIDER_KEY],
                additionalConfig: new AdditionalConfig(timeout: new Timeout(init: 2), grpcTransport: GrpcTransportChoice::Curl, httpClient: FakeHttpClient::weaviate()),
            );
            $client->connect();
            self::fail('the gRPC health check must fail on port 1');
        } catch (\Throwable $e) {
            $depth = 0;
            for ($current = $e; $current !== null; $current = $current->getPrevious()) {
                ++$depth;
                $trace = print_r($current->getTrace(), true) . $current->getMessage();
                self::assertStringNotContainsString(self::KEY, $trace, $current::class);
                self::assertStringNotContainsString(self::PROVIDER_KEY, $trace, $current::class);
            }
            self::assertGreaterThanOrEqual(2, $depth, 'the test must exercise a chained exception');
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    /** S4: no dump, cast or serialization of the client or its transport exposes credentials. */
    public function testClientStateNeverExposesCredentials(): void
    {
        $http = FakeHttpClient::weaviate();
        $grpc = \Weaviate\Client\Tests\Support\FakeGrpcTransport::serving();
        $client = new WeaviateClient(
            ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 50051, false),
            auth: self::KEY,
            headers: ['X-OpenAI-Api-Key' => self::PROVIDER_KEY],
            additionalConfig: new AdditionalConfig(httpClient: $http, grpcTransport: $grpc),
        );
        $client->connect();
        // The test doubles record requests (with headers); real PSR-18 clients and transports don't.
        $http->requests = [];
        $grpc->calls = [];

        $export = static fn(mixed $value): string => (string) @var_export($value, true); // tolerate circular refs
        $outputs = [
            'var_export(client)' => $export($client),
            '(array) client' => print_r((array) $client, true),
            'var_export(rest)' => $export($client->restTransport()),
            '(array) rest' => print_r((array) $client->restTransport(), true),
            'json_encode(headers)' => (string) json_encode(new SecretHeaders(['authorization' => 'Bearer ' . self::KEY])),
        ];
        foreach ($outputs as $label => $output) {
            self::assertStringNotContainsString(self::KEY, $output, $label);
            self::assertStringNotContainsString(self::PROVIDER_KEY, $output, $label);
        }

        foreach ([$client, $client->restTransport(), new SecretHeaders(['x' => 'y'])] as $object) {
            try {
                serialize($object);
                self::fail($object::class . ' must refuse serialization');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** S5: settings that can't be applied to an injected client are reported, not silently dropped. */
    public function testProxySettingsOnAnInjectedClientAreReported(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $client = new WeaviateClient(
            ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 50051, false),
            additionalConfig: new AdditionalConfig(proxies: 'http://proxy:3128', httpClient: FakeHttpClient::weaviate(), logger: $logger, grpcTransport: \Weaviate\Client\Tests\Support\FakeGrpcTransport::serving()),
        );
        $client->connect();

        self::assertContains(
            'AdditionalConfig proxies/trustEnv are not applied to an injected PSR-18 client; configure the proxy on that client.',
            $logger->messages,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafePaths(): iterable
    {
        yield 'parent traversal' => ['/schema/../../admin'];
        yield 'dot segment' => ['/schema/./x'];
        yield 'query injection' => ['/schema/Foo?consistency_level=ALL'];
        yield 'fragment truncation' => ['/schema/Foo#'];
        yield 'no leading slash' => ['schema/Foo'];
        yield 'whitespace' => ['/schema/Foo Bar'];
        yield 'control character' => ["/schema/Foo\r\n"];
        yield 'backslash' => ['/schema/..\\admin'];
    }

    /** S6: unsafe paths are refused before any request. */
    #[DataProvider('unsafePaths')]
    public function testUnsafePathsAreRejected(string $path): void
    {
        $http = FakeHttpClient::weaviate();
        $rest = new RestTransport('http://localhost:8080', new SecretHeaders(), new Timeout(), $http);

        try {
            $rest->request('GET', $path);
            self::fail('expected InvalidInputException');
        } catch (InvalidInputException) {
            self::assertSame([], $http->requests, 'nothing may be sent');
        }
    }

    /** S6: the path builder encodes every segment. */
    public function testPathBuilderEncodesSegments(): void
    {
        self::assertSame('/schema/My%20Class/tenants', RestTransport::path('schema', 'My Class', 'tenants'));
        self::assertSame('/schema/..%2Fadmin', RestTransport::path('schema', '../admin'));
        self::assertSame('/schema/Foo%3Fx%3D1%23', RestTransport::path('schema', 'Foo?x=1#'));

        $this->expectException(InvalidInputException::class);
        RestTransport::path('schema', '..');
    }

    /** S7: the API key becomes a header value, so line breaks are rejected. */
    public function testApiKeyWithLineBreaksIsRejected(): void
    {
        $this->expectException(InvalidInputException::class);
        Auth::apiKey("key\r\nx-evil: 1");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function spoofedCloudUrls(): iterable
    {
        yield 'userinfo' => ['https://x.weaviate.cloud@evil.example'];
        yield 'fragment' => ['https://evil.example#.weaviate.cloud'];
        yield 'user and password' => ['https://user:pass@abc.weaviate.cloud'];
    }

    /** S14: URLs that look like a cluster but point elsewhere are rejected. */
    #[DataProvider('spoofedCloudUrls')]
    public function testSpoofedCloudUrlsAreRejected(string $url): void
    {
        $this->expectException(InvalidInputException::class);
        Weaviate::parseCloudUrl($url);
    }

    /** S14: X-Weaviate-Cluster-URL only goes to real Weaviate domains (suffix match). */
    public function testClusterUrlHeaderUsesASuffixMatch(): void
    {
        $spoof = new WeaviateClient(ConnectionParams::fromParams('weaviate.io.evil.example', 443, true, 'grpc.evil.example', 443, true));
        $real = new WeaviateClient(ConnectionParams::fromParams('abc.c0.europe-west3.gcp.weaviate.cloud', 443, true, 'grpc-abc.c0.europe-west3.gcp.weaviate.cloud', 443, true));

        self::assertArrayNotHasKey('x-weaviate-cluster-url', $spoof->grpcMetadata());
        self::assertSame('https://abc.c0.europe-west3.gcp.weaviate.cloud', $real->grpcMetadata()['x-weaviate-cluster-url'] ?? null);
    }

    /** ConnectionException stays the parent of transport failures surfaced through connect(). */
    public function testStartupFailuresKeepTheConnectionCause(): void
    {
        $server = new LocalHttpServer(['MODE' => 'redirect', 'REDIRECT_TO' => 'http://127.0.0.1:1']);

        try {
            Weaviate::connectToLocal(host: '127.0.0.1', port: $server->port, grpcPort: 1, skipInitChecks: true);
            self::fail('expected a startup failure');
        } catch (WeaviateStartUpException $e) {
            self::assertInstanceOf(ConnectionException::class, $e->getPrevious());
        }
    }
}
