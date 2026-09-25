# ADR 0004: Weaviate server version floor

- **Status:** Accepted: floor **1.29.0**
- **Decided:** 2026-09-25

## Context

Python v4 (4.17 and later) needs Weaviate 1.27 or later. Several features need newer servers:

| Feature | Min server |
|---|---|
| gRPC Aggregate | 1.29 |
| Per-query generative provider | 1.30 |
| Aliases, replication ops | 1.32 |
| Object TTL | 1.35 |
| Server-side batch stream (GA) | 1.36 (preview 1.34) |
| Collection export, blobHash | 1.37 |
| Boost | 1.38 |
| gRPC-web on the REST endpoint | 1.38.3 |

## Decision

- **Floor: 1.29.0.** This is **higher than Python v4**, which accepts 1.27 and later.
  - `connect()` reads `/v1/meta` and throws `WeaviateStartUpException` for older servers ("Weaviate version X is not supported. Please use 1.29.0 or higher").
  - This check runs even with `skipInitChecks`, as Python's floor check does.
- Newer features are version-gated. `UnsupportedFeatureException` names the feature, the server version and the version it needs.
- CI tests 1.29 (the floor) plus the latest patch of the four newest minor versions.

## Rationale

- **Aggregate and `length()` always work.** gRPC `Aggregate` first ships in 1.29.0. Without a floor of 1.29, PHP would need Python's GraphQL aggregate fallback (`gql/aggregate.py`, marked "remove once 1.29 is the minimum supported version"), or it would have to leave aggregate and `length()` broken on 1.27–1.28 ([13](../13-aggregate.md)).
- **No legacy wire paths.** The floor removes several:
  - the pre-1.29 `nearVector` encoding (`vector_bytes` instead of `Vectors`);
  - the pre-1.27.14 generative wire (`single_response_prompt` etc.);
  - the gRPC `getByName` path on 1.27;
  - the backup-location gate at 1.27.2.
- **Features always available at 1.29:**
  - RBAC (1.28), which needs no gate;
  - per-query generative providers (1.27.14);
  - multi-vectors (1.29).
- **The cost is small.** 1.27 and 1.28 are old minors, and they'll be further outside Weaviate's support window by the time the PHP client reaches 1.0. Users on them can upgrade the server, or use `graphqlRawQuery()` in the meantime.

## Consequences

- **Removed gates:** aggregate/`length()` (1.29), RBAC (1.28), `generativeProvider` (1.27.14), multi-vector inputs (1.29), backup location (1.27.2).
- **Remaining gates**, as in [02](../02-feature-parity-matrix.md):
  - 1.30: DB users, `listBackups`
  - 1.31: BM25 operator, `addVector`, MUVERA
  - 1.32: aliases, replication, RQ, OIDC groups
  - 1.33: `containsNone`
  - 1.35: object TTL
  - 1.36: stream batching, `ingest`, restore cancel, HFresh, delete property index
  - 1.37: export, tokenization, blobHash, incremental backups, text analyzer, diversity
  - 1.38: boost
  - 1.38.3: gRPC-web
  - 1.39: delete vector index
- **This is a documented deviation from Python.** The README and the connection error message state the 1.29 requirement.
- **Review the floor at each major release.** Raising it is a breaking change after 1.0, so it moves only with a major version or an announced deprecation window.
