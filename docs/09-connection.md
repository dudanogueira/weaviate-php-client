# 09: Connection specification

This maps **every** connection option in Python v4 to PHP. The source is `weaviate/connect/helpers.py`, `connect/base.py`, `connect/v4.py`, `config.py` and `auth.py` on the Python client's `main` branch, read on 2026-09-25. Rows in [02 §1](02-feature-parity-matrix.md#1-connection--client) link here.

## 1. The model: HTTP and gRPC are two separate endpoints

Python keeps REST and gRPC as **two independent endpoints**, each with its own host, port and TLS flag:

```python
ConnectionParams(
    http=ProtocolParams(host, port, secure),
    grpc=ProtocolParams(host, port, secure),
    grpc_path_prefix=None,   # grpc-web, see §6
)
```

PHP mirrors this exactly:

```php
final readonly class ProtocolParams {
    public function __construct(
        public string $host,   // must not be empty
        public int $port,      // 0–65535
        public bool $secure,   // https / TLS gRPC
    ) {}
}

final readonly class ConnectionParams {
    public function __construct(
        public ProtocolParams $http,
        public ProtocolParams $grpc,
        public ?string $grpcPathPrefix = null,
    ) {}

    public static function fromParams(
        string $httpHost, int $httpPort, bool $httpSecure,
        string $grpcHost, int $grpcPort, bool $grpcSecure,
        ?string $grpcPathPrefix = null,
    ): self;

    /** Python `from_url`: host from the URL; http port from the URL or 443/80; grpc secure = $grpcSecure || scheme is https */
    public static function fromUrl(string $url, int $grpcPort, bool $grpcSecure = false, ?string $grpcPathPrefix = null): self;
}
```

Validation, the same as Python:
- The host must not be empty.
- Ports must be in the range 0–65535.
- **If `http.host == grpc.host` and `http.port == grpc.port` with no grpc-web prefix, throw `InvalidInputException`** ("http port and grpc port must be different if using the same host").
- A `fromUrl` scheme other than `http` or `https` throws.

This covers every topology:

| Topology | How |
|---|---|
| The same host, plaintext, default ports (docker-compose) | `connectToLocal()` |
| The same host, non-default ports | `connectToLocal(port: 8081, grpcPort: 50052)` |
| **A different gRPC host** (a separate load balancer or ingress for gRPC) | `connectToCustom(httpHost: 'api.example.com', …, grpcHost: 'grpc.example.com', …)` |
| **HTTPS REST with plaintext gRPC** (or the other way round) | `connectToCustom(httpSecure: true, grpcSecure: false, …)` |
| Both TLS on 443 with different hostnames (the Weaviate Cloud pattern) | `connectToWeaviateCloud()`, or `connectToCustom(... 443, true ... 443, true)` |
| A private CA or mTLS for gRPC | `AdditionalConfig(grpcConfig: new GrpcConfig(tls: …))` (§4.4) |
| gRPC through a proxy | `AdditionalConfig(proxies: new Proxies(grpc: 'http://proxy:3128'))` (§4.2) |
| gRPC on the same host:port as REST, with no HTTP/2 (grpc-web, server 1.38.3 and later) | `grpcPathPrefix: '/v1/grpc-web'` (§6) |
| Anything else | `new WeaviateClient(connectionParams: …, auth: …, …)` and then `->connect()` |

## 2. Connect helpers

Each helper builds a `WeaviateClient`, calls `connect()`, and returns the client.
- If `connect()` throws, the helper closes the client before re-throwing, like Python's `__connect`.
- The same parameters also exist in the async package as `WeaviateAsync::connectTo*()` (Python's `use_async_with_*`). The async versions **do not** connect automatically; call `await $client->connect()`.

### 2.1 `Weaviate::connectToLocal()` (Python `connect_to_local`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `host` | `host` | `string` | `'localhost'` | Used for **both** HTTP and gRPC |
| `port` | `port` | `int` | `8080` | HTTP port |
| `grpc_port` | `grpcPort` | `int` | `50051` | gRPC port |
| `headers` | `headers` | `array<string,string>` | `[]` | §5 |
| `additional_config` | `additionalConfig` | `?AdditionalConfig` | `null` | §4 |
| `skip_init_checks` | `skipInitChecks` | `bool` | `false` | §7 |
| `auth_credentials` | `auth` | `string\|AuthCredentials\|null` | `null` | A string is treated as an API key (§3) |

Behaviour: `http = (host, port, secure: false)` and `grpc = (host, grpcPort, secure: false)`. `connectToLocal` has **no TLS option**. Use `connectToCustom` for TLS.

### 2.2 `Weaviate::connectToWeaviateCloud()` (Python `connect_to_weaviate_cloud`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `cluster_url` | `clusterUrl` | `string` | required | A hostname or a full URL |
| `auth_credentials` | `auth` | `string\|AuthCredentials` | **required** | |
| `headers` | `headers` | `array` | `[]` | |
| `additional_config` | `additionalConfig` | `?AdditionalConfig` | `null` | |
| `skip_init_checks` | `skipInitChecks` | `bool` | `false` | |

Behaviour:
1. If `clusterUrl` starts with `http`, keep only its host (so a pasted URL works).
2. Work out the **gRPC host**:
   - If the host ends with `.weaviate.network`, `ident.rest-of-domain` becomes `ident.grpc.rest-of-domain`. This is the legacy WCS domain.
   - Otherwise, prefix the host: `grpc-{host}`.
3. `http = (host, 443, secure: true)` and `grpc = (grpcHost, 443, secure: true)`.
4. If `auth` is bearer, client-credentials or client-password, emit a **deprecation warning** through the PSR-3 logger, because OIDC with Weaviate Cloud is deprecated. The connection still works.
5. `X-Weaviate-Cluster-URL` is added automatically (§5).

`connect_to_wcs` is a deprecated alias in Python and is **not ported**.

### 2.3 `Weaviate::connectToCustom()` (Python `connect_to_custom`)

| Python param | PHP param | Type | Default |
|---|---|---|---|
| `http_host` | `httpHost` | `string` | required |
| `http_port` | `httpPort` | `int` | required |
| `http_secure` | `httpSecure` | `bool` | required |
| `grpc_host` | `grpcHost` | `string` | required |
| `grpc_port` | `grpcPort` | `int` | required |
| `grpc_secure` | `grpcSecure` | `bool` | required |
| `headers` | `headers` | `array` | `[]` |
| `additional_config` | `additionalConfig` | `?AdditionalConfig` | `null` |
| `auth_credentials` | `auth` | `string\|AuthCredentials\|null` | `null` |
| `skip_init_checks` | `skipInitChecks` | `bool` | `false` |
| — | `grpcPathPrefix` | `?string` | `null` |

The `grpcPathPrefix` row is a PHP addition. Python only exposes it on `ConnectionParams`, but it's handy here. See §6.

The six endpoint parameters are required, which matches Python: callers state the topology explicitly.

### 2.4 `new WeaviateClient(...)` (Python `WeaviateClient.__init__`)

| Python param | PHP param | Notes |
|---|---|---|
| `connection_params` | `connectionParams: ConnectionParams` | Required in PHP |
| `embedded_options` | — | Embedded isn't supported ([02 §13](02-feature-parity-matrix.md#13-explicitly-out-of-scope)) |
| `auth_client_secret` | `auth` | |
| `additional_headers` | `headers` | |
| `additional_config` | `additionalConfig` | |
| `skip_init_checks` | `skipInitChecks` | |

The constructor does **no I/O**. Call `$client->connect(force: false)` (§7), and later `$client->close()`. `isConnected()` reports the state.

### 2.5 Not ported

| Python | Why |
|---|---|
| `connect_to_embedded` / `use_async_with_embedded` (hostname 127.0.0.1, port 8079, grpc_port 50050, version, persistence_data_path, binary_path, environment_variables) | Managing a subprocess binary from PHP-FPM isn't workable. Use Docker or testcontainers-php. **Reconsider** for CLI-only use if users ask |
| `connect_to_wcs` | A deprecated alias |

## 3. Authentication (`Auth`)

| Python | PHP | Fields | Behaviour |
|---|---|---|---|
| `Auth.api_key(api_key)` | `Auth::apiKey(string $apiKey)` | apiKey | Sends `Authorization: Bearer <key>` on REST, and `authorization` metadata on gRPC |
| `Auth.bearer_token(access_token, expires_in=60, refresh_token=None)` | `Auth::bearerToken(string $accessToken, int $expiresIn = 60, ?string $refreshToken = null)` | | Warns when `expiresIn < 0`. Without a refresh token, auth expires when the token does. With one, it refreshes through the OIDC token endpoint |
| `Auth.client_credentials(client_secret, scope=None)` | `Auth::clientCredentials(string $clientSecret, string\|array\|null $scope = null)` | | `scope` can be a space-separated string or a list. Azure scopes are hardcoded when the provider is Azure, like Python |
| `Auth.client_password(username, password, scope=None)` | `Auth::clientPassword(string $username, string $password, string\|array\|null $scope = null)` | | `openid` is added automatically. Some IdPs also need `offline_access` to return a refresh token |
| Passing a plain `str` as `auth_credentials` | Passing a plain `string` as `auth:` | | Treated as `Auth::apiKey()` |

Rules taken from Python:
- **OIDC discovery:** `GET {http}/v1/.well-known/openid-configuration` with the `init` timeout. The response gives the issuer, the `clientId` and the scopes. If OIDC auth is configured but the server has no OIDC config, the client warns and doesn't send an auth header. The exact rule is ported from `__process_oidc_response`.
- **Token refresh:** Python runs a background task. PHP has no background threads, so the sync client **refreshes lazily**: before each request, if the token expires within about 30 seconds, it refreshes first. The async client may refresh ahead of time on a timer.
- **Precedence:** if `headers` contains `Authorization` **and** `auth` is set, warn and **drop the header**. The auth object wins, because it can refresh.
- **gRPC metadata** for OIDC is set on each call from the current token.
- **Token sharing:** tokens can optionally be shared across FPM workers through an injected PSR-16 cache (`AdditionalConfig::tokenCache`). This is a PHP addition.

## 4. `AdditionalConfig`

| Python field | PHP field | Type | Default | Notes |
|---|---|---|---|---|
| `timeout` (`Timeout` or a `(query, insert)` tuple) | `timeout` | `Timeout\|array{int\|float,int\|float}` | `new Timeout()` | An array is read as `[query, insert]`, like the Python tuple |
| `proxies` (`str \| Proxies \| None`) | `proxies` | `string\|Proxies\|null` | `null` | §4.2 |
| `trust_env` | `trustEnv` | `bool` | `false` | §4.2 |
| `connection` (`ConnectionConfig`) | `connection` | `ConnectionConfig` | defaults | §4.3 |
| `grpc_config` (`GrpcConfig`) | `grpcConfig` | `?GrpcConfig` | `null` | §4.4 |
| — | `grpcTransport` | `GrpcTransportChoice\|GrpcTransport\|null` | `null` (auto) | PHP only. `Auto`, `Curl`, `ExtGrpc`, `GrpcWeb`, or an instance ([ADR 0002](decisions/0002-pluggable-grpc-transport.md)) |
| — | `httpClient` | `?Psr\Http\Client\ClientInterface` | discovered | PHP only. Bring your own PSR-18 client |
| — | `logger` | `?Psr\Log\LoggerInterface` | `NullLogger` | PHP only. Python uses stdlib warnings |
| — | `tokenCache` | `?Psr\SimpleCache\CacheInterface` | `null` | PHP only. See §3 |
| — | `retry` | `RetryConfig` | 3 retries, exponential backoff | PHP only. Python's REST retries come from `session_pool_max_retries` |

### 4.1 `Timeout` (seconds, all `>= 0`)

| Field | Default | Used for |
|---|---|---|
| `query` | 30 | Reads: REST GET and gRPC Search/Aggregate/TenantsGet |
| `insert` | 90 | Writes: REST POST/PUT/PATCH/DELETE and gRPC BatchObjects/BatchDelete, REST batch references |
| `init` | 2 | Meta, OIDC discovery, gRPC health check, `.well-known/ready` |
| `stream` | `null` (none) | `BatchStream` and other streaming RPCs |

### 4.2 Proxies

- `Proxies(http: ?string, https: ?string, grpc: ?string)`.
- A plain **string** sets all three to the same URL. The proxy then has to handle HTTP/1.1 and HTTP/2 at the same time.
- **`trustEnv: true`** reads `HTTP_PROXY`/`http_proxy`, `HTTPS_PROXY`/`https_proxy` and `GRPC_PROXY`/`grpc_proxy`. Uppercase names win. The environment is **ignored when `proxies` is set**.
- Mapping:
  - REST: passed to the PSR-18 client when it's one we build (Guzzle `proxy` / Symfony `proxy`). When the user supplies their own client, proxying is their job, and we log a notice if both are set.
  - curl gRPC: `CURLOPT_PROXY`, using HTTP CONNECT tunnelling for TLS.
  - ext-grpc: the `grpc.http_proxy` channel argument.

### 4.3 `ConnectionConfig` (connection pool)

| Python | Default | PHP |
|---|---|---|
| `session_pool_connections` | 20 | `poolConnections`. For the curl transport this is the `curl_share`/multi-handle connection cap. Otherwise it's advisory, because PSR-18 clients manage their own pools |
| `session_pool_maxsize` | 100 | `poolMaxSize` (same) |
| `session_pool_max_retries` | 3 | Goes into `RetryConfig::maxRetries` |
| `session_pool_timeout` | 5 | `poolTimeout`, the connect timeout (`CURLOPT_CONNECTTIMEOUT`) |

PHP-FPM tears down the pool at the end of each request, so the pool mostly matters for CLI workers, Octane, RoadRunner and async. That's documented.

### 4.4 `GrpcConfig`

| Python | PHP | Notes |
|---|---|---|
| `channel_options: [(name, value)]`, e.g. keepalive | `channelOptions: array<string, int\|string>` | Passed straight through on ext-grpc. On curl, a supported subset is mapped (`grpc.keepalive_time_ms` → `CURLOPT_TCP_KEEPINTVL`, `grpc.max_receive_message_length` and so on) and unknown keys log a warning |
| `credentials: grpc.ssl_channel_credentials(root_certificates, private_key, certificate_chain)` | `tls: new GrpcTlsConfig(caFile:, certFile:, keyFile:, verifyPeer: true, verifyHost: true)` | PHP can't use Python's opaque credentials object, so we take files or PEM strings. This covers a **private CA** and **mTLS**. Mapped to `CURLOPT_CAINFO`/`SSLCERT`/`SSLKEY` on curl and `ChannelCredentials::createSsl()` on ext-grpc |

Always set:
- `max_send`/`max_receive` message length. The default is `104858000` bytes (about 100 MB), and it's overridden by `grpcMaxMessageSize` from `/v1/meta` when the server reports one.
- `grpc.default_authority = grpc.host`, which is the `:authority` pseudo-header on curl.

REST TLS (a custom CA or client cert for HTTPS) is set on the PSR-18 client. When we build the client ourselves, `AdditionalConfig::httpTls` (`caFile`, `certFile`, `keyFile`, `verify`) is mapped to it. This is a PHP addition; Python uses httpx defaults plus `trust_env` for `SSL_CERT_FILE`.

## 5. Headers

- **Always sent:** `X-Weaviate-Client: weaviate-client-php/{version}-{sync|async}`, which Python also sends as `x-weaviate-client` gRPC metadata. **Coordinate the exact format with the server and analytics team** so the PHP client is counted.
- **`X-Weaviate-Cluster-URL: https://{http.host}`** is added automatically when the HTTP host is a Weaviate domain, meaning it contains `weaviate.io`, `weaviate.cloud` or `semi.technology`. This applies to **every** connect helper, not just the cloud one. It's sent on gRPC too when auth is set, and Weaviate Embeddings needs it.
- **User headers** (`headers:`):
  - They're lowercased and sent on REST.
  - They're also sent as gRPC metadata, except `x-weaviate-client`.
  - A `null` value throws `InvalidInputException`.
  - Examples: `X-OpenAI-Api-Key`, `X-Cohere-Api-Key`, `X-HuggingFace-Api-Key`, `X-Azure-Api-Key` (and `X-Azure-Deployment-Id`/`X-Azure-Resource-Name` where the module needs them), `X-VoyageAI-Api-Key`, `X-Jinaai-Api-Key`, `X-Mistral-Api-Key`, `X-Anthropic-Api-Key`, `X-Goog-Studio-Api-Key`/`X-Goog-Vertex-Api-Key`, AWS `X-Aws-Access-Key`/`X-Aws-Secret-Key`, `X-Weaviate-Api-Key`.
  - We'll add `Headers::openAI($key)` style helpers only as sugar; the source of truth stays a plain array.
- Header names are case-insensitive, and gRPC metadata keys must be lowercase.

## 6. gRPC-web (server 1.38.3 and later)

Python recently added `grpc_path_prefix`. Weaviate 1.38.3 and later serves **grpc-web on the REST endpoint** at `/v1/grpc-web`. In Python this is only used for WebAssembly/Pyodide, through async and a shim.

**This matters more for PHP than for Python.** grpc-web runs over **HTTP/1.1 on the REST host and port**. That means:
- no HTTP/2 or nghttp2 requirement,
- no separate gRPC port or ingress,
- it works through proxies and load balancers that only speak HTTP/1.1.

So we add **`GrpcWebTransport`**:
- It's built on the same PSR-18 client as REST.
- It's selected when `grpcPathPrefix` is set, or automatically as a fallback when curl has no HTTP/2 **and** the server is 1.38.3 or later.
- It supports unary calls only, so `BatchStream` falls back to dynamic batching.
- When grpc-web is active, the `grpc` endpoint is forced to equal the `http` endpoint. If the caller had set a different gRPC endpoint, log a warning, like Python's `Con006`.

See [ADR 0002](decisions/0002-pluggable-grpc-transport.md).

## 7. `connect()` sequence and `skipInitChecks`

This follows Python `ConnectionSync.connect()`:

1. If the client is already connected and `force` is false, return.
2. **Open REST.** If auth is OIDC, run discovery (`/v1/.well-known/openid-configuration`, `init` timeout) and get the first token.
3. **`GET /v1/meta`** (always, **even with `skipInitChecks`**). This reads the server `version` and `grpcMaxMessageSize`. Connection, TLS or read errors become `WeaviateStartUpException("Could not connect to Weaviate: …")`.
4. **Open gRPC:** build the transport with the effective message size, TLS and proxy settings.
5. **Version floor.** If the server is older than **1.29.0**, throw `WeaviateStartUpException` ("Weaviate version X is not supported. Please use 1.29.0 or higher"). This is a hard failure, as in Python, but with a higher floor (Python's is 1.27.0); see [ADR 0004](decisions/0004-server-version-floor.md).
6. Unless `skipInitChecks` is set:
   - **gRPC health check:** `/grpc.health.v1.Health/Check` with `init` timeout. A response other than `SERVING` throws `WeaviateGrpcUnavailableException`.
   - **Package version check:** warn when a newer client release is available. Python checks PyPI. PHP will make this **opt-in only** (`AdditionalConfig::checkForUpdates`) and never call Packagist by default, because outbound calls from production PHP apps are unwelcome.
7. Mark the client as connected.

Other lifecycle methods:
- `isReady()` calls `GET /v1/.well-known/ready`.
- `isLive()` calls `GET /v1/.well-known/live` **and then** the gRPC health check. It returns `true` only when both pass, as Python does. `isReady()` and `isLive()` return `false` on connection errors instead of throwing.
- `isConnected()` returns the connection state.
- `close()` releases the curl and gRPC handles.
- `waitForWeaviate(int $startupPeriod)` polls `ready` once a second. It's public in PHP, which is useful in CI and docker-compose.
- `getMeta()` and `getOpenIdConfiguration()` are REST calls.

**Scoped helper.** Python uses `with connect_to_local() as client:`. The PHP equivalent is:

```php
Weaviate::withLocal(fn (WeaviateClient $client) => …, grpcPort: 50052);
```

It closes the client in `finally`. There's one `with*` helper for each `connectTo*`.

## 8. Examples

```php
// Local docker-compose, custom ports, API key
$c = Weaviate::connectToLocal(port: 8081, grpcPort: 50052, auth: 'my-key');

// Kubernetes: REST on HTTPS ingress, gRPC on a separate TLS host
$c = Weaviate::connectToCustom(
    httpHost: 'weaviate.example.com', httpPort: 443, httpSecure: true,
    grpcHost: 'weaviate-grpc.example.com', grpcPort: 443, grpcSecure: true,
    auth: Auth::clientCredentials(getenv('OIDC_SECRET'), scope: 'api://weaviate/.default'),
);

// HTTPS REST, plaintext gRPC inside a private network
$c = Weaviate::connectToCustom(
    httpHost: 'weaviate.internal', httpPort: 443, httpSecure: true,
    grpcHost: 'weaviate.internal', grpcPort: 50051, grpcSecure: false,
);

// Private CA + mTLS + corporate proxy + longer timeouts
$c = Weaviate::connectToCustom(
    httpHost: 'vdb.corp', httpPort: 8443, httpSecure: true,
    grpcHost: 'vdb.corp', grpcPort: 50443, grpcSecure: true,
    additionalConfig: new AdditionalConfig(
        timeout: new Timeout(init: 10, query: 60, insert: 180),
        proxies: new Proxies(https: 'http://proxy.corp:3128', grpc: 'http://proxy.corp:3128'),
        grpcConfig: new GrpcConfig(
            tls: new GrpcTlsConfig(caFile: '/etc/ssl/corp-ca.pem', certFile: '/etc/app/client.pem', keyFile: '/etc/app/client.key'),
            channelOptions: ['grpc.keepalive_time_ms' => 10000],
        ),
        httpTls: new HttpTlsConfig(caFile: '/etc/ssl/corp-ca.pem'),
    ),
);

// grpc-web: only port 443 is reachable, or curl lacks HTTP/2 (server 1.38.3 and later)
$c = Weaviate::connectToCustom(
    httpHost: 'vdb.example.com', httpPort: 443, httpSecure: true,
    grpcHost: 'vdb.example.com', grpcPort: 443, grpcSecure: true,
    grpcPathPrefix: '/v1/grpc-web',
);

// Full control
$client = new WeaviateClient(
    connectionParams: ConnectionParams::fromUrl('https://vdb.example.com', grpcPort: 50051, grpcSecure: true),
    auth: Auth::apiKey('…'),
    skipInitChecks: true,
);
$client->connect();
```

## 9. Test checklist (becomes integration and unit tests in P0)

- Every helper with its defaults and every argument overridden. Assert the resulting `ConnectionParams`.
- Cloud URL parsing: a bare host, a pasted `https://` URL, `*.weaviate.network` becoming `.grpc.`, and `*.weaviate.cloud` becoming `grpc-` plus the host.
- Validation: the same host and port without grpc-web throws; an empty host, a bad port and a bad URL scheme throw.
- All four TLS combinations of HTTP and gRPC against a TLS-terminating compose stack (Caddy or Envoy in front of Weaviate).
- gRPC on a different host from REST, using a compose network alias.
- A private CA, mTLS, and `verifyPeer: false` (which logs a warning).
- Proxies: explicit per-protocol settings, a single string, `trustEnv`, and explicit settings winning over the environment. Use a Squid container.
- Auth: an API key given as a string and as an `Auth` object; bearer with and without a refresh token; client credentials and client password against Keycloak; a header `Authorization` combined with an `auth` object (warns and drops the header); lazy refresh near expiry.
- Headers: lowercasing, `null` rejected, `X-Weaviate-Cluster-URL` injected only for Weaviate domains, and headers forwarded as gRPC metadata.
- Init: a server older than 1.29 fails (1.28.x is rejected, 1.29.0 is accepted); `skipInitChecks` skips health but still calls meta; `grpcMaxMessageSize` from meta is applied; a failed health check throws the right exception.
- Timeouts: each class (`init`, `query`, `insert`, `stream`) is applied to the right calls. Use a toxiproxy-delayed server.
- Transport selection: `Auto` with ext-grpc, with only curl HTTP/2, and with neither (grpc-web on 1.38.3 and later, otherwise an actionable error).
