<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Weaviate\Client\Connect\AdditionalConfig;
use Weaviate\Client\Connect\Auth;
use Weaviate\Client\Connect\ConnectionParams;
use Weaviate\Client\Weaviate;
use Weaviate\Client\WeaviateClient;

/**
 * QA F2: API keys and provider keys must not leak through dumps, JSON, serialization or exception traces.
 */
final class SecretRedactionTest extends TestCase
{
    private const KEY = 'TOPSECRET-weaviate-key';
    private const PROVIDER_KEY = 'sk-TOPSECRET-openai';

    public function testDumpsOfTheClientAreRedacted(): void
    {
        $client = new WeaviateClient(
            ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 50051, false),
            auth: self::KEY,
            headers: ['X-OpenAI-Api-Key' => self::PROVIDER_KEY],
            additionalConfig: new AdditionalConfig(proxies: 'http://user:proxy-password@proxy:3128'),
        );

        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean();
        $printR = print_r($client, true);

        foreach ([$dump, $printR] as $output) {
            self::assertStringNotContainsString(self::KEY, $output);
            self::assertStringNotContainsString(self::PROVIDER_KEY, $output);
            self::assertStringContainsString('***', $output);
        }
    }

    public function testApiKeyNeverSerializesItsSecret(): void
    {
        $key = Auth::apiKey(self::KEY);

        ob_start();
        var_dump($key);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString(self::KEY, $dump);
        self::assertStringNotContainsString(self::KEY, print_r($key, true));
        self::assertStringNotContainsString(self::KEY, var_export($key, true));
        self::assertStringNotContainsString(self::KEY, (string) json_encode($key));
        self::assertSame('Bearer ' . self::KEY, $key->authorizationHeader());

        $this->expectException(\LogicException::class);
        serialize($key);
    }

    public function testConnectFailureTraceHasNoSecrets(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            Weaviate::connectToLocal(
                host: '127.0.0.1',
                port: 1, // nothing listens here: connection refused
                grpcPort: 2,
                headers: ['X-OpenAI-Api-Key' => self::PROVIDER_KEY],
                additionalConfig: new AdditionalConfig(timeout: [2, 2]),
                auth: self::KEY,
            );
            self::fail('expected the connection to fail');
        } catch (\Throwable $e) {
            for ($current = $e; $current !== null; $current = $current->getPrevious()) {
                $trace = print_r($current->getTrace(), true) . $current->getTraceAsString() . $current->getMessage();
                self::assertStringNotContainsString(self::KEY, $trace, $current::class);
                self::assertStringNotContainsString(self::PROVIDER_KEY, $trace, $current::class);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }
}
