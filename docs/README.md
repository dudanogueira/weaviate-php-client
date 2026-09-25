# Weaviate PHP Client: Planning Docs

This folder plans a new **official Weaviate PHP client** (`dudanogueira/weaviate-php-client`). The client:

- is **gRPC-first**: search, aggregate, batch and tenant reads go over gRPC, and everything else uses REST;
- aims to match the features of the **Python v4 client**, the primary supported client;
- replaces the community package [`timkley/weaviate-php`](https://github.com/timkley/weaviate-php), which covers a small part of the REST API and runs every search as a raw GraphQL string.

## Documents

| # | Document | What it covers |
|---|----------|----------------|
| 00 | [Context](00-context.md) | Why we're doing this, analysis of the existing client, GraphQL → gRPC status |
| 01 | [Architecture](01-architecture.md) | Package layout, REST/gRPC transports, proto codegen, auth, errors |
| 02 | [Feature parity matrix](02-feature-parity-matrix.md) | Every Python v4 capability → PHP API, transport, phase, min server |
| 03 | [API design](03-api-design.md) | PHP conventions and worked examples |
| 04 | [Roadmap](04-roadmap.md) | Phases P0–P6, releases, exit criteria |
| 05 | [Testing & CI](05-testing-and-ci.md) | Unit, integration and parity tests, CI matrix |
| 06 | [Integrations](06-integrations.md) | Laravel package, Symfony bundle, async client |
| 07 | [Migration from timkley/weaviate-php](07-migration-from-timkley.md) | Old call → new API mapping |
| 08 | [Docs & examples](08-docs-and-examples.md) | PHP tabs on docs.weaviate.io, runnable examples |

## Detailed specifications (Python source → PHP)

Each spec maps every public Python v4 method and parameter to its PHP equivalent, with wire mapping, behaviour rules, version gates, examples and a test checklist. They were written from the Python client's `main` branch source.

| # | Spec | Area |
|---|------|------|
| 09 | [Connection](09-connection.md) | Connect helpers, separate HTTP and gRPC endpoints, TLS, auth, proxies, timeouts, headers, grpc-web, init sequence |
| 10 | [Collections & config](10-collections-and-config.md) | Collection CRUD, properties, vectorizers, vector index, quantizers, modules, reconfiguration |
| 11 | [Data, references & tenants](11-data-references-tenants.md) | Object CRUD, insertMany, deleteMany, references, multi-tenancy, value types |
| 12 | [Query & generate](12-query-and-generate.md) | All search methods, the filter DSL, results, iterator, RAG |
| 13 | [Aggregate](13-aggregate.md) | Aggregations, metrics, group-by |
| 14 | [Batch](14-batch.md) | Dynamic, fixed-size, rate-limited and streaming batching without threads |
| 15 | [Admin APIs](15-admin-apis.md) | Backups, RBAC, users, aliases, cluster and replication, debug |

## Spikes

| # | Spike | Result |
|---|---|---|
| [0001](spikes/0001-grpc-transport.md) | Pure-PHP gRPC transport (ADR 0002) | GO: curl matches or beats ext-grpc (local and Weaviate Cloud) with libcurl 8.4 or later |

## QA reviews

| Date | Review | Result |
|---|---|---|
| 2026-09-25 | [P0 connection layer + Python parity](qa/2026-09-25-p0-review.md) | 16 findings fixed (2 P1). Tests went from 45 to 124. The collections parity audit found no gaps; the client scope got 6 additions |

## Security reviews

| Date | Review | Result |
|---|---|---|
| 2026-09-25 | [Whole client, CI, dependencies, history](security/2026-09-25-review.md) | 14 findings (1 High, 4 Medium) fixed with regression tests; automated scans clean |

## Decision records

| ADR | Decision | Status |
|-----|----------|--------|
| [0001](decisions/0001-new-official-repo.md) | Build a new official repo instead of forking | Accepted |
| [0002](decisions/0002-pluggable-grpc-transport.md) | Pluggable gRPC transport: pure-PHP curl HTTP/2 by default, ext-grpc when available | Accepted |
| [0003](decisions/0003-php-8.2-minimum.md) | PHP 8.2 minimum | Accepted |
| [0004](decisions/0004-server-version-floor.md) | Weaviate **1.29** server floor (above Python's 1.27), with newer features version-gated | Accepted |
| [0005](decisions/0005-namespace-and-package-name.md) | Package `dudanogueira/weaviate-php-client` with the `Weaviate\Client\` root namespace | Accepted |

## Summary of decisions so far

- **New official repository.** A migration guide covers timkley users.
- **Transport.** It is pluggable. The default is pure PHP (`ext-curl` HTTP/2 plus `google/protobuf`), so no PECL extension is needed. `ext-grpc` is picked up automatically when it's installed.
- **PHP 8.2 or later.**
- **Weaviate 1.29 or later.**
- **Namespace:** `Weaviate\Client\`, so it can be installed next to the community package.
- **In scope:** the core client, an async client, a Laravel package, a Symfony bundle, and PHP snippets on docs.weaviate.io.
