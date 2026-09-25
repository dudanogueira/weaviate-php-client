# 06: Integrations (Laravel, Symfony, async)

The core package `dudanogueira/weaviate-php-client` is framework-agnostic. Each integration is a **separate package** with its own release cycle. They live in a monorepo under `packages/` and are split into read-only repos with `splitsh-lite` (the approach Symfony and Laravel use), or they get their own repos. That choice is made in P5.

## Laravel: `weaviate/weaviate-laravel`

- **Service provider** with package auto-discovery. It registers `WeaviateClient` as a singleton and `WeaviateManager` for multiple connections.
- **`config/weaviate.php`** (published with `php artisan vendor:publish --tag=weaviate-config`):

  ```php
  return [
      'default' => env('WEAVIATE_CONNECTION', 'default'),
      'connections' => [
          'default' => [
              'driver' => env('WEAVIATE_DRIVER', 'cloud'),   // local | cloud | custom
              'url' => env('WEAVIATE_URL'),
              'api_key' => env('WEAVIATE_API_KEY'),
              'grpc' => ['host' => env('WEAVIATE_GRPC_HOST'), 'port' => env('WEAVIATE_GRPC_PORT', 50051)],
              'headers' => ['X-OpenAI-Api-Key' => env('OPENAI_API_KEY')],
              'timeout' => ['init' => 2, 'query' => 30, 'insert' => 90],
              'skip_init_checks' => false,
          ],
      ],
  ];
  ```
- **Facade:**
  - `Weaviate::collections()->use('Article')->query->nearText(...)`
  - `Weaviate::connection('analytics')->…`
- **Octane / long-running workers:** the client is immutable and reuses connections. Tokens are cached in Laravel's cache store through the PSR-16 hook in the core.
- **Artisan commands:**
  - `weaviate:status` runs the meta and readiness checks.
  - `weaviate:collections` lists collections.
- **Testing helper:** `Weaviate::fake()` swaps in the core `FakeGrpcTransport` and a mock REST client, so apps can assert on the queries they send.
- **Optional:** a Laravel Scout engine (`weaviate` driver) for keyword, hybrid and vector search on Eloquent models. It's worth doing because many timkley users are Laravel users. It ships after 1.0 as a separate milestone.

## Symfony: `weaviate/weaviate-symfony`

- A bundle with a `weaviate:` config tree that mirrors the Laravel keys. It supports multiple named clients (`weaviate.client.default`, `weaviate.client.analytics`) and autowiring with `WeaviateClient $client` or named aliases.
- It uses Symfony HttpClient as the PSR-18 implementation, so the web profiler and HttpClient traces cover REST traffic.
- **Profiler data collector (optional):** shows gRPC and REST calls, their timings and server errors in the Symfony profiler, using a decorating transport.
- **Commands:** `weaviate:status`, `weaviate:collections:list`.
- Supported on Symfony 6.4 LTS and 7.x.

## Async: `weaviate/weaviate-php-async`

**Why a separate package:** async needs an event loop. It would pull AMPHP into every install, and most PHP apps (FPM) don't need it.

- **Runtime:** AMPHP v3 (fibers, PHP 8.1 and later). The async API looks synchronous inside fibers. Methods return `Amp\Future` for explicit concurrency:

  ```php
  $client = WeaviateAsync::connectToLocal();
  [$a, $b] = Amp\Future\await([
      async(fn () => $client->collections->use('A')->query->nearText(query: 'x')),
      async(fn () => $client->collections->use('B')->query->bm25(query: 'y')),
  ]);
  ```
- **Transport:** `AmpGrpcTransport` on `amphp/http-client` (HTTP/2, multiplexed) and `AmpRestTransport`. **Bidirectional streaming works here**, so `batch->stream()` uses server-side batching without ext-grpc.
- **Beyond Python:** Python's async client only has `batch.stream()`. PHP async offers every batch mode.
- **Code sharing:** the builders, mappers, results and exceptions all come from the core. Only the orchestration layer (`Collections\*` executors) is duplicated.
  - Python solves this with an executor pattern (`client_executor.py`). We mirror it: each operation is written once as a "plan" (a request plus a response mapper), and a sync executor and an async executor each run the plan.
  - This decision lands in P0 so the async package in P5 doesn't need a refactor.
- **ReactPHP:** not planned. AMPHP v3 fibers work with Revolt, which ReactPHP 3 also targets. We'll revisit this if users ask.
- **Swoole / OpenSwoole:** coroutine hooks make the sync curl transport non-blocking already. We document that, and there's no separate package.
