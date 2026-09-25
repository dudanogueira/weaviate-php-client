<?php

declare(strict_types=1);

namespace Weaviate\Client\Transport\Rest;

use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Weaviate\Client\Connect\SecretHeaders;
use Weaviate\Client\Connect\Timeout;
use Weaviate\Client\Exceptions\AuthenticationException;
use Weaviate\Client\Exceptions\ConnectionException;
use Weaviate\Client\Exceptions\InsufficientPermissionsException;
use Weaviate\Client\Exceptions\InvalidInputException;
use Weaviate\Client\Exceptions\UnexpectedStatusCodeException;
use Weaviate\Client\Exceptions\UsageLimitException;

/**
 * REST over PSR-18. See docs/01-architecture.md "REST".
 *
 * Default (built-in Guzzle client), with these guarantees:
 * - per-request timeouts (init / query / insert, docs/09-connection.md §4.1);
 * - proxies follow `proxies` / `trustEnv`;
 * - **redirects are never followed** (security review S1). Guzzle strips `Authorization` on a cross-host
 *   redirect but forwards every other header, so a redirect would leak provider API keys;
 * - **no transparent decompression** and a **response size cap** (S2).
 *
 * An injected PSR-18 client is used as is, and it's the caller's job to configure the same things on it:
 * redirects off, timeouts, proxies. A warning is logged when `proxies` / `trustEnv` can't be applied. The size
 * cap still applies to every response.
 *
 * @internal
 */
final class RestTransport
{
    private const API_PREFIX = '/v1';
    private const READ_CHUNK = 65_536;

    private readonly ClientInterface $client;
    private readonly bool $ownsGuzzleClient;
    private readonly RequestFactoryInterface $requestFactory;
    private readonly StreamFactoryInterface $streamFactory;

    /**
     * @param bool $trustEnv         honour HTTP(S)_PROXY from the environment (only when $proxy is null)
     * @param int  $maxResponseBytes responses larger than this are rejected (after decompression, if any)
     */
    public function __construct(
        private readonly string $baseUrl,
        private SecretHeaders $headers,
        private readonly Timeout $timeout,
        ?ClientInterface $client = null,
        #[\SensitiveParameter]
        private readonly ?string $proxy = null,
        private readonly bool $trustEnv = false,
        private readonly int $maxResponseBytes = 268_435_456,
        ?LoggerInterface $logger = null,
    ) {
        if ($client === null) {
            $this->client = new \GuzzleHttp\Client(['http_errors' => false, 'allow_redirects' => false, 'decode_content' => false]);
            $this->ownsGuzzleClient = true;
        } else {
            $this->client = $client;
            $this->ownsGuzzleClient = false;
            if ($proxy !== null || $trustEnv) {
                ($logger ?? new NullLogger())->warning(
                    'AdditionalConfig proxies/trustEnv are not applied to an injected PSR-18 client; configure the proxy on that client.',
                );
            }
        }
        $this->requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'headers' => $this->headers->redacted(), 'proxy' => $this->proxy === null ? null : '***'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('A transport holding credentials cannot be serialized');
    }

    public function setHeader(string $name, #[\SensitiveParameter] string $value): void
    {
        $this->headers = $this->headers->with($name, $value);
    }

    /**
     * Builds an API path from segments, percent-encoding each one, so names can't escape the path
     * (`..`), add query parameters (`?`) or cut the query off (`#`). Security review S6.
     *
     *   RestTransport::path('schema', $collection, 'tenants') === '/schema/My%20Class/tenants'
     */
    public static function path(string ...$segments): string
    {
        $encoded = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidInputException(\sprintf("Invalid path segment '%s'", $segment));
            }
            $encoded[] = rawurlencode($segment);
        }

        return '/' . implode('/', $encoded);
    }

    /**
     * @param string                                $path         absolute API path below /v1, built with path() when it contains user input
     * @param array<mixed>|null                     $body         JSON-encoded when not null
     * @param array<string, string|int|float|bool> $query        booleans are sent as "true"/"false"
     * @param TimeoutClass|null                     $timeoutClass default: query for GET/HEAD, insert otherwise
     *
     * @throws ConnectionException   on network failures and oversized responses
     * @throws InvalidInputException on an unsafe path
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [], ?TimeoutClass $timeoutClass = null): RestResponse
    {
        self::assertSafePath($path);
        $url = rtrim($this->baseUrl, '/') . self::API_PREFIX . $path;
        if ($query !== []) {
            $url .= '?' . self::buildQuery($query);
        }

        $request = $this->requestFactory->createRequest($method, $url);
        foreach ($this->headers->all() as $name => $value) {
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
            $raw = $this->readBody($response, $method, $url);
        } catch (ClientExceptionInterface $e) {
            // Not chained on purpose: PSR-18 exceptions carry the request, including the Authorization header.
            throw new ConnectionException(\sprintf('%s %s failed: %s', $method, $url, $e->getMessage()));
        } catch (\RuntimeException $e) {
            if ($e instanceof ConnectionException) {
                throw $e;
            }

            throw new ConnectionException(\sprintf('%s %s failed while reading the response: %s', $method, $url, $e->getMessage()));
        }

        if ($this->ownsGuzzleClient && $response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
            throw new ConnectionException(\sprintf(
                '%s %s: the server answered with a redirect (%d) to "%s", which the client does not follow. Check the URL.',
                $method,
                $url,
                $response->getStatusCode(),
                $response->getHeaderLine('location'),
            ));
        }

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

    private static function assertSafePath(string $path): void
    {
        if (
            !str_starts_with($path, '/')
            || preg_match('#[?\#\s\x00-\x1f\x7f\\\\]#', $path) === 1
            || preg_match('#(^|/)\.{1,2}(/|$)#', $path) === 1
        ) {
            throw new InvalidInputException(\sprintf(
                'Unsafe API path "%s": build paths with RestTransport::path(), which encodes each segment',
                $path,
            ));
        }
    }

    private function send(RequestInterface $request, TimeoutClass $timeoutClass): ResponseInterface
    {
        if (!$this->ownsGuzzleClient || !$this->client instanceof \GuzzleHttp\ClientInterface) {
            return $this->client->sendRequest($request);
        }

        $maxBytes = $this->maxResponseBytes;
        $options = [
            'timeout' => $timeoutClass->seconds($this->timeout),
            'connect_timeout' => min($this->timeout->init, $timeoutClass->seconds($this->timeout)),
            'http_errors' => false,
            'allow_redirects' => false,
            'decode_content' => false,
            'stream' => true,
            // Refuse early when the server announces an oversized body.
            'on_headers' => static function (ResponseInterface $response) use ($maxBytes): void {
                $length = $response->getHeaderLine('content-length');
                if ($length !== '' && ctype_digit($length) && (int) $length > $maxBytes) {
                    throw new ConnectionException(\sprintf('response of %s bytes exceeds the maximum of %d bytes', $length, $maxBytes));
                }
            },
        ];
        if ($this->proxy !== null) {
            $options['proxy'] = $this->proxy;
        } elseif (!$this->trustEnv) {
            // An empty proxy disables libcurl's own HTTP(S)_PROXY lookup.
            $options['proxy'] = '';
        }

        try {
            return $this->client->send($request, $options);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // on_headers failures surface wrapped; unwrap our own exception so the size message survives.
            $previous = $e->getPrevious();
            if ($previous instanceof ConnectionException) {
                throw $previous;
            }

            throw $e;
        }
    }

    /**
     * Reads the body in chunks and stops at the size cap, for every client (built-in or injected).
     */
    private function readBody(ResponseInterface $response, string $method, string $url): string
    {
        $stream = $response->getBody();
        $body = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(self::READ_CHUNK);
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
            if (\strlen($body) > $this->maxResponseBytes) {
                $stream->close();

                throw new ConnectionException(\sprintf(
                    '%s %s: response exceeds the maximum of %d bytes',
                    $method,
                    $url,
                    $this->maxResponseBytes,
                ));
            }
        }

        return $body;
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
