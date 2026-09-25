<?php

declare(strict_types=1);

namespace Weaviate\Client\Transport\Rest;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Weaviate\Client\Connect\Headers;
use Weaviate\Client\Connect\Timeout;
use Weaviate\Client\Exceptions\AuthenticationException;
use Weaviate\Client\Exceptions\ConnectionException;
use Weaviate\Client\Exceptions\InsufficientPermissionsException;
use Weaviate\Client\Exceptions\UnexpectedStatusCodeException;
use Weaviate\Client\Exceptions\UsageLimitException;

/**
 * REST over any PSR-18 client. See docs/01-architecture.md "REST".
 *
 * When no client is injected and Guzzle is installed, the client uses Guzzle directly, so each request gets
 * its own timeout (init / query / insert, docs/09-connection.md §4.1) and proxies follow `proxies`/`trustEnv`.
 * An injected PSR-18 client is used as is: PSR-18 has no per-request timeout or proxy option, so configure
 * those on the client itself.
 *
 * @internal
 */
final class RestTransport
{
    private const API_PREFIX = '/v1';

    private readonly ClientInterface $client;
    private readonly bool $ownsGuzzleClient;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param array<string, string> $headers  lowercase header names
     * @param bool                  $trustEnv honour HTTP(S)_PROXY from the environment (only when $proxy is null)
     */
    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter]
        private array $headers,
        private readonly Timeout $timeout,
        ?ClientInterface $client = null,
        #[\SensitiveParameter]
        private readonly ?string $proxy = null,
        private readonly bool $trustEnv = false,
    ) {
        if ($client === null && class_exists(\GuzzleHttp\Client::class)) {
            $client = new \GuzzleHttp\Client(['http_errors' => false]);
            $this->ownsGuzzleClient = true;
        } else {
            $this->ownsGuzzleClient = false;
        }
        $this->client = $client ?? Psr18ClientDiscovery::find();
        $this->requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'headers' => Headers::redact($this->headers), 'proxy' => $this->proxy === null ? null : '***'];
    }

    public function setHeader(string $name, string $value): void
    {
        $this->headers = array_merge($this->headers, Headers::normalize([$name => $value]));
    }

    /**
     * @param array<mixed>|null                     $body         JSON-encoded when not null
     * @param array<string, string|int|float|bool> $query        booleans are sent as "true"/"false"
     * @param TimeoutClass|null                     $timeoutClass default: query for GET/HEAD, insert otherwise
     *
     * @throws ConnectionException on network failures
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [], ?TimeoutClass $timeoutClass = null): RestResponse
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

        $timeoutClass ??= \in_array($method, ['GET', 'HEAD'], true) ? TimeoutClass::Query : TimeoutClass::Insert;
        try {
            $response = $this->send($request, $timeoutClass);
        } catch (ClientExceptionInterface $e) {
            // Not chained on purpose: PSR-18 exceptions carry the request, including the Authorization header.
            throw new ConnectionException(\sprintf('%s %s failed: %s', $method, $url, $e->getMessage()));
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
     *
     * @throws AuthenticationException          on 401
     * @throws InsufficientPermissionsException on 403
     * @throws UsageLimitException              on 429
     * @throws UnexpectedStatusCodeException    on any other unexpected status
     */
    public function requestExpecting(
        string $method,
        string $path,
        string $errorMessage,
        array $expected = [200],
        ?array $body = null,
        array $query = [],
        ?TimeoutClass $timeoutClass = null,
    ): RestResponse {
        $response = $this->request($method, $path, $body, $query, $timeoutClass);
        if (\in_array($response->statusCode, $expected, true)) {
            return $response;
        }

        throw match ($response->statusCode) {
            401 => new AuthenticationException(\sprintf(
                '%s: unauthorized (401)%s',
                $errorMessage,
                ($detail = UnexpectedStatusCodeException::serverMessage($response->body)) !== null ? ' (' . $detail . ')' : '',
            )),
            403 => new InsufficientPermissionsException($errorMessage, 403, $response->body),
            429 => new UsageLimitException($errorMessage, 429, $response->body),
            default => new UnexpectedStatusCodeException($errorMessage, $response->statusCode, $response->body),
        };
    }

    private function send(RequestInterface $request, TimeoutClass $timeoutClass): ResponseInterface
    {
        if (!$this->ownsGuzzleClient || !$this->client instanceof \GuzzleHttp\ClientInterface) {
            return $this->client->sendRequest($request);
        }

        $options = [
            'timeout' => $timeoutClass->seconds($this->timeout),
            'connect_timeout' => min($this->timeout->init, $timeoutClass->seconds($this->timeout)),
            'http_errors' => false,
        ];
        if ($this->proxy !== null) {
            $options['proxy'] = $this->proxy;
        } elseif (!$this->trustEnv) {
            // An empty proxy disables libcurl's own HTTP(S)_PROXY lookup.
            $options['proxy'] = '';
        }

        // Guzzle's exceptions implement PSR-18's ClientExceptionInterface; request() converts them.
        return $this->client->send($request, $options);
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
}
