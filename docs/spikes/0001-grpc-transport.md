# Spike 0001: pure-PHP gRPC transport (ADR 0002, P0 week 1)

- **Date:** 2026-09-25
- **Result:** **GO.** `CurlGrpcTransport` stays the default. The one exception is the TLS check against Weaviate Cloud, which hasn't been run yet (see below).
- **Code:**
  - `src/Transport/Grpc/CurlGrpcTransport.php`
  - `src/Transport/Grpc/ExtGrpcTransport.php`
  - `tests/Integration/GrpcSearchSpikeTest.php`
  - `tests/Benchmark/transport-latency.php`

## Exit criteria

| # | Criterion | Result |
|---|---|---|
| 1a | Unary `Search` against a local Weaviate over h2c | ✅ curl and ext-grpc, on Weaviate 1.29.11, 1.36.23, 1.37.17, 1.38.17 and 1.39.7 (CI matrix) |
| 1b | Unary `Search` against Weaviate Cloud over TLS | ⏳ **Not run yet.** Needs a WCD cluster and API key. The TLS code path (`CURL_HTTP_VERSION_2TLS`, then checking that h2 was negotiated) is written but not exercised |
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

**Caveat:** these numbers come from a local network. Opening a fresh connection per call (old libcurl) costs a TCP handshake each time, plus a TLS handshake on `https`. Against a remote TLS server such as Weaviate Cloud, that overhead will be much larger than the ~0.25 ms measured here. Measure it as part of criterion 1b.

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

## Still to do in P0

- Criterion 1b (a Weaviate Cloud TLS test), plus TLS latency with and without connection reuse.
- `GrpcTlsConfig` (CA file, mTLS), per-protocol proxies, `trustEnv`, and the connection-pool options ([09](../09-connection.md) §4).
- `GrpcWebTransport` (server 1.38.3 and later): a fallback for libcurl without HTTP/2.
- OIDC auth flows, a typed `Meta` result, retries with backoff, and the one-time fallback log notice.
