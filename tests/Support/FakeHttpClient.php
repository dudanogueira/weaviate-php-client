<?php

declare(strict_types=1);

namespace Weaviate\Client\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client that answers by path from a routing table and records every request.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param array<string, ResponseInterface|\Throwable> $routes "GET /v1/meta" => response
     */
    public function __construct(private array $routes = []) {}

    /**
     * @param array<string, mixed> $extraMeta
     */
    public static function weaviate(string $version = '1.39.7', array $extraMeta = []): self
    {
        return new self([
            'GET /v1/meta' => self::json(['version' => $version, 'hostname' => 'http://[::]:8080'] + $extraMeta),
            'GET /v1/.well-known/ready' => new Response(200),
            'GET /v1/.well-known/live' => new Response(200),
        ]);
    }

    /**
     * @param array<mixed> $body
     */
    public static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['content-type' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    public function route(string $key, ResponseInterface|\Throwable $response): self
    {
        $this->routes[$key] = $response;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $key = $request->getMethod() . ' ' . $request->getUri()->getPath();
        $response = $this->routes[$key] ?? new Response(404, [], 'no route for ' . $key);
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }
}
