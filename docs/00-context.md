# 00: Context

## Problem

PHP developers don't have an official Weaviate client. The only option is the community package [`timkley/weaviate-php`](https://github.com/timkley/weaviate-php). Its search depends entirely on hand-written GraphQL, and Weaviate's client work is moving away from GraphQL.

We want an official PHP client that:

1. uses **gRPC** for data-plane operations (search, aggregate, batch) and **REST** for management,
2. matches the features of the **Python v4 client**, the main client we support,
3. has an idiomatic, typed PHP API that we can document next to Python, TypeScript, Go and Java on docs.weaviate.io.

## The existing client: `timkley/weaviate-php`

These figures were checked on 2026-09-25.

| Aspect | State |
|--------|-------|
| Version | 0.12.0 (2026-03-30), pre-1.0 |
| Adoption | About 81k total installs, about 6.5k a month; 37 stars |
| License | MIT |
| Maintainers | One maintainer. Recent commits are mostly dependency bumps |
| PHP | `^8.3` |
| HTTP | Laravel `illuminate/http` (Guzzle). This pulls `illuminate/support` into every app that installs it |
| Tests | Pest with Laravel `Http::fake()` fixtures. **No integration tests against a real Weaviate** |

### What it covers

- `/v1/schema`: class CRUD and add-property.
- `/v1/objects`: CRUD, `HEAD` exists.
- `/v1/batch/objects`: create (a single request, with no chunking) and delete-by-filter (the `where` clause is a raw array).
- `/v1/meta`.
- `/v1/graphql`: **raw string passthrough only** (`$weaviate->graphql()->get('{ Get { ... } }')`). There is no query builder and GraphQL `errors` are not parsed.

### Problems found

- **No gRPC.** Every search is hand-written GraphQL.
- **No typed API** for near*, hybrid, bm25, filters, group-by, generative (RAG) or aggregate queries.
- **Missing APIs:**
  - multi-tenancy, named vectors, multi-vectors;
  - references, backups, nodes and cluster;
  - RBAC, users, aliases, replication;
  - readiness checks and OIDC.
- **Bugs and design issues:**
  - Query parameters are stored on the shared `Api` object and never reset, so they leak into later requests.
  - `Batch::create` returns raw arrays inside a collection that is supposed to hold models.
  - Everything except 401 and 404 becomes a generic `\Exception`.
- **Dependency:** a hard dependency on Laravel packages, even for apps that don't use Laravel.

It works for basic REST CRUD, but closing these gaps means rewriting nearly all of it. See [ADR 0001](decisions/0001-new-official-repo.md).

## GraphQL status: be precise

Our shorthand is "GraphQL is being phased out". The accurate version is:

- Weaviate has **not formally deprecated** the GraphQL API. The server still serves it, and the docs list it next to gRPC.
- **Every current official client is gRPC-first for the data plane.** That includes Python v4, TypeScript v3, Java v6 and C#. Go uses gRPC for batch and has gRPC search in its experimental API.
- New search features ship on gRPC first. Examples include multi-target vectors, the server-side batch stream, and gRPC aggregate.
- The Python v4 client uses GraphQL only for its `graphql_raw_query()` escape hatch.

So a new client built on GraphQL would be behind from day one. The PHP client will keep a `graphqlRawQuery()` escape hatch for parity and nothing more.

## Parity baseline: Python v4

At the time of writing Python v4 is at release 4.24.0 (`main` at `eb5546a`), targets Weaviate 1.39, and needs server 1.27 or later. The PHP client sets a higher floor of **1.29** ([ADR 0004](decisions/0004-server-version-floor.md)). Python's surface is:

- **Connection:** local, cloud, custom and embedded; API key, OIDC and bearer auth; provider headers; timeouts.
- **Collections:** create, get, list, delete and update config, with the full config builders (vectorizers, named and multi-vectors, index types, quantizers, generative, reranker, replication, sharding, multi-tenancy, TTL).
- **Data:** single-object CRUD, `insert_many`, `delete_many`, references.
- **Batch:** dynamic, fixed-size, rate-limited and server-side stream batching.
- **Query:** fetch, near*, hybrid, bm25, the filter DSL, sort, group-by, metadata, references, target vectors, rerank, iterator.
- **Generate (RAG):** single prompt, grouped task, per-query providers.
- **Aggregate:** over_all, near*, hybrid, group-by, metrics.
- **Tenants, backups, RBAC (roles, permissions, users), aliases, cluster and replication.**
- **An async client** that mirrors the sync one.

The row-by-row mapping is in [02: Feature parity matrix](02-feature-parity-matrix.md).
