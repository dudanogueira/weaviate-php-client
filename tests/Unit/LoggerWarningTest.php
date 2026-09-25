<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Weaviate\Client\Connect\AdditionalConfig;
use Weaviate\Client\Connect\ConnectionParams;
use Weaviate\Client\WeaviateClient;

final class LoggerWarningTest extends TestCase
{
    public function testAuthorizationHeaderConflictIsLogged(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        new WeaviateClient(
            ConnectionParams::fromParams('localhost', 8080, false, 'localhost', 50051, false),
            auth: 'key',
            headers: ['Authorization' => 'Bearer other'],
            additionalConfig: new AdditionalConfig(logger: $logger),
        );

        self::assertCount(1, $logger->messages);
        self::assertStringContainsString('Authorization header', $logger->messages[0]);
    }
}
