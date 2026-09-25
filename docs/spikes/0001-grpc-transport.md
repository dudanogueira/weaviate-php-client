# Spike 0001: pure-PHP gRPC transport (ADR 0002, P0 week 1)

- **Date:** 2026-09-25
- **Result:** **GO.** All exit criteria pass. `CurlGrpcTransport` stays the default. Against Weaviate Cloud over TLS it's slightly faster than ext-grpc, as long as libcurl is 8.4 or later.
- **Code:**
  - `src/Transport/Grpc/CurlGrpcTransport.php`
  - `src/Transport/Grpc/ExtGrpcTransport.php`
  - `tests/Integration/GrpcSearchSpikeTest.php`
  - `tests/Benchmark/transport-latency.php`

## Exit criteria

| # | Criterion | Result |
|---|---|---|
| 1a | Unary `Search` against a local Weaviate over h2c | ✅ curl and ext-grpc, on Weaviate 1.29.11, 1.36.23, 1.37.17, 1.38.17 and 1.39.7 (CI matrix) |
| 1b | Unary `Search` against Weaviate Cloud over TLS | ✅ curl and ext-grpc on libcurl 7.88.1 and 8.14.1: `TenantsGet` + `Search` in a tenant (read-only `tests/Integration/CloudTest.php`, `--group cloud`). The first run **failed**: Envoy chose HTTP/1.1 through ALPN. Fixed; see finding 6 |
| 2 | A non-OK `grpc-status` maps to `GrpcException` | ✅ Both transports. A search on an unknown collection gives a non-OK status, and the server message is passed through |
| 3 | curl `Search` p50 within 1.5× of ext-grpc | ✅ **0.95–0.96×** with connection reuse (libcurl 8.14.1). **1.24–1.29×** without reuse (libcurl 7.88.1) |

## Benchmark

Unary `Search` over a local Docker network. 200 objects with 128-dimensional vectors, limit 10, the pure-PHP protobuf runtime (no ext-protobuf), after a 20-call warm-up.

**PHP 8.4.26, libcurl 8.14.1** (Debian trixie; connection reuse on), ext-grpc 1.84.0, 1000 iterations:

| Query | Transport | p50 ms | p95 ms | Mean ms |
|---|---|---|---|---|
| fetchObjects | curl | 0.709 | 0.774 | 0.729 |
| fetchObjects | ext-grpc | 0.742 | 0.896 | 0.764 |
| nearVector | curl | 0.843 | 0.948 | 0.858 |
| nearVector | ext-grpc | 0.891 | 1.079 | 0.921 |

**PHP 8.2.34, libcurl 7.88.1** (Debian bookworm; a fresh connection per call), ext-grpc 1.84.0, 500 iterations:

| Query | Transport | p50 ms | p95 ms | Mean ms |
|---|---|---|---|---|
| fetchObjects | curl | 0.999 | 1.210 | 1.027 |
| fetchObjects | ext-grpc | 0.772 | 0.869 | 0.793 |
| nearVector | curl | 1.129 | 1.219 | 1.141 |
| nearVector | ext-grpc | 0.914 | 1.021 | 0.931 |

Reproduce with `GRPC=1 PHP_VERSION=8.4 DEBIAN=trixie bin/php tests/Benchmark/transport-latency.php 1000`.

### Weaviate Cloud (TLS, over the internet)

The cluster was a Weaviate Cloud sandbox in AWS us-east-1, running server 1.39.4. The benchmark was a read-only `Search` (limit 3) in one tenant, 100 iterations after 5 warm-ups, run from a laptop, so about 135 ms of that is network RTT.

| Environment | Transport | p50 ms | p95 ms | Mean ms |
|---|---|---|---|---|
| PHP 8.4, libcurl 8.14.1 | curl (**connection reused**) | **138.2** | 141.1 | 138.5 |
| PHP 8.4, libcurl 8.14.1 | ext-grpc 1.84.0 | 151.0 | 157.3 | 151.6 |
| PHP 8.4, libcurl 8.14.1 | curl (fresh connection per call, forced) | 576.0 | 626.1 | 579.2 |
| PHP 8.2, libcurl 7.88.1 | curl (fresh connection per call, automatic) | 582.1 | **2545.6** | 900.6 |
| PHP 8.2, libcurl 7.88.1 | ext-grpc 1.84.0 | 151.2 | 508.3 | 237.4 |

- **With reuse, curl is 0.92× ext-grpc.**
- **A fresh connection per call is about 4× slower**, because each call pays a TCP + TLS handshake. On libcurl 7.88.1 it also has multi-second tails, and those broke the 2 s connect timeout in the first run (finding 7).

**Local-network caveat:** the local numbers above come from a local network. Opening a fresh connection per call (old libcurl) costs a TCP handshake each time, plus a TLS handshake on `https`. Against a remote TLS server such as Weaviate Cloud, that overhead will be much larger than the ~0.25 ms measured here. Measure it as part of criterion 1b.

## Findings

1. **libcurl before 8.4.0 can't reuse an HTTP/2 connection for a second POST.**
   - Symptom: on libcurl 7.88.1 (Debian bookworm, which is the default base of many `php:*` images), the second request on a reused h2c connection fails with `Error in the HTTP2 framing layer` (curl errno 16) before anything is sent. It fails the same way with the same easy handle (with or without `curl_reset`), with `curl_share` `CURL_LOCK_DATA_CONNECT`, and with a persistent `curl_multi` handle.
   - Versions checked: 7.88.1 broken; 8.4.0, 8.5.0, 8.9.1, 8.12.1 and 8.14.1 working (Alpine 3.17–3.20 and Debian trixie images). 8.0–8.3 weren't tested.
   - **Mitigation (implemented):** `CurlGrpcTransport::canReuseConnections()` checks the version against 8.4.0 or later. Below that, every call uses `CURLOPT_FRESH_CONNECT` + `CURLOPT_FORBID_REUSE` on a new handle. That's correct but slower. The README documents the requirement.
   - **Follow-up:** narrow the range by testing 8.0–8.3, and log a one-time notice through the PSR-3 logger when the client falls back.
2. **Weaviate 1.29 ignores `return_all_nonref_properties`.**
   - 1.29.11 returns no `nonRefProps` unless the properties are listed by name. 1.30.23 through 1.39.7 honour the flag.
   - Since 1.29 is the floor, the P2 query builder must resolve property names from the schema on 1.29.x. This is recorded in [12](../12-query-and-generate.md) §6.
   - CI caught it on the floor version, which is the reason for testing the floor.
3. **The transport needs no gRPC library.** `ExtGrpcTransport` uses the extension's low-level `Grpc\Call`/`startBatch` API, so the `grpc/grpc` Composer package isn't needed.
4. **Trailers:** libcurl 7.88 and 8.x deliver HTTP/2 trailers (`grpc-status`, `grpc-message`) to `CURLOPT_HEADERFUNCTION` as documented. Trailers-only responses carry the status in the headers, and the transport reads both.
5. **Debian bookworm libcurl has HTTP/2.** The `php:8.2-cli-bookworm` image's libcurl is built with nghttp2 (`CURL_VERSION_HTTP2`), so the transport works out of the box.
6. **ALPN must offer only `h2` (Weaviate Cloud).**
   - Weaviate Cloud's gRPC ingress is Istio/Envoy. When a client offers `h2,http/1.1`, Envoy **chooses `http/1.1`**, and gRPC can't work over that (there are no trailers). grpcio offers only `h2`, which is why Python works.
   - The first version of the transport used `CURL_HTTP_VERSION_2TLS`, which offers both, and the cloud test failed with "did not speak HTTP/2".
   - **Fix (implemented):** always use `CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE`. libcurl 8.12 and later then offers only `h2` over TLS; this was verified on 8.12.1 and 8.14.1, while 8.4.0, 8.5.0 and 8.9.1 still offer both. On older libcurl, ALPN is **disabled** over TLS and the h2 preface is sent directly. Envoy accepts that (verified on 7.88.1).
   - **Risk:** a strict gRPC server that requires ALPN `h2`, such as grpc-go with ALPN enforcement behind a self-hosted TLS setup, would reject the no-ALPN path on libcurl older than 8.12. Test this in the P0 TLS/mTLS work (Envoy/Caddy/grpc-go in compose).
7. **Fresh connections over TLS are slow and have long tails.**
   - Handshakes sometimes stall for seconds (p95 2.5 s on libcurl 7.88.1).
   - **Fix (implemented):** without reuse, the connect timeout becomes the call's own deadline, not the 2 s init timeout.
   - The client also **logs a warning** when curl runs without reuse over TLS, recommending libcurl 8.4 or later, or ext-grpc.
8. **A wrong API key raises `AuthenticationException`** (401 on `/v1/meta`), not a generic startup error. That's intentional, because it's more actionable.
9. **Cluster usage limits come back as 429.** Sandbox clusters return `429 USAGE_LIMIT_EXCEEDED` (for example, one collection maximum). The typed API should map that to a dedicated exception, not treat it as a transient rate limit to retry. Note this for the retry policy.

## Still to do in P0

- A TLS test against strict-ALPN gRPC servers (finding 6), and narrowing the libcurl ranges (8.0–8.3 for reuse, 8.10–8.11 for h2-only ALPN).
- Mapping `429 USAGE_LIMIT_EXCEEDED` to a non-retryable exception (finding 9).
- `GrpcTlsConfig` (CA file, mTLS), per-protocol proxies, `trustEnv`, and the connection-pool options ([09](../09-connection.md) §4).
- `GrpcWebTransport` (server 1.38.3 and later): a fallback for libcurl without HTTP/2.
- OIDC auth flows, a typed `Meta` result, retries with backoff, and the one-time fallback log notice.
