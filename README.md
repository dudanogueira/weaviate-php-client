# weaviate-php-client

A PHP client for the [Weaviate](https://weaviate.io) vector database.

- **gRPC-first.** Search, aggregate and batch go over gRPC; management goes over REST. GraphQL is only an escape hatch.
- **No PECL extension required.** The default gRPC transport is pure PHP: ext-curl over HTTP/2 plus `google/protobuf`. When `ext-grpc` is installed, it's used automatically.
- **Parity with the official Python v4 client** is the goal. Every feature is mapped in [`docs/`](docs/README.md).

> **Status: pre-alpha (P0, foundations).** Connection, the startup checks and the gRPC transports work. The collections, query, batch and admin APIs aren't implemented yet. See the [roadmap](docs/04-roadmap.md) and the [parity matrix](docs/02-feature-parity-matrix.md).

## Requirements

- PHP 8.2 or later with ext-curl. For gRPC, libcurl must be built with HTTP/2 (nghttp2), which is the default on most distributions.
  - With libcurl 8.4.0 or later, one HTTP/2 connection is reused across calls.
  - Older libcurl, such as 7.88.1 on Debian bookworm, can't reuse HTTP/2 connections safely, so the client opens a fresh connection per call. That's correct, but slower.
- Weaviate 1.29.0 or later ([ADR 0004](docs/decisions/0004-server-version-floor.md)).

## Install

Not on Packagist yet. To try it from GitHub:

```bash
composer config repositories.weaviate-php-client vcs https://github.com/dudanogueira/weaviate-php-client
composer require dudanogueira/weaviate-php-client:dev-main guzzlehttp/guzzle
```

Any PSR-18 HTTP client works for REST; Guzzle is only a suggestion.

## Connect

```php
use Weaviate\Client\Weaviate;
use Weaviate\Client\Connect\Auth;

// Local docker-compose
$client = Weaviate::connectToLocal();

// Weaviate Cloud
$client = Weaviate::connectToWeaviateCloud(
    clusterUrl: getenv('WEAVIATE_URL'),
    auth: Auth::apiKey(getenv('WEAVIATE_API_KEY')),
    headers: ['X-OpenAI-Api-Key' => getenv('OPENAI_API_KEY')],
);

// Any topology: separate host, port and TLS for REST and gRPC
$client = Weaviate::connectToCustom(
    httpHost: 'weaviate.example.com', httpPort: 443, httpSecure: true,
    grpcHost: 'weaviate-grpc.example.com', grpcPort: 443, grpcSecure: true,
);

$client->isReady();                  // true
$client->serverVersion();            // 1.39.7
$client->close();
```

## Development

Everything runs in Docker: no local PHP is needed. See [CONTRIBUTING.md](CONTRIBUTING.md).

```bash
docker compose up -d weaviate
bin/php composer install
bin/php vendor/bin/phpunit
```

## Relationship to `timkley/weaviate-php`

[`timkley/weaviate-php`](https://github.com/timkley/weaviate-php) is the community client this project builds on the experience of. It uses REST and raw GraphQL. The two packages use different namespaces (`Weaviate\` vs `Weaviate\Client\`), so they can be installed side by side while you migrate ([migration guide](docs/07-migration-from-timkley.md)).

## License

BSD-3-Clause. See [LICENSE](LICENSE).
