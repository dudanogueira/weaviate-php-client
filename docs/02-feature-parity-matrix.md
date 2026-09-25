# 02: Feature parity matrix (Python v4 → PHP)

This is the source of truth for scope and progress. Update the **Status** column as work lands.

- **Transport:** `gRPC` or `REST`. The RPC or endpoint name is shown where it isn't obvious.
- **Phase:** see [04: Roadmap](04-roadmap.md).
- **Min server:** the lowest Weaviate version where the feature exists. `—` means it works on the whole supported range (**1.29 and later**, see [ADR 0004](decisions/0004-server-version-floor.md)).
- **Status:** ⬜ not started · 🟨 in progress · ✅ done · ⏸ deferred.

Python names come from client 4.24.0 (`main` at `eb5546a`, read 2026-09-25). Each section links to a detailed spec (09–15), which was written from the Python **source**. Rows marked † are still unverified, and each spec lists its own unverified items in its final section.

**Intentional deviations.** Where Python has a bug, the PHP client doesn't copy it; each spec lists these in its own section. Examples:
- the PQ `encoder.type` key;
- the export key names;
- the `top_occurrences_value` flag being dropped;
- lost references in stream batching.

Where an old server would silently ignore a field, PHP adds a version gate that Python doesn't have.

## 1. Connection & client

Full parameter-level spec: **[09: Connection](09-connection.md)**. Verified against the Python source.

| Python API | PHP API | Transport | Phase | Min server | Status |
|---|---|---|---|---|---|
| `connect_to_local(host="localhost", port=8080, grpc_port=50051, headers, additional_config, skip_init_checks, auth_credentials)` | `Weaviate::connectToLocal(host:, port:, grpcPort:, headers:, additionalConfig:, skipInitChecks:, auth:)`. Always plaintext; same host for both | — | P0 | — | ✅ |
| `connect_to_weaviate_cloud(cluster_url, auth_credentials, headers, additional_config, skip_init_checks)` | `Weaviate::connectToWeaviateCloud(clusterUrl:, auth:, headers:, additionalConfig:, skipInitChecks:)`. Derives the gRPC host (`grpc-{host}` / `.grpc.` for `*.weaviate.network`); 443 + TLS for both; warns about OIDC | — | P0 | — | 🟨 |
| `connect_to_custom(http_host, http_port, http_secure, grpc_host, grpc_port, grpc_secure, headers, additional_config, auth_credentials, skip_init_checks)` | `Weaviate::connectToCustom(...)`, the same 10 parameters plus `grpcPathPrefix:`. **Separate host, port and TLS for each protocol** | — | P0 | — | 🟨 |
| `WeaviateClient(connection_params, auth_client_secret, additional_headers, additional_config, skip_init_checks)` + `connect(force)` | `new WeaviateClient(connectionParams:, auth:, headers:, additionalConfig:, skipInitChecks:)` + `connect(force:)` | — | P0 | — | ✅ |
| `ConnectionParams(http=ProtocolParams(host, port, secure), grpc=ProtocolParams(...), grpc_path_prefix)`, `.from_params(...)`, `.from_url(url, grpc_port, grpc_secure)` | `ConnectionParams`, `ProtocolParams`, `::fromParams()`, `::fromUrl()`. Validates that the same host:port isn't used for both | — | P0 | — | ⬜ |
| `grpc_path_prefix` (grpc-web on the REST endpoint) | `grpcPathPrefix:` + `GrpcWebTransport` (HTTP/1.1, no nghttp2 needed; also an automatic fallback) | gRPC-web | P0 | 1.38.3 | ⬜ |
| `connect_to_embedded(hostname, port=8079, grpc_port=50050, version, persistence_data_path, binary_path, environment_variables, …)` | ⏸ Not planned (managing a subprocess from PHP). Use Docker or testcontainers | — | — | — | ⏸ |
| `connect_to_wcs` (deprecated alias) | Not ported | — | — | — | ⏸ |
| `Auth.api_key(api_key)`; a plain `str` is also treated as an API key | `Auth::apiKey()`; a plain `string` works too | both | P0 | — | ✅ |
| `Auth.bearer_token(access_token, expires_in=60, refresh_token=None)` | `Auth::bearerToken(accessToken:, expiresIn: 60, refreshToken:)` | REST (OIDC) | P0 | — | ⬜ |
| `Auth.client_credentials(client_secret, scope)` (a space-separated string or a list) | `Auth::clientCredentials(clientSecret:, scope: string\|array)` | REST (OIDC) | P0 | — | ⬜ |
| `Auth.client_password(username, password, scope)` | `Auth::clientPassword(username:, password:, scope:)` | REST (OIDC) | P0 | — | ⬜ |
| OIDC discovery + background token refresh | Discovery + **lazy refresh before each call** (no threads); optional PSR-16 token cache | REST | P0 | — | ⬜ |
| An `Authorization` header together with `auth` → warn and drop the header | Same | — | P0 | — | ✅ |
| `headers=`: lowercased, `None` rejected, forwarded as gRPC metadata | `headers:`, same rules | both | P0 | — | ✅ |
| `X-Weaviate-Client` header / metadata | `X-Weaviate-Client: weaviate-client-php/{v}-{sync\|async}` | both | P0 | — | ✅ |
| `X-Weaviate-Cluster-URL`, added automatically for Weaviate domains | Same, for every connect helper | both | P0 | — | ✅ |
| `AdditionalConfig.timeout`: `Timeout(query=30, insert=90, init=2, stream=None)` or a `(query, insert)` tuple | `timeout: Timeout\|array` | — | P0 | — | 🟨 |
| `AdditionalConfig.proxies`: `str \| Proxies(http, https, grpc)` + `trust_env` (HTTP_PROXY / HTTPS_PROXY / GRPC_PROXY) | `proxies: string\|Proxies`, `trustEnv:` | both | P0 | — | 🟨 |
| `AdditionalConfig.connection`: `ConnectionConfig(session_pool_connections=20, session_pool_maxsize=100, session_pool_max_retries=3, session_pool_timeout=5)` | `connection: ConnectionConfig(poolConnections:, poolMaxSize:, maxRetries:, poolTimeout:)` | both | P0 | — | ⬜ |
| `AdditionalConfig.grpc_config`: `GrpcConfig(channel_options, credentials)` (keepalive, custom CA, mTLS) | `grpcConfig: GrpcConfig(channelOptions:, tls: GrpcTlsConfig(caFile, certFile, keyFile, verifyPeer, verifyHost))` | gRPC | P0 | — | ⬜ |
| — (httpx defaults) | PHP additions: `httpTls:`, `httpClient:` (PSR-18), `logger:` (PSR-3), `tokenCache:` (PSR-16), `retry:`, `grpcTransport:` | — | P0 | — | ⬜ |
| gRPC max message size (default 104858000 bytes, overridden by `grpcMaxMessageSize` from meta) | Same | gRPC | P0 | — | ✅ |
| `skip_init_checks` (skips the gRPC health check and the package-version check; meta and the version floor still run) | `skipInitChecks:`, same semantics; the update check is opt-in only | — | P0 | — | ✅ |
| Hard failure below 1.27.0 (`WeaviateStartUpError`) | `WeaviateStartUpException` below **1.29.0** (a deliberate deviation) | REST meta | P0 | 1.29 | ✅ |
| `wait_for_weaviate(startup_period)` | `waitForWeaviate(int $seconds)` (public) | REST | P0 | — | ⬜ |
| `client.connect()` / `close()` / context manager | `connect()` / `close()`; `Weaviate::with…(fn)` scoped helper | — | P0 | — | ⬜ |
| `client.is_ready()` / `is_live()` / `is_connected()` | `isReady()` / `isLive()` / `isConnected()`. Errors return `false`; **`isLive` also runs the gRPC health check** | REST `.well-known` (+ gRPC health) | P0 | — | ✅ |
| `client.get_meta()` | `getMeta(): Meta` (currently returns the raw array) | REST `/v1/meta` | P0 | — | 🟨 |
| `client.get_open_id_configuration()` | `getOpenIdConfiguration()` | REST | P0 | — | ⬜ |
| `client.graphql_raw_query(q)` | `graphqlRawQuery(string $q)` (escape hatch) | REST `/v1/graphql` | P2 | — | ⬜ |
| `client.debug.get_object_over_rest(...)` | `$client->debug->getObjectOverRest()` (`@experimental`) | REST | P4 | — | ⬜ |
| `WeaviateAsyncClient` / `use_async_with_*` | `weaviate/weaviate-php-async`: `WeaviateAsync::connectTo*()` | — | P5 | — | ⬜ |

## 2. Collections management

Full spec: **[10: Collections & config](10-collections-and-config.md)**, from Python `main` at commit `eb5546a` (client 4.24.0). Vectorizer, generative and reranker factories are **generated from a module table** (`resources/modules/*.php`), and one generic `VectorizerConfig` replaces about 35 per-module classes. Configs come in three kinds: *Create* objects (serialized to JSON), *Update* objects (merged into the fetched schema) and *Read* objects (parsed back, with a `raw` escape hatch).

| Python API | PHP API | Transport | Phase | Min server | Status |
|---|---|---|---|---|---|
| `collections.create(name, description, properties, references, vector_config, generative_config, reranker_config, inverted_index_config, multi_tenancy_config, replication_config, sharding_config, object_ttl_config, skip_argument_validation, …)` | `$client->collections->create(...)`, the same arguments camelCased | REST `POST /v1/schema` | P1 | — | ⬜ |
| `collections.create_from_dict` / `create_from_config` | `createFromArray(array)` (also takes legacy JSON) / `createFromConfig(CollectionConfig)` | REST | P1 | — | ⬜ |
| `collections.get(name)` / `use(name)` | `get(name)` / `use(name)` (both return a handle and do no I/O) | — | P1 | — | ⬜ |
| `collections.list_all(simple)` | `listAll(simple: true)` | REST | P1 | — | ⬜ |
| `collections.exists(name)` | `exists(name)` | REST | P1 | — | ⬜ |
| `collections.delete(name \| list)` / `delete_all()` | `delete(string\|array)` / `deleteAll()` | REST | P1 | — | ⬜ |
| `collections.export_config(name)` | `exportConfig(name): CollectionConfig`. Writes the **server** keys; Python writes `distanceMetric`, `multiVector` and `internalBitCompression` | REST | P1 | — | ⬜ |
| `Property(name, data_type, description, tokenization, index_filterable, index_searchable, index_range_filters, nested_properties, vectorize_property_name, skip_vectorization, text_analyzer)` | `new Property(...)`, the same named arguments | — | P1 | text_analyzer 1.37 | ⬜ |
| `DataType.*` (TEXT, INT, NUMBER, BOOL, DATE, UUID, GEO_COORDINATES, PHONE_NUMBER, BLOB, OBJECT + arrays) | `enum DataType` | — | P1 | — | ⬜ |
| `DataType.BLOB_HASH` | `DataType::BlobHash` | — | P1 | 1.37 | ⬜ |
| `Tokenization.*`, `Configure.text_analyzer(...)`, `stopword_presets` | `enum Tokenization`, `Configure::textAnalyzer(...)`, `stopwordPresets:` | — | P1 | 1.37 | ⬜ |
| `ReferenceProperty(name, target_collection)` / `ReferencePropertyMultiTarget(name, target_collections)` | `new ReferenceProperty(...)` / `new ReferencePropertyMultiTarget(...)` (two classes, as in Python) | — | P1 | — | ⬜ |
| `Configure.Vectors.self_provided / text2vec_* / multi2vec_* / img2vec_* / ref2vec_*` | `Configure::vectors()->selfProvided()`, `->text2vecOpenAI()`, … (generated) | — | P1 | — | ⬜ |
| `Configure.MultiVectors.*` + encodings (MUVERA) | `Configure::multiVectors()->…`, `->muvera(...)` (gated) | — | P1 | MUVERA 1.31 | ⬜ |
| `Configure.VectorIndex.hnsw / flat / dynamic / hfresh / none` | `Configure::vectorIndex()->hnsw(...)` etc. | — | P1 | hfresh 1.36 | ⬜ |
| `Configure.VectorIndex.Quantizer.pq / bq / sq / rq / none` | `Configure::quantizer()->pq(...)` etc. (PQ `encoder.type` is sent correctly; Python sends `type_`) | — | P1 | RQ: HNSW 1.32 / flat 1.34; none: 1.32.4 / 1.33 | ⬜ |
| `Configure.Generative.*` / `Configure.Reranker.*` | `Configure::generative()->openAI(...)` / `Configure::reranker()->cohere(...)` (generated) | — | P1 | — | ⬜ |
| `Configure.inverted_index(bm25_b, bm25_k1, cleanup_interval_seconds, index_timestamps, index_property_length, index_null_state, stopwords_*)` | `Configure::invertedIndex(...)` | — | P1 | — | ⬜ |
| `Configure.replication(factor, async_enabled, deletion_strategy)` + `Configure.Replication.async_config(...)` | `Configure::replication(...)` + `Configure::replicationAsyncConfig(...)` (renamed, because PHP can't have both a method and a namespace called `replication`) | — | P1 | async_config 1.36 | ⬜ |
| `Configure.sharding(virtual_per_physical, desired_count, desired_virtual_count)` | `Configure::sharding(...)` | — | P1 | — | ⬜ |
| `Configure.multi_tenancy(enabled, auto_tenant_creation, auto_tenant_activation)` | `Configure::multiTenancy(...)` | — | P1 | — | ⬜ |
| `Configure.ObjectTTL.*` / `Reconfigure.ObjectTTL.*` | `Configure::objectTtl()->…` / `Reconfigure::objectTtl()->…` (one wire key, `objectTtlConfig`; Python's update writes `objectTTLConfig`) | — | P6 | 1.35 | ⬜ |
| `Integrations.*` (provider credentials helpers) | `Integrations::…` | headers | P1 | — | ⬜ |
| `collection.config.get(simple)` | `$col->config->get(simple:)` → `CollectionConfig` / `CollectionConfigSimple` | REST | P1 | — | ⬜ |
| `collection.config.update(description, property_descriptions, inverted_index_config, multi_tenancy_config, replication_config, vector_config, generative_config, reranker_config, object_ttl_config, …)` with `Reconfigure.VectorIndex / Quantizer / Vectors / inverted_index / replication / multi_tenancy / …` | `$col->config->update(...)` with `Reconfigure::…`. Only mutable fields; the legacy `vectorizerConfig:` index update is kept for old collections | REST `PUT /v1/schema/{c}` | P1 | — | ⬜ |
| `collection.config.add_property(p)` / `add_reference(r)` | `addProperty()` / `addReference()` | REST | P1 | — | ⬜ |
| `collection.config.add_vector(...)` | `addVector()` | REST | P1 | 1.31 | ⬜ |
| `collection.config.delete_property_index(name, IndexName)` | `deletePropertyIndex(name, IndexName)` | REST | P1 | 1.36 | ⬜ |
| `collection.config.delete_vector_index(name)` | `deleteVectorIndex(name)` | REST | P1 | 1.39 | ⬜ |
| `collection.config.get_shards()` / `update_shards(status: ShardStatus, shard_names)` | `getShards()` / `updateShards()`, `enum ShardStatus` | REST | P1 | — | ⬜ |
| `Configure.NamedVectors.*`, `Configure.Vectorizer.*`, legacy `vectorizer_config`/`vector_index_config` arguments, and the functions removed after Q3 '26 | Not ported; legacy JSON still works through `createFromArray` | — | — | — | ⏸ |

Per-property `skip_vectorization` is honoured with `vectorConfig`. Python ignores it on create; see [10 §18](10-collections-and-config.md).

## 3. Data (`collection.data`) and the collection handle

Full spec: **[11: Data, references & tenants](11-data-references-tenants.md)**. It covers the value-type mapping, PHP array rules, the date, vector and number encoding, and `generateUuid5`.

| Python API | PHP API | Transport | Phase | Min server | Status |
|---|---|---|---|---|---|
| `data.insert(properties, references, uuid, vector)` (Python's positional order) | `$col->data->insert(properties:, references:, uuid:, vector:): string` | REST `POST /v1/objects` | P1 | — | ⬜ |
| `data.insert_many(objects)` → `BatchObjectReturn` (errors keyed by index) | `insertMany(array $objects): BatchObjectReturn`. An empty list does no I/O (Python raises); oversized payloads are caught before sending | gRPC `BatchObjects` | P3 | — | ⬜ |
| `data.ingest(...)` | `ingest(...)` | see [11](11-data-references-tenants.md) | P3 | 1.36 | ⬜ |
| `data.replace(uuid, properties, references, vector)` | `replace(...)` | REST PUT | P1 | — | ⬜ |
| `data.update(uuid, properties, references, vector)` | `update(...)` | REST PATCH | P1 | — | ⬜ |
| `data.delete_by_id(uuid)` | `deleteById(uuid): bool` | REST DELETE | P1 | — | ⬜ |
| `data.delete_many(where, verbose, dry_run)` → `DeleteManyReturn` | `deleteMany(where:, verbose:, dryRun:): DeleteManyReturn` (including `limit`, read from a newer proto field †) | gRPC `BatchDelete` | P3 | — | ⬜ |
| `data.exists(uuid)` | `exists(uuid): bool` | REST HEAD | P1 | — | ⬜ |
| `data.reference_add(from_uuid, from_property, to)` | `referenceAdd(...)` | REST | P1 | — | ⬜ |
| `data.reference_replace(...)` / `reference_delete(...)` | `referenceReplace()` / `referenceDelete()` | REST | P1 | — | ⬜ |
| `data.reference_add_many(refs)` → `BatchReferenceReturn` | `referenceAddMany(array): BatchReferenceReturn` | **REST `POST /v1/batch/references`** | P3 | — | ⬜ |
| `DataObject(properties, uuid, vector, references)` | `new DataObject(...)` | — | P1 | — | ⬜ |
| `DataReference(from_property, from_uuid, to_uuid)` / `DataReference.MultiTarget(..., target_collection)` | `new DataReference(...)` / `DataReference::multiTarget(...)` | — | P1 | — | ⬜ |
| `ReferenceToMulti(target_collection, uuids)` | `new ReferenceToMulti(...)` | — | P1 | — | ⬜ |
| `GeoCoordinate(latitude, longitude)`, `PhoneNumber(number, default_country)` | `new GeoCoordinate(...)`, `new PhoneNumber(...)` | — | P1 | — | ⬜ |
| `weaviate.util.generate_uuid5(identifier, namespace)` | `Weaviate\Client\Util\generateUuid5()`: native, byte-compatible with Python's `str()` rules (test vectors in [11](11-data-references-tenants.md)) | — | P1 | — | ⬜ |
| `ConsistencyLevel` (ONE, QUORUM, ALL) | `enum ConsistencyLevel` | both | P1 | — | ⬜ |
| `collection.with_tenant(t)` / `.tenant` | `withTenant(string\|Tenant)` / `tenant()` | both | P1 | — | ⬜ |
| `collection.with_consistency_level(cl)` / `.consistency_level` | `withConsistencyLevel(ConsistencyLevel)` / `consistencyLevel()` | both | P1 | — | ⬜ |
| `len(collection)` | `$col->length()` (see §7) | gRPC `Aggregate` | P3 | — | ⬜ |
| `collection.exists()` | `$col->exists()` | REST | P1 | — | ⬜ |
| `collection.shards()` | `$col->shards()` | REST | P1 | — | ⬜ |
| `__str__` / `__repr__` (fetches the config) | Not ported: it would do network I/O | — | — | — | ⏸ |

## 4. Batch (`client.batch`, `collection.batch`)

Full spec: **[14: Batch](14-batch.md)**. It covers the design without threads (one "pump" step per call), `curl_multi` concurrency, and the `BatchStream` lock-step driver.

| Python API | PHP API | Transport | Phase | Min server | Status |
|---|---|---|---|---|---|
| `batch.dynamic(consistency_level)` | `$client->batch->dynamic(fn (ClientBatcher $b) => …, consistencyLevel:)` → `BatchReport`. Sizing comes from `/v1/nodes` `batchStats`; if that isn't readable (e.g. RBAC), it falls back to 100 × 2 **with a warning** (Python: a silent 10 × 2) | gRPC `BatchObjects` + REST refs | P3 | — | ⬜ |
| `batch.fixed_size(batch_size=100, concurrent_requests=2, consistency_level)` | `fixedSize(fn, batchSize:, concurrentRequests:, consistencyLevel:)`. Concurrency via `curl_multi` (needs `GrpcTransport::startUnary()/drive()`, see [01](01-architecture.md)) | gRPC + REST refs | P3 | — | ⬜ |
| `batch.rate_limit(requests_per_minute, consistency_level)` (the value is really **objects** per minute) | `rateLimit(fn, requestsPerMinute:, …)`, documented as objects per minute | gRPC + REST refs | P3 | — | ⬜ |
| `batch.stream(concurrency)` (server-side batching; `concurrency` is ignored in Python) | `stream(fn, fallbackToDynamic: true)`. Lock-step on ext-grpc/async; falls back to dynamic on unary transports | gRPC `BatchStream` | P3 | **1.36.0 (hard)** | ⬜ |
| Explicit (not context-manager) use | `start(BatchMode)` → `flush()` / `poll()` / `close()` / `abort()` (PHP addition) | — | P3 | — | ⬜ |
| `batch.add_object(collection, properties, references, uuid, vector, tenant)` | `ClientBatcher::addObject(...)`; `tenant` takes `string\|Tenant`; the UUID is generated when missing | — | P3 | — | ⬜ |
| `collection.batch.*.add_object(properties, references, uuid, vector)` | `CollectionBatcher::addObject(...)` (collection, tenant and consistency come from the handle) | — | P3 | — | ⬜ |
| `batch.add_reference(from_uuid, from_collection, from_property, to, tenant)` / collection variant | `addReference(...)`. Sent 50 per request over **REST `POST /v1/batch/references`** | REST | P3 | — | ⬜ |
| `batch.flush()` | `flush()` | — | P3 | — | ⬜ |
| `number_errors` / `failed_objects` / `failed_references` / `results` | `numberErrors()` / `failedObjects()` / `failedReferences()` / `results()` | — | P3 | — | ⬜ |
| `BatchObjectReturn`, `BatchReferenceReturn`, `ErrorObject(original_uuid, …)`, `ErrorReference` | readonly result classes | — | P3 | — | ⬜ |
| `wait_for_vector_indexing(shards, how_many_failures=5)`, `Shard` | `waitForVectorIndexing(?array $shards = null, int $howManyFailures = 5)`, `Shard` | REST | P3 | — | ⬜ |
| Retries: vectorizer rate-limit backoff, gRPC `UNAVAILABLE`, `WEAVIATE_BATCH_MAX_RETRIES` env var | Same rules (worst case is about 34 min by our reading, vs ~10.5 min in Python's comment †) | — | P3 | — | ⬜ |
| Stream: reconnect, out-of-memory backoff, server shutdown, 160 s GCP renewal | Same, with two Python bugs fixed (refs to failed objects are never sent; objects are no longer lost on the out-of-memory path) | gRPC | P3 | 1.36 | ⬜ |
| Async client: Python only has `stream()` | The async package offers every mode (a PHP addition) | — | P5 | — | ⬜ |
| `batch.experimental()`, `all_responses` | Deprecated in Python, not ported | — | — | — | ⏸ |

## 5. Query (`collection.query`), all gRPC `Search`

Full spec: **[12: Query & generate](12-query-and-generate.md)**. It covers the shared-args table, the filter DSL, result mapping and the iterator.

| Python API | PHP API | Phase | Min server | Status |
|---|---|---|---|---|
| `fetch_objects(limit, offset, after, filters, sort, include_vector, return_metadata, return_properties, return_references)` | `fetchObjects(...)` | P2 | — | ⬜ |
| `fetch_object_by_id(uuid, …)` | `fetchObjectById(uuid, …): ?ObjectSingleReturn` (a `WeaviateObject`) | P2 | — | ⬜ |
| `fetch_objects_by_ids(ids, …)` | `fetchObjectsByIds(...)` | P2 | — | ⬜ |
| `near_vector(near_vector, certainty, distance, target_vector, …)` (including dict / multi-target input) | `nearVector(...)` | P2 | — | ⬜ |
| `near_object(near_object, …)` | `nearObject(...)` | P2 | — | ⬜ |
| `near_text(query, move_to, move_away, …)` | `nearText(query:, moveTo: Move, moveAway: Move, …)` | P2 | — | ⬜ |
| `near_image(near_image, …)` (path, base64, bytes) | `nearImage(string\|\SplFileInfo, …)` | P2 | — | ⬜ |
| `near_media(media, media_type=AUDIO/VIDEO/THERMAL/DEPTH/IMU)` | `nearMedia(media:, mediaType: NearMediaType)` | P2 | — | ⬜ |
| `hybrid(query, alpha, vector, query_properties, fusion_type, max_vector_distance, bm25_operator, target_vector, alpha_param, …)` | `hybrid(...)` | P2 | bm25_operator 1.31, alpha_param 1.36.6 | ⬜ |
| `BM25Operator.or_(minimum_match)` / `and_()` / `and_cross()` | `BM25Operator::or(...)` / `::and()` / `::andCross()` (gated; Python doesn't gate it, and old servers silently ignore it) | P2 | 1.31; and_cross 1.37.15 / 1.38.8 / 1.39.0 | ⬜ |
| `NearVector` input forms, including `list_of_vectors` for multi-vector targets | same input shapes | P2 | — | ⬜ |
| `bm25(query, query_properties, operator)` | `bm25(...)` | P2 | — | ⬜ |
| Common arguments: `limit`, `offset`, `auto_limit`, `filters`, `rerank`, `include_vector`, `return_metadata`, `return_properties`, `return_references`, `group_by`, `target_vector`, `boost`, `diversity_selection` | Same named arguments | P2 | — | ⬜ |
| `Filter.by_property(p).equal / not_equal / less_than / less_or_equal / greater_than / greater_or_equal / like / contains_any / contains_all / contains_none / is_none / within_geo_range` | `Filter::byProperty('p')->equal(...)` etc. | P2 | contains_none 1.33.0 | ⬜ |
| `Filter.by_ref(link_on).by_property(...)`, `by_ref_multi_target`, `by_ref_count` | `Filter::byRef('p')->byProperty(...)`, `byRefMultiTarget()`, `byRefCount()` | P2 | — | ⬜ |
| `Filter.by_id()`, `by_creation_time()`, `by_update_time()` | `Filter::byId()`, `byCreationTime()`, `byUpdateTime()` | P2 | — | ⬜ |
| `&`, `\|`, `~`, `Filter.all_of`, `Filter.any_of`, `Filter.not_` | `->and()`, `->or()`, `->not()`, `Filter::allOf([...])`, `Filter::anyOf([...])`, `Filter::not($f)`. Immutable builders; nesting is left-leaning like Python, so the wire bytes match | P2 | not 1.33 † | ⬜ |
| `Sort.by_property(name, ascending)`, `by_id`, `by_creation_time`, `by_update_time`, chained | `Sort::byProperty('p', ascending: true)->byCreationTime(...)` | P2 | — | ⬜ |
| `GroupBy(prop, number_of_groups, objects_per_group)` | `new GroupBy(prop:, numberOfGroups:, objectsPerGroup:)` → `GroupByReturn` | P2 | — | ⬜ |
| `MetadataQuery(distance, certainty, score, explain_score, creation_time, last_update_time, is_consistent, query_profile)` / `.full()` / `.full_with_profile()` | `new MetadataQuery(...)` / `MetadataQuery::full()` / `::fullWithProfile()` | P2 | — | ⬜ |
| `QueryReference(link_on, return_properties, return_metadata, …)`, `QueryReference.MultiTarget` | `new QueryReference(...)` | P2 | — | ⬜ |
| `QueryNested(name, properties)` | `new QueryNested(...)` | P2 | — | ⬜ |
| `GeoCoordinate` (for `within_geo_range`) | `new GeoCoordinate(...)` (shared with §3) | P2 | — | ⬜ |
| `include_vector=True \| [names]` | `includeVector: true \| ['a','b']` | P2 | — | ⬜ |
| `target_vector=` + `TargetVectors.sum / average / minimum / manual_weights / relative_score` | `targetVector: 'x' \| TargetVectors::sum([...])` | P2 | — | ⬜ |
| `Rerank(prop, query)` | `new Rerank(prop:, query:)` | P2 | — | ⬜ |
| `HybridFusion.RANKED / RELATIVE_SCORE`, `HybridVector.near_text / near_vector` | `enum HybridFusion`, `HybridVector::nearText()` | P2 | — | ⬜ |
| `collection.iterator(include_vector, return_properties, return_metadata, cache_size, after)` | `$col->iterator(...)`: a PHP `Generator` that pages with the `after` cursor | P2 | — | ⬜ |
| `diversity_selection=Diversity.mmr(...)` | `diversitySelection: Diversity::mmr(...)` (gated) | P6 | near-*: 1.37.0; hybrid: 1.38.6 | ⬜ |
| `boost=Boost.filter / time_decay / numeric_decay / numeric_property / blend` | `boost: Boost::filter(...)` etc. (gated) | P6 | 1.38.0 | ⬜ |
| Query profile (`MetadataQuery(query_profile=True)`) | `new MetadataQuery(queryProfile: true)` | P6 | 1.36.9 | ⬜ |

## 6. Generate / RAG (`collection.generate`), gRPC `Search` + `generative.proto`

Full spec: **[12 §Generate](12-query-and-generate.md)**, including every parameter for each of the 22 providers.

| Python API | PHP API | Phase | Min server | Status |
|---|---|---|---|---|
| The query methods above, with `single_prompt`, `grouped_task`, `grouped_properties` | `$col->generate->nearText(..., singlePrompt:, groupedTask:, groupedProperties:)` | P2 | — | ⬜ |
| `GenerativeParameters.single_prompt(prompt, metadata, debug, image_properties, images)` / `.grouped_task(prompt, non_blob_properties, image_properties, images, metadata, debug)` | `GenerativeParameters::singlePrompt(...)` / `::groupedTask(...)`. Images or metadata **without a provider are rejected**, where Python silently drops them | P2 | — | ⬜ |
| `generative_provider=GenerativeConfig.*` (per query) | `generativeProvider: GenerativeConfig::…` | P2 | — | ⬜ |
| `GenerativeConfig.openai / azure_openai / anthropic / anyscale / aws_bedrock / aws_sagemaker / cohere / contextualai / databricks / deepseek / digitalocean / dummy / friendliai / google_gemini / google_vertex / meta / mistral / nvidia / ollama / xai / …` | `GenerativeConfig::openAI(...)` etc. (one factory per provider) | P2 | — | ⬜ |
| `GenerativeConfig.aws` / `.google` (deprecated, to be removed after Q3 '26) | Not ported; use `awsBedrock` / `awsSagemaker` / `googleVertex` / `googleGemini` | — | — | ⏸ |
| Result: `obj.generative.text / metadata / debug`, grouped `response.generative.*` | `$obj->generative->text`, `->metadata`, `->debug`; `$res->generative->…` | P2 | — | ⬜ |
| The deprecated `generated` fields | Not ported | — | — | ⏸ |

PHP intentionally avoids some Python bugs. These are listed in [12 §16](12-query-and-generate.md):
- `query.near_vector` returns a generative-typed result;
- xai and deepseek metadata is dropped;
- group-by generate ignores the newer fields;
- a string `include_vector` returns no vectors;
- the group rerank score is `0.0` instead of `null`.

## 7. Aggregate (`collection.aggregate`), gRPC `Aggregate`

Full spec: **[13: Aggregate](13-aggregate.md)**. gRPC `Aggregate` first ships in server v1.29.0, which is the PHP floor, so aggregate needs no gate.

| Python API | PHP API | Phase | Min server | Status |
|---|---|---|---|---|
| `over_all(filters, group_by, total_count, return_metrics)` | `overAll(...)` | P3 | — | ⬜ |
| `near_text(query, certainty, distance, move_to, move_away, object_limit, target_vector, …)` | `nearText(...)` (including `moveTo`/`moveAway`) | P3 | — | ⬜ |
| `near_vector / near_object / near_image` (`object_limit`, `distance`, `certainty`, `target_vector`) | same names; `targetVector` takes a **single string** (the server allows one target) | P3 | — | ⬜ |
| `hybrid(query, alpha, vector, query_properties, object_limit, bm25_operator, max_vector_distance, target_vector, …)` | `hybrid(...)`; `bm25Operator` gated at 1.31 †, `andCross` at 1.37.15 / 1.38.8 / 1.39.0 | P3 | — | ⬜ |
| `len(collection)` / async `length()` | `$col->length()` (explicit; not `Countable`, which would hide a network call) | P3 | — | ⬜ |
| `GroupByAggregate(prop, limit)` | `new GroupByAggregate(prop:, limit:)`; the return type narrows through a PHPStan conditional return | P3 | — | ⬜ |
| `Metrics(p).text(count, top_occurrences_count, top_occurrences_value, limit)` | `Metrics::of('p')->text(...)` (fixes a Python bug that drops the value flag); `min_occurrences` is deprecated and not ported | P3 | — | ⬜ |
| `.integer() / .number()` (count, sum, mean, median, mode, minimum, maximum) | `->integer(...)` / `->number(...)` | P3 | — | ⬜ |
| `.boolean()` (count, total_true, total_false, percentage_true, percentage_false), `.date_()` (count, minimum, maximum, median, mode) | `->boolean()`, `->date()`; dates come back as `DateTimeImmutable` | P3 | — | ⬜ |
| `.reference(pointing_to)` | `->reference()`, **experimental**: Python flags server-side bugs and doesn't export the result type | P3 | — | ⬜ |
| `AggregateReturn`, `AggregateGroupByReturn`, `AggregateGroup`, `GroupedBy`, per-type metric results | readonly result classes; missing fields are `null` | P3 | — | ⬜ |

- Consistency level has no effect on aggregate, because the request has no field for it.
- Python keeps a GraphQL fallback for servers older than 1.29 (`gql/aggregate.py`). PHP doesn't need one, because its floor is 1.29 ([ADR 0004](decisions/0004-server-version-floor.md)). Aggregate and `length()` always work.

## 8. Multi-tenancy (`collection.tenants`)

Full spec: **[11 §Tenants](11-data-references-tenants.md)**.

| Python API | PHP API | Transport | Phase | Min server | Status |
|---|---|---|---|---|---|
| `tenants.create(Tenant \| TenantCreate \| str \| list)` | `create(...)` | REST | P1 | — | ⬜ |
| `tenants.remove(names)` | `remove(...)` | REST | P1 | — | ⬜ |
| `tenants.update(Tenant \| TenantUpdate \| list)`: sent in **non-atomic chunks of 100** | `update(...)`, same chunking (documented as non-atomic) | REST | P1 | — | ⬜ |
| `tenants.activate(names)` / `deactivate(names)` / `offload(names)` | `activate()` / `deactivate()` / `offload()` | REST | P1 | — | ⬜ |
| `tenants.get()` / `get_by_names()` | `get()` / `getByNames()` | gRPC `TenantsGet` | P1 | — | ⬜ |
| `tenants.get_by_name(name)` | `getByName()`: REST GET (the gRPC path Python uses on 1.27 isn't needed) | REST / gRPC | P1 | — | ⬜ |
| `tenants.exists(name)` | `exists(name)` | REST HEAD | P1 | — | ⬜ |
| `TenantCreate(name, activity_status)` / `TenantUpdate(name, activity_status)`, with restricted allowed statuses | `new TenantCreate(...)` / `new TenantUpdate(...)`; invalid statuses are rejected before I/O | — | P1 | — | ⬜ |
| `TenantActivityStatus` (ACTIVE, INACTIVE, OFFLOADED, OFFLOADING, ONLOADING; deprecated HOT/COLD/FROZEN) | `enum TenantActivityStatus`. The deprecated cases aren't exposed but are still **decoded**; writes send the legacy wire names as Python does † | — | P1 | — | ⬜ |
| Auto tenant creation / activation (collection config) | See [10](10-collections-and-config.md) | — | P1 | — | ⬜ |

## 9. Backups (`client.backup`, `collection.backup`)

Full spec: **[15 §2](15-admin-apis.md)**.

| Python API | PHP API | Phase | Min server | Status |
|---|---|---|---|---|
| `backup.create(backup_id, backend, include_collections, exclude_collections, wait_for_completion, config, backup_location, …)` | `$client->backup->create(..., waitForCompletion:, waitTimeout:, pollInterval: 1.0)`. The timeout is a PHP addition: it throws `BackupTimeoutException` and does **not** cancel the backup | P4 | — | ⬜ |
| `get_create_status`, `restore`, `get_restore_status` | same, camelCase | P4 | — | ⬜ |
| `restore(..., roles_restore, users_restore)` / `overwrite_alias` | `rolesRestore:`, `usersRestore:`, `overwriteAlias:` | P4 | 1.30.10 / 1.32 | ⬜ |
| `BackupConfigCreate` / `BackupConfigRestore` (compression level, CPU %, chunk size, …) | `new BackupConfigCreate(...)` / `new BackupConfigRestore(...)` | P4 | — | ⬜ |
| `BackupLocation.FileSystem / S3 / GCP / Azure` (dynamic path or bucket) | `BackupLocation::s3(...)` etc. | P4 | — | ⬜ |
| Incremental backups | `incremental:` option | P4 | 1.37 | ⬜ |
| `cancel(backup_id, backend, …)` | `cancel(...)`. A 404 returns `false` (Python raises); cancelling a restore needs 1.36 | P4 | create: —, restore: 1.36 | ⬜ |
| `list_backups(backend, sort_by_starting_time_asc)` | `listBackups(backend:, sortByStartingTimeAsc:)` | P4 | 1.30 (sorting 1.33.2) | ⬜ |
| `BackupStorage` enum (FILESYSTEM, S3, GCS, AZURE) | `enum BackupStorage` | P4 | — | ⬜ |
| `collection.backup.create / get_create_status / restore / get_restore_status` (no cancel or list) | `$col->backup->…` | P4 | — | ⬜ |

## 10. RBAC, users & groups

Full spec: **[15 §3–5](15-admin-apis.md)**. RBAC starts at 1.28, so it's always available at the 1.29 floor. Deprecated Python methods aren't ported.

| Python API | PHP API | Phase | Min server | Status |
|---|---|---|---|---|
| `roles.create(role_name, permissions)` / `get` / `list_all` / `delete` / `exists` | `$client->roles->…` | P4 | — | ⬜ |
| `roles.add_permissions` / `remove_permissions` / `has_permissions` | same | P4 | — | ⬜ |
| `roles.get_user_assignments` / `get_group_assignments` | same | P4 | groups 1.32 | ⬜ |
| `Permissions.collections / data / tenants / backup / cluster / nodes (minimal/verbose) / roles / users / alias / replicate / groups (oidc) / mcp` + the `Actions` enums | `Permissions::collections(...)` etc.; nested `Permissions::nodes()->verbose()`, `Permissions::groups()->oidc()`; one class per scope, used for input and output | P4 | mcp 1.37; others vary | ⬜ |
| `users.get_my_user()` | `$client->users->getMyUser()` | P4 | — | ⬜ |
| `users.db.create / delete / rotate_key / activate / deactivate / list_all / get / assign_roles / revoke_roles / get_assigned_roles` | `$client->users->db->…`. Revoke also sends `userType` (Python builds it but never sends it †) | P4 | 1.30 | ⬜ |
| `users.oidc.assign_roles / revoke_roles / get_assigned_roles` | `$client->users->oidc->…` | P4 | 1.30 | ⬜ |
| `groups.oidc.*` (**missing before**) | `$client->groups->oidc->…` | P4 | 1.32 | ⬜ |

## 11. Aliases (`client.alias`)

| Python API | PHP API | Phase | Min server | Status |
|---|---|---|---|---|
| `alias.create / get / list_all / update / delete / exists` | `$client->alias->…`, with a client-side gate on every method | P4 | 1.32 | ⬜ |

## 12. Cluster & replication (`client.cluster`)

Full spec: **[15 §7](15-admin-apis.md)**.

| Python API | PHP API | Phase | Min server | Status |
|---|---|---|---|---|
| `cluster.nodes(collection, shard, output="minimal"\|"verbose")` | `$client->cluster->nodes(collection:, shard:, output: Verbosity::Verbose)` | P4 | — | ⬜ |
| `cluster.statistics()` | `statistics()` | P4 | † | ⬜ |
| `cluster.replicate(collection, shard, source_node, target_node, replication_type=COPY\|MOVE)` | `replicate(...)` | P4 | 1.32 | ⬜ |
| `cluster.replications.get / list_all / query / cancel / delete / delete_all` | `$client->cluster->replications->…` | P4 | 1.32 | ⬜ |
| `cluster.query_sharding_state(collection, shard)` | `queryShardingState(...)` | P4 | 1.32 | ⬜ |

## 12b. Export & tokenization (missing before)

| Python API | PHP API | Phase | Min server | Status |
|---|---|---|---|---|
| `client.export.*` (collection export, with a wait loop) | `$client->export->…`; the wait loop is shared with backups | P6 | 1.37 | ⬜ |
| `client.tokenization.*` | `$client->tokenization->…` | P6 | 1.37 | ⬜ |

- Only Python's own client-side version checks are enforced as gates. Minimums that appear only in Python's tests are documented and not enforced ([15 §1.6](15-admin-apis.md)).
- Boolean query parameters are sent as `"true"`/`"false"`, not the `1` that `http_build_query` produces.

## 13. Explicitly out of scope

- `connect_to_embedded`: use Docker or testcontainers instead.
- `weaviate.agents` (Query/Transformation/Personalization Agents): these are separate Weaviate Cloud services with their own Python package. They'll be reconsidered after 1.0.
- The v3 GraphQL-style API: v4 is the only design target.
