<?php

declare(strict_types=1);

namespace Weaviate\Client\Transport\Rest;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Weaviate\Client\Connect\Timeout;
use Weaviate\Client\Exceptions\AuthenticationException;
use Weaviate\Client\Exceptions\ConnectionException;
use Weaviate\Client\Exceptions\UnexpectedStatusCodeException;

/**
 * REST over any PSR-18 client. See docs/01-architecture.md "REST".
 *
 * When no client is injected and Guzzle is installed, a Guzzle client is built with the configured timeouts.
 * Otherwise a client is discovered; per-request timeouts then depend on how that client was configured,
 * because PSR-18 has no timeout option.
 *
 * @internal
 */
final class RestTransport
{
    private const API_PREFIX = '/v1';

    private readonly ClientInterface $client;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param array<string, string> $headers lowercase header names
     */
    public function __construct(
        private readonly string $baseUrl,
        private array $headers,
        Timeout $timeout,
        ?ClientInterface $client = null,
        ?string $proxy = null,
    ) {
        $this->client = $client ?? self::defaultClient($timeout, $proxy);
        $this->requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = Psr17FactoryDiscovery::findStreamFactory();
    }

    public function setHeader(string $name, string $value): void
    {
        $this->headers[strtolower($name)] = $value;
    }

    /**
     * @param array<mixed>|null                     $body  JSON-encoded when not null
     * @param array<string, string|int|float|bool> $query booleans are sent as "true"/"false"
     *
     * @throws ConnectionException on network failures
     */
    public function request(string $method, string $path, ?array $body = null, array $query = []): RestResponse
    {
        $url = rtrim($this->baseUrl, '/') . self::API_PREFIX . $path;
        if ($query !== []) {
            $url .= '?' . self::buildQuery($query);
        }

        $request = $this->requestFactory->createRequest($method, $url);
        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $json = json_encode($body, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_SLASHES);
            $request = $request
                ->withHeader('content-type', 'application/json')
                ->withBody($this->streamFactory->createStream($json));
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ConnectionException(\sprintf('%s %s failed: %s', $method, $url, $e->getMessage()), 0, $e);
        }

        $raw = (string) $response->getBody();
        $decoded = null;
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = $raw;
            }
        }

        return new RestResponse($response->getStatusCode(), $decoded);
    }

    /**
     * Like request(), but throws unless the status is one of $expected.
     *
     * @param array<mixed>|null                     $body
     * @param array<string, string|int|float|bool> $query
     * @param list<int>                             $expected
     */
    public function requestExpecting(string $method, string $path, string $errorMessage, array $expected = [200], ?array $body = null, array $query = []): RestResponse
    {
        $response = $this->request($method, $path, $body, $query);
        if (\in_array($response->statusCode, $expected, true)) {
            return $response;
        }
        if ($response->statusCode === 401) {
            throw new AuthenticationException($errorMessage . ': unauthorized (401)');
        }

        throw new UnexpectedStatusCodeException($errorMessage, $response->statusCode, $response->body);
    }

    /**
     * @param array<string, string|int|float|bool> $query
     */
    private static function buildQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = rawurlencode($key) . '=' . rawurlencode(match (true) {
                $value === true => 'true',
                $value === false => 'false',
                default => (string) $value,
            });
        }

        return implode('&', $pairs);
    }

    private static function defaultClient(Timeout $timeout, ?string $proxy): ClientInterface
    {
        if (class_exists(\GuzzleHttp\Client::class)) {
            $options = [
                'timeout' => max($timeout->query, $timeout->insert),
                'connect_timeout' => $timeout->init,
                'http_errors' => false,
            ];
            if ($proxy !== null) {
                $options['proxy'] = $proxy;
            }

            return new \GuzzleHttp\Client($options);
        }

        return Psr18ClientDiscovery::find();
    }
}
