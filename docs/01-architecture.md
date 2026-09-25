# 01: Architecture

## Goals

- **No framework dependency** in the core package. Laravel and Symfony support live in separate packages ([06](06-integrations.md)).
- **No PECL extension required.** The client works on shared hosting, serverless (Bref, Vapor) and standard Docker images. See [ADR 0002](decisions/0002-pluggable-grpc-transport.md).
- **Typed from end to end.** PHPStan passes at max level, all value objects are readonly, and fixed option sets are enums.
- **A mirror of Python v4**, so docs snippets, test suites and support knowledge carry over.

## Package layout (`src/`)

```
src/
  Weaviate.php                 static connect helpers → WeaviateClient
  WeaviateClient.php           $client->collections, ->batch, ->backup, ->roles, ->users,
                               ->alias, ->cluster; isReady(), isLive(), getMeta(), close()
  Connect/                     full spec: 09-connection.md
    Auth.php                   Auth::apiKey(), ::bearerToken(), ::clientCredentials(), ::clientPassword()
    ConnectionParams.php       http host/port/secure + grpc host/port/secure
    AdditionalConfig.php       timeouts, proxies, retries, logger, transport override
    Timeout.php                init / query / insert
    Oidc/                      discovery, token fetch & refresh
  Collections/
    Collections.php            create(), get(), use(), listAll(), exists(), delete(), deleteAll(), exportConfig()
    Collection.php             ->data, ->query, ->generate, ->aggregate, ->batch, ->tenants, ->config,
                               ->backup; withTenant(), withConsistencyLevel(), iterator()
    Data/  Query/  Generate/  Aggregate/  Batch/  Tenants/  Config/
  Config/                      Configure, Reconfigure, Property, ReferenceProperty, DataType enum,
                               Vectorizers, VectorIndex, Quantizer, Generative, Reranker, …
  Query/                       Filter, Sort, GroupBy, MetadataQuery, QueryReference, TargetVectors,
                               Rerank, HybridFusion, Move, BM25Operator, Diversity, Boost, Metrics, …
  Generate/                    GenerativeConfig (one factory per provider), GenerativeParameters
  Result/                      readonly result objects (WeaviateObject, QueryReturn, GroupByReturn,
                               GenerativeReturn, AggregateReturn, BatchResult, …)
  Backup/  Rbac/  Users/  Alias/  Cluster/
  Transport/
    Rest/                      PSR-18 RestTransport, request/response mapping, retries
    Grpc/                      GrpcTransport interface, CurlGrpcTransport, ExtGrpcTransport,
                               framing, status mapping
    Mapper/                    domain objects ⇄ protobuf / JSON
  Proto/                       GENERATED from weaviate/weaviate grpc/proto/v1 (committed)
  Exceptions/
```

Layering rule: `Collections/*` and the admin namespaces depend on `Transport` interfaces only. They never touch Guzzle, curl or protobuf classes directly. `Transport/Mapper` is the only code that knows both the domain objects and the protobuf messages.

## Transports

### REST

- Uses **PSR-18** (`ClientInterface`) and **PSR-17** factories. When none are injected, they're found with `php-http/discovery`, which works with Guzzle, Symfony HttpClient and others.
- Used for:
  - schema and config,
  - single-object CRUD and single references,
  - tenant writes,
  - backups, RBAC, users, aliases, cluster and replication,
  - meta and readiness checks,
  - the GraphQL raw query.
- Retries with exponential backoff on connection errors, 502/503/504 and 429. Only idempotent methods are retried unless a caller opts in.

### gRPC

The `GrpcTransport` interface:

```php
interface GrpcTransport
{
    /** @template T of Message  @param class-string<T> $responseClass  @return T */
    public function unary(string $method, Message $request, string $responseClass, ?float $timeout = null): Message;

    /** Non-blocking start plus drive(), for curl_multi concurrency in fixed-size/dynamic batching (see 14-batch.md §5.1) */
    public function startUnary(string $method, Message $request, string $responseClass, ?float $timeout = null): PendingCall;

    public function drive(PendingCall ...$calls): void;

    public function supportsBidiStreaming(): bool;

    public function bidiStream(string $method, ?float $timeout = null): BidiStream; // throws if unsupported
}
```

| Adapter | When it's used | Notes |
|---------|----------------|-------|
| `CurlGrpcTransport` | Default | Uses `ext-curl` (built with nghttp2) and `google/protobuf` (pure PHP, or the C extension if present). It speaks gRPC over HTTP/2: `CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE` for plaintext h2c, ALPN for TLS. It frames messages with the 5-byte prefix (compressed flag plus a 4-byte length), sends `content-type: application/grpc` and `te: trailers`, reads `grpc-status`/`grpc-message` from trailers through `CURLOPT_HEADERFUNCTION`, and maps timeouts to `grpc-timeout` plus the curl timeout. It keeps one curl handle per client so the HTTP/2 connection is reused. **Unary calls only.** |
| `GrpcWebTransport` | When `grpcPathPrefix` is set, or as an automatic fallback when curl has no HTTP/2 and the server is 1.38.3 or later | Uses gRPC-web over the **REST endpoint** (`/v1/grpc-web`), built on the PSR-18 client over HTTP/1.1. It needs no nghttp2 and no separate gRPC port. **Unary only.** See [09 §6](09-connection.md#6-grpc-web-server-1383-and-later) |
| `ExtGrpcTransport` | Chosen automatically when `extension_loaded('grpc')`, or forced through `AdditionalConfig` | Wraps `Grpc\BaseStub`. Supports bidirectional streaming. |
| `AmpGrpcTransport` (async package) | `WeaviateAsyncClient` | Runs on `amphp/http-client` HTTP/2 and supports bidirectional streaming. See [06](06-integrations.md). |

**Automatic selection** (`grpcTransport: Auto`):
1. If `grpcPathPrefix` is set, use `GrpcWebTransport`.
2. Otherwise, if ext-grpc is loaded, use `ExtGrpcTransport`.
3. Otherwise, if curl has HTTP/2 (`curl_version()['features'] & CURL_VERSION_HTTP2`), use `CurlGrpcTransport`.
4. Otherwise, if the server is 1.38.3 or later, use `GrpcWebTransport`.
5. Otherwise, throw `ConnectionException` with install guidance.

**Streaming batch (`BatchStream`).** This call needs bidirectional streaming. When the active transport can't do it, `batch->stream()` falls back to the client-side dynamic batcher, which uses unary `BatchObjects`. It logs a one-time notice. Unary is the same path Python used before 1.34, so the fallback is correct, just less efficient.

**Transport per operation** (this mirrors Python v4):

| gRPC | REST |
|------|------|
| `Search` (query, generate, iterator) | schema and collection config |
| `Aggregate` (1.29+) | single-object insert, replace, update, delete, exists |
| `BatchObjects` (insertMany, batch objects) | single references, **and batch references** (`POST /v1/batch/references`, as Python does) |
| `BatchDelete` (deleteMany) | tenants create, update, remove |
| `BatchStream` (batch stream, 1.36 GA) | backups, RBAC, users, aliases, cluster, replication |
| `TenantsGet` | meta, `.well-known/ready`, `.well-known/live`, OIDC discovery |
| gRPC health check (`health_weaviate.proto`) | `graphqlRawQuery` |

## Proto code generation

- The `.proto` files are copied from `weaviate/weaviate` at `grpc/proto/v1/` at a **pinned server tag** into `proto/v1/`. That covers `weaviate.proto`, `base.proto`, `base_search.proto`, `search_get.proto`, `generative.proto`, `properties.proto`, `batch.proto`, `batch_delete.proto`, `aggregate.proto`, `tenants.proto` and `health_weaviate.proto`.
- `bin/sync-protos <tag>` downloads them. `bin/generate-protos` runs `protoc --php_out=src/Proto` with a pinned `protoc` version inside Docker.
- The generated code is **committed**, so users never need `protoc`.
- A CI job regenerates the code and fails on any diff.
- The generated namespace is `Weaviate\Client\Proto\V1\…`, set with `option php_namespace` through a protoc flag or by post-processing, because the upstream protos don't set it. The generated classes are `@internal`.

## Connection lifecycle

1. The `Weaviate::connectTo*()` helpers build a `ConnectionParams`, choose transports, and resolve auth.
2. Unless `skipInitChecks: true` is set, the client then:
   - runs OIDC discovery when OIDC auth is used;
   - runs a gRPC health check.

   These always run, even with `skipInitChecks`:
   - `GET /v1/meta` reads the server version, the modules and `grpcMaxMessageSize`;
   - the client **refuses** servers older than **1.29.0**. Python's floor is 1.27; see ([ADR 0004](decisions/0004-server-version-floor.md)).

   The exact sequence is in [09 §7](09-connection.md#7-connect-sequence-and-skipinitchecks).
3. `ServerVersion` is cached on the client. Version-gated features call `$this->requireServer('1.32', 'aliases')` and throw `UnsupportedFeatureException` when the server is too old.
4. `close()` releases the curl handles and gRPC channels. `__destruct` calls `close()` as a safety net. The Laravel and Symfony integrations register the client as a shared service.

## Auth

| Mode | Behaviour |
|------|-----------|
| `Auth::apiKey($key)` | Sends `Authorization: Bearer <key>` on REST and gRPC metadata |
| `Auth::bearerToken($access, expiresIn:, refreshToken:)` | Refreshes through the OIDC token endpoint when a refresh token is given |
| `Auth::clientCredentials($secret, scope:)` | OIDC client-credentials flow; fetches a new token before it expires |
| `Auth::clientPassword($user, $pass, scope:)` | OIDC resource-owner password flow, with refresh |

- **Token cache.** Tokens are kept in memory per client. An optional PSR-16 cache can be injected so PHP-FPM workers share tokens.
- **Provider headers.** Provider API keys (for example `X-OpenAI-Api-Key` or `X-Cohere-Api-Key`) are passed as `headers:`. They go out as HTTP headers and as gRPC metadata.
- **Weaviate Cloud.** `connectToWeaviateCloud` also sends `X-Weaviate-Cluster-Url`, which Weaviate Cloud's embedding service needs. The Python client does the same. Verify the exact header name against its source during P0.

## Errors

✅ = implemented in P0; the rest are specified in 09–15 and land with their features. Python equivalents are in brackets.

```
WeaviateException (base, extends \RuntimeException)
├── ConnectionException ✅                 network/TLS failures, missing HTTP/2          [WeaviateConnectionError]
│   ├── ClientClosedException ✅           call on a closed / never-connected client     [WeaviateClosedClientError]
│   └── WeaviateGrpcUnavailableException   gRPC endpoint unreachable at startup          [WeaviateGRPCUnavailableError]
├── WeaviateStartUpException ✅            connect() failed: unreachable, < 1.29, health  [WeaviateStartUpError]
├── AuthenticationException ✅             401 / gRPC UNAUTHENTICATED / OIDC failures     [AuthenticationFailedError]
│   └── MissingScopeException              client_credentials without scope (non-Azure)   [MissingScopeError]
├── UnexpectedStatusCodeException ✅       other REST errors; status + decoded body       [UnexpectedStatusCodeError]
│   ├── InsufficientPermissionsException ✅ 403 and gRPC PERMISSION_DENIED (status 403)   [InsufficientPermissionsError]
│   ├── UsageLimitException ✅             429, with errorCode() (e.g. USAGE_LIMIT_EXCEEDED)
│   ├── ResponseCannotBeDecodedException   2xx with an undecodable body                  [ResponseCannotBeDecodedError]
│   └── EmptyResponseException             2xx with an empty body where one is required  [EmptyResponseError]
├── GrpcException ✅                       non-OK gRPC status: status + message
│   └── QueryException                     Search / Aggregate failures                    [WeaviateQueryError]
├── TimeoutException                       client-side waits (backups, exports, indexing) [WeaviateTimeoutError]
├── RetryException                         retries exhausted                              [WeaviateRetryError]
├── InsertManyException / InsertManyAllFailedException, DeleteManyException, TenantsGetException (spec 11)
├── BatchException, BatchValidationException, BatchStream* (spec 14)
├── BackupException / BackupFailedException / BackupCanceledException, Export* (spec 15)
├── SchemaValidationException              config JSON the client can't parse (spec 10)
├── UnsupportedFeatureException ✅         server version too old                         [WeaviateUnsupportedFeatureError]
└── InvalidInputException ✅               client-side validation, before any I/O         [WeaviateInvalidInputError]
```

Rules:
- **Every transport raises the same exception** for the same failure, verified by `TransportParityTest`:
  - UNAVAILABLE for network or TLS failures;
  - DEADLINE_EXCEEDED;
  - RESOURCE_EXHAUSTED for size limits, on either side;
  - UNAUTHENTICATED becomes `AuthenticationException`;
  - PERMISSION_DENIED becomes `InsufficientPermissionsException`.
- **Weaviate quirk:** a bad or missing API key comes back over gRPC as `UNKNOWN` with "extract auth: unauthorized". That's mapped to `AuthenticationException` too.
- **No secrets in exceptions:**
  - PSR-18 exceptions (which carry the request, including `Authorization`) are never chained.
  - Credential parameters are `#[\SensitiveParameter]`.
  - `__debugInfo` redacts headers, and `ApiKey` doesn't store the key in a property at all.
- **One timeout name:** `TimeoutException`, used by specs 11, 14 and 15.

## Cross-cutting concerns

- **Logging:** a PSR-3 `LoggerInterface`, `NullLogger` by default. It logs retries, fallbacks and deprecation notices.
- **Consistency level:** `ConsistencyLevel` enum (`One`, `Quorum`, `All`). It's set through `withConsistencyLevel()` and sent both as a REST query parameter and in the gRPC request field.
- **Tenant scoping:** `withTenant('t1')` returns a new immutable `Collection` that applies the tenant to every call.
- **Immutability:** `Collection` handles are immutable, and each `with*()` returns a new clone. That makes them safe to share across Octane or RoadRunner workers.
- **UUIDs:** accepted as `string`, or as `Ramsey\Uuid\UuidInterface` when that package is installed (it's an optional dependency). Deterministic ids come from `Weaviate\Client\Util\generateUuid5($data, $namespace)`.
- **Vectors:** `list<float>` for single vectors, `list<list<float>>` for multi-vectors. On gRPC they're packed as little-endian float32 bytes (`vector_bytes`), matching Python.
