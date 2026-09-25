# ADR 0002: Pluggable gRPC transport, pure-PHP default

- **Status:** Accepted. The P0 week-1 spike must confirm it.
- **Date:** 2026-09-25

## Context

The official gRPC library for PHP is the `grpc` PECL extension. It has these problems:
- it has to be compiled, and a build can take many minutes;
- managed hosts, many serverless runtimes and minimal Docker images often don't have it;
- it has a history of crashes under PHP-FPM when a process forks.

Asking users to install it would hurt adoption badly, especially among Laravel and WordPress users.

gRPC over HTTP/2 is a simple wire protocol:
- each message is framed with a 5-byte prefix;
- the content type is `application/grpc`;
- the status is sent in HTTP trailers.

`ext-curl` is almost always installed and is normally built with nghttp2, so it can speak HTTP/2 (h2c with prior knowledge, or h2 over TLS through ALPN). It can also read trailers through its header callback. `google/protobuf` ships a pure-PHP runtime, and the optional C extension makes it faster.

## Decision

- Define a `GrpcTransport` interface with these adapters:
  - **`CurlGrpcTransport` (default):** unary RPCs over ext-curl HTTP/2 with pure-PHP protobuf.
  - **`ExtGrpcTransport`:** chosen automatically when `ext-grpc` is loaded. It supports bidirectional streaming.
  - **`GrpcWebTransport`:** unary gRPC-web over the REST endpoint (`/v1/grpc-web`, server 1.38.3 and later) through PSR-18 on HTTP/1.1. It's used when `grpcPathPrefix` is set, or as an automatic fallback when curl lacks HTTP/2.
  - **`AmpGrpcTransport`:** in the async package. It supports bidirectional streaming.
- Users can force a transport through `AdditionalConfig(grpcTransport: …)`.
- The only RPC that needs streaming is `BatchStream`. On a unary-only transport, `batch->stream()` falls back to client-side dynamic batching over unary `BatchObjects` and logs a notice.

## Consequences

- The package installs with `composer require` alone. No extension is required.
- We own a small gRPC-over-curl implementation, so it needs thorough tests: trailers, error statuses, deadlines, GOAWAY and reconnect, TLS, proxies, and large messages.
- There's a startup check for HTTP/2 support in curl, with an error that says how to fix it.
- Streaming batch without ext-grpc is only available in the async package.
- The P3 benchmarks compare curl, ext-grpc and async throughput. If curl is far slower, the README will recommend ext-grpc for heavy imports.

## Spike exit criteria (P0, week 1)

1. A unary `Search` works against a local Weaviate over h2c and against Weaviate Cloud over TLS.
2. A non-OK `grpc-status` maps correctly to `GrpcException`.
3. Unary `Search` p50 latency is within 1.5× of ext-grpc on the same host.

Late finding (from the Python source, 2026-09-25): Weaviate 1.38.3 and later serves **grpc-web on the REST port**. Python uses it only for Pyodide. For PHP it's a strong **plan B**: it has no HTTP/2 requirement at all, and it reuses the REST client. Because the floor is 1.27, it can't be the only transport, but it removes most of the "old libcurl" risk for current servers. The spike should also measure grpc-web latency.

If the spike fails, we'll look at an HTTP/2 client written in pure PHP (for example `amphp/http-client` in blocking mode) before falling back to requiring ext-grpc.
