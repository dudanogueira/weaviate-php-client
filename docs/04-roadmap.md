# 04: Roadmap

Each phase ends with a tagged pre-release on Packagist. A phase is **done** when all three of these are true:

1. Every row assigned to it in the [parity matrix](02-feature-parity-matrix.md) is ✅, or ⏸ with a stated reason.
2. Integration tests for those rows pass on the full server matrix ([05](05-testing-and-ci.md)).
3. Each new public method has a runnable example in `examples/` and a docs snippet ([08](08-docs-and-examples.md)).

Sizes are rough estimates for one engineer who works full-time on the client and already knows the Python client.

---

## P0: Foundations → `0.1.0-alpha` (about 3–4 weeks)

- Repo setup:
  - `composer.json` (PHP ^8.2, `ext-curl`, `ext-json`, `google/protobuf`, `psr/http-client`, `psr/http-factory`, `psr/log`, `php-http/discovery`);
  - licence (BSD-3, same as the other official clients; confirm with legal);
  - CONTRIBUTING, SECURITY, CODEOWNERS.
- Tooling: PHPUnit 11, PHPStan at max level, PHP-CS-Fixer (PER-CS 2.0), Rector (optional), GitHub Actions skeleton.
- `bin/sync-protos` and `bin/generate-protos` (pinned protoc in Docker), committed `src/Proto/`, and a CI drift check.
- `Transport\Rest` (PSR-18, retries, error mapping).
- `Transport\Grpc`:
  - the interface;
  - **`CurlGrpcTransport`** (framing, trailers, deadlines, TLS/h2c, connection reuse);
  - **`ExtGrpcTransport`**;
  - automatic selection;
  - HTTP/2 capability detection.
- Connect helpers, `ConnectionParams`, `AdditionalConfig`, `Timeout`.
- Auth: API key, bearer, OIDC client credentials and password, discovery, refresh, optional PSR-16 token cache.
- Startup checks: meta, the version floor (a hard failure below 1.29), gRPC health.
- `ServerVersion` gating, and the exception hierarchy.
- `isReady`, `isLive`, `getMeta`, `getOpenIdConfiguration`.
- **Spike (week 1): done, GO** ([spike 0001](spikes/0001-grpc-transport.md)).
  - curl matches or beats ext-grpc latency (local and Weaviate Cloud) when libcurl is 8.4 or later.
  - Older libcurl falls back to a fresh connection per call, which is about 4× slower over TLS.
- **P0 progress (2026-09-25):**
  - Done: repo, CI, proto codegen, both gRPC transports, the REST transport, the connect helpers, API-key auth, the startup checks and the exception base.
  - Remaining: OIDC, TLS/mTLS config, per-protocol proxies, grpc-web, a typed `Meta` result, and retries.

## P1: Schema, data and tenants → `0.2.0-alpha` (about 4 weeks)

- `collections`: create, createFromArray, createFromConfig, get, use, listAll, exists, delete, deleteAll, exportConfig.
- All `Configure::*` and `Reconfigure::*` builders. The vectorizer, generative and reranker methods are **generated from a module table** (a YAML file listing each module's parameters), so new modules are one-line additions.
- `config`: get, update, addProperty, addReference, addVector, deletePropertyIndex, getShards, updateShards.
- `data`: insert, replace, update, deleteById, exists, and single references.
- `tenants`: all methods. Plus `withTenant` and `withConsistencyLevel`.

## P2: Search and generate → `0.3.0-beta` (about 5 weeks)

- `Transport\Mapper` for `SearchRequest` and `SearchReply`, covering properties, nested objects, references, metadata, vectors (bytes) and multi-vectors.
- The full `Filter` DSL, plus `Sort`, `GroupBy`, `MetadataQuery`, `QueryReference`, `QueryNested`, `TargetVectors` and `Rerank`.
- `query`: fetchObjects, fetchObjectById, fetchObjectsByIds, nearVector, nearObject, nearText, nearImage, nearMedia, hybrid, bm25.
- `generate`: every query method, plus singlePrompt, groupedTask, groupedProperties, generativeProvider and image inputs.
- `iterator()`.
- `graphqlRawQuery` as the escape hatch.
- **Beta gate:** the core read/write API freezes, apart from breaking fixes recorded in `UPGRADING.md`.

## P3: Bulk and analytics → `0.4.0-beta` (about 4 weeks)

- `data->insertMany`, `data->deleteMany`, `data->referenceAddMany`.
- `batch`:
  - `dynamic`, `fixedSize` (concurrency through `curl_multi` on the curl transport), `rateLimit`, `stream` (bidi when available, with the fallback);
  - failed objects and references, retries, backoff when vectorizers rate-limit.
- `aggregate`: overAll, near*, hybrid, groupBy, and all the `Metrics` types.
- Performance benchmark: import 100k objects with self-provided vectors through the curl, ext-grpc and async transports. Publish the numbers in the README.

## P4: Admin APIs → `0.5.0-rc` (about 3 weeks)

- `backup` (client and collection level, locations, incremental), `roles` + `Permissions`, `users` (db and oidc), `groups` (oidc), `alias`, `cluster` (nodes, statistics, replicate, replications, sharding state), `debug`. Spec: [15](15-admin-apis.md).

## P5: Ecosystem and 1.0 → `1.0.0` (about 4–5 weeks, partly in parallel with P3 and P4)

- `weaviate/weaviate-php-async` on AMPHP v3 ([06](06-integrations.md)).
- `weaviate/weaviate-laravel` and `weaviate/weaviate-symfony`.
- PHP tabs on the priority docs.weaviate.io pages ([08](08-docs-and-examples.md)).
- The [migration guide](07-migration-from-timkley.md) is published, and we reach out to the timkley maintainer.
- API review with the Python client maintainers, final `UPGRADING.md`, and SemVer commitments.
- Security review of auth, token handling, TLS defaults and logging redaction.

## P6: Keep up with the server (ongoing)

For each Weaviate minor release:
1. Sync the protos and regenerate.
2. Add rows to the parity matrix for the new Python features.
3. Implement them behind version gates.
4. Add the new server version to the CI matrix.

The current backlog is object TTL, `client.export`, `client.tokenization`, boost, diversity and query profile.

**Target:** each PHP client minor release ships within about 4 weeks of the Python release that adds the same features.

---

## Rough timeline

```
Week   1    4    8    12   16   20   24
P0     ████
P1         ████
P2             █████
P3                  ████
P4                      ███
P5                   ██████████
P6                               → ongoing
```

This is about 6 months to 1.0 with one engineer. A second engineer working in parallel on P5 (integrations and docs) brings it to about 4–5 months.

## Staffing and ownership (to be decided)

- A client owner from DevRel or SDK.
- A reviewer from the Python client team, for API parity sign-off at the P2 and P5 gates.
- Docs team contact for the PHP tabs.

## Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| The pure-PHP curl gRPC transport has edge cases (trailers, GOAWAY, TLS/ALPN, older libcurl) | Blocks P0 | Week-1 spike. Keep ext-grpc as a supported path. Document the minimum libcurl version (7.66 or later with nghttp2; verify) |
| Old PHP images ship libcurl without HTTP/2 | Some users can't connect | Detect at startup and give a clear error that names the fix (ext-grpc, or a curl with nghttp2) |
| Proto changes upstream break the mapper | Regressions | Pin the proto tag, run the drift check in CI, and run the server-version matrix |
| Scope creep from new server features during P0–P4 | 1.0 slips | New features go into P6 unless they're needed for parity at the frozen Python version |
| Namespace conflict with `timkley/weaviate-php` | Apps can't install both during migration | Resolved: the `Weaviate\Client\` root ([ADR 0005](decisions/0005-namespace-and-package-name.md)), enforced by an architecture test |
