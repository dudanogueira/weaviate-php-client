# 14: Batch specification

This maps **every** batching feature of Python v4 to PHP: `client.batch`, `collection.batch`, the four modes, results, retries and the `BatchStream` protocol. The sources are `weaviate/collections/batch/{base,sync,async_,batch_wrapper,client,collection,grpc_batch,rest}.py`, `weaviate/collections/classes/batch.py`, `weaviate/connect/v4.py` (`grpc_batch_objects`, `grpc_batch_stream`) and `weaviate/retry.py` on the Python client's `main` branch, plus `grpc/proto/v1/batch.proto` and `adapters/handlers/grpc/v1/batch/*.go` on the server's `main` branch. All were read on 2026-09-25. Rows in [02 §4](02-feature-parity-matrix.md#4-batch-clientbatch-collectionbatch) link here.

## 1. The model: a queue, a scheduler and a sender, but no threads

Python's batcher is built on threads:

| Python piece | What it does |
|---|---|
| Caller thread | `add_object()` validates and appends to a locked queue, then **blocks** while the queue is too long |
| `BgBatchScheduler` thread | Every 10 ms, if a slot is free, pops up to `recommended_num_objects` objects and 50 references and submits them to a `ThreadPoolExecutor` |
| `BgDynamicBatchRate` thread | Every second, reads `GET /v1/nodes` and resizes the batch and the concurrency (dynamic mode only) |
| Executor workers | Each sends one gRPC `BatchObjects` and then one REST `POST /v1/batch/references`, handles retries, and writes results under a lock |
| Stream mode (`sync.py`) | Two threads instead: `BgBatchLoop` fills a size-1 queue, `BgBatchRecv` drives the bidi `BatchStream` and handles server messages |

PHP has no threads, so **the caller's own calls drive everything**. Every `addObject()`, `addReference()`, `poll()`, `flush()` and `close()` call runs one *pump* step. The pump:

1. makes non-blocking progress on in-flight requests,
2. harvests finished requests,
3. updates the sizing state, and
4. dispatches new requests while there are free slots.

Blocking happens only where Python's caller thread also blocks, which is when the queue is full or the server is overloaded. That keeps the same backpressure contract. It's also why concurrency is still real on the curl transport: requests are started through `curl_multi` and progress in the kernel and on the server while PHP builds the next objects.

```
            addObject() / addReference() / poll() / flush() / close()
                                   │
                                   ▼
   ┌───────────── pump() ─────────────────────────────────────────────┐
   │ transport->drive(0 | until-next-event)   ← curl_multi_exec/select │
   │ harvest done calls → results, retries, release uuid lookup        │
   │ refresh sizing (dynamic: /v1/nodes at most once per second)       │
   │ while slots free && not held && ready: dispatch next request      │
   └───────────────────────────────────────────────────────────────────┘
```

The engine is written once, as a plan-style state machine (see [06](06-integrations.md)). The sync client runs it in the caller. The async package runs the same state machine in fibers, with real timers (§10).

## 2. Entry points

### 2.1 `$client->batch` (Python `_BatchClientWrapper`)

| Python | PHP | Notes |
|---|---|---|
| `client.batch.dynamic(consistency_level=None)` | `dynamic(Closure $callback, ?ConsistencyLevel $consistencyLevel = null): BatchReport` | §4.1 |
| `client.batch.fixed_size(batch_size=100, concurrent_requests=2, consistency_level=None)` | `fixedSize(Closure $callback, int $batchSize = 100, int $concurrentRequests = 2, ?ConsistencyLevel $consistencyLevel = null): BatchReport` | §4.2 |
| `client.batch.rate_limit(requests_per_minute, consistency_level=None)` | `rateLimit(Closure $callback, int $requestsPerMinute, ?ConsistencyLevel $consistencyLevel = null): BatchReport` | §4.3 |
| `client.batch.stream(*, concurrency=None, consistency_level=None)` | `stream(Closure $callback, ?int $concurrency = null, ?ConsistencyLevel $consistencyLevel = null, bool $fallbackToDynamic = true): BatchReport` | §4.4. `fallbackToDynamic` is a PHP addition |
| `client.batch.experimental(...)` | — | Deprecated in 4.20, due for removal. **Not ported** |
| (context manager object) | `start(BatchMode $mode, ?ConsistencyLevel $consistencyLevel = null): ClientBatcher` | The explicit API (§3.2) |
| `client.batch.failed_objects` | `failedObjects(): list<ErrorObject>` | From the **last closed** batch, as in Python |
| `client.batch.failed_references` | `failedReferences(): list<ErrorReference>` | Same |
| `client.batch.results` | `results(): BatchResult` | Same |
| — | `numberErrors(): int` | Python only has this on the context object. It's added here for symmetry |
| `client.batch.wait_for_vector_indexing(shards=None, how_many_failures=5)` | `waitForVectorIndexing(?array $shards = null, int $howManyFailures = 5): void` | §7.4 |

### 2.2 `$collection->batch` (Python `_BatchCollectionWrapper`)

| Python | PHP | Notes |
|---|---|---|
| `collection.batch.dynamic()` | `dynamic(Closure $callback): BatchReport` | Consistency level and tenant come from the handle (`withConsistencyLevel()`, `withTenant()`). **There's no `consistencyLevel` argument**, as in Python |
| `collection.batch.fixed_size(batch_size=100, concurrent_requests=2)` | `fixedSize(Closure $callback, int $batchSize = 100, int $concurrentRequests = 2)` | |
| `collection.batch.rate_limit(requests_per_minute)` | `rateLimit(Closure $callback, int $requestsPerMinute)` | |
| `collection.batch.stream(*, concurrency=None)` | `stream(Closure $callback, ?int $concurrency = null, bool $fallbackToDynamic = true)` | |
| `collection.batch.experimental()` | — | Not ported |
| `failed_objects` / `failed_references` / `results` / `wait_for_vector_indexing` | same as §2.1 | |
| — | `start(BatchMode $mode): CollectionBatcher` | |

The callback is the **first** parameter, so the mode options read naturally as named arguments: `fixedSize(fn (…) => …, batchSize: 200, concurrentRequests: 4)`.

### 2.3 `BatchMode` (the explicit form)

```php
BatchMode::dynamic();
BatchMode::fixedSize(batchSize: 100, concurrentRequests: 2);
BatchMode::rateLimit(requestsPerMinute: 600);
BatchMode::stream(concurrency: null, fallbackToDynamic: true);
```

Validation happens **before any I/O** ([03](03-api-design.md) rule):
- `batchSize` must be at least 1 and at most 10 000. Python doesn't validate this; PHP adds the limit to guard against runaway memory use.
- `concurrentRequests` must be 1–32. Python doesn't validate this either.
- `requestsPerMinute` must be at least 1. Python raises `WeaviateInvalidInputError` here too.
- `concurrency` on `stream` is accepted and ignored with a debug log. Python hard-codes one stream: the client wrapper says "hard-code until client-side multi-threading is fixed", and the collection wrapper stores the value but `_BatchBaseSync` never reads it.

## 3. The `Batcher` API

### 3.1 Scoped (closure) form, Python's `with … as batch:`

```php
$report = $client->batch->dynamic(function (ClientBatcher $b) use ($rows) {
    foreach ($rows as $row) {
        $b->addObject(collection: 'Article', properties: $row);
    }
});
```

Rules:
1. `start()` is called, then the callback, then **`close()` in `finally`**. That matches Python's `__exit__`, which calls `_shutdown()` + `_wait()` whether or not the body raised, so **pending objects are still sent after an exception**. Use `$b->abort()` inside the body if you want them dropped (§3.4).
2. If the body throws and `close()` also throws, the **body's exception is rethrown**, and the `close()` exception is logged at `error` and attached as `previous` when the body's exception has none.
3. The callback's return value is discarded. The method returns the `BatchReport`.
4. After it returns, `$client->batch->failedObjects()` and the other accessors show this batch, as in Python. A new batch resets them (Python's `_batch_data = _BatchDataWrapper()`).

### 3.2 Explicit form, for producers that aren't a single loop

```php
$b = $client->batch->start(BatchMode::fixedSize(batchSize: 200, concurrentRequests: 4));
try {
    foreach ($queue->consume() as $msg) {
        $b->addObject(collection: 'Event', properties: $msg->payload, uuid: $msg->id);
        if ($msg->isLastOfPage()) {
            $b->flush();           // durable checkpoint
            $queue->ack($msg);
        }
    }
} finally {
    $report = $b->close();
}
```

`__destruct()` is a safety net only: if the batcher wasn't closed, it logs a `warning` and calls `close()`, catching every `Throwable`. **Don't rely on it.** At script shutdown the curl handles may already be gone.

### 3.3 `ClientBatcher::addObject()` (Python `add_object`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `collection` | `collection` | `string` | required | Must not be empty. The first letter is upper-cased (`_capitalize_first_letter`) |
| `properties` | `properties` | `?array<string,mixed>` | `null` | §8.2 |
| `references` | `references` | `?array<string, string\|UuidInterface\|list<…>\|ReferenceToMulti>` | `null` | Inline references, sent **inside** the object |
| `uuid` | `uuid` | `string\|UuidInterface\|null` | `null` | Accepts a bare UUID, a `weaviate://localhost/…/{uuid}` beacon or an `http…/v1/objects/{uuid}` href (`get_valid_uuid`). **If it's null, a UUIDv4 is generated at add time** |
| `vector` | `vector` | `list<float>\|array<string, list<float>\|list<list<float>>>\|null` | `null` | A list is the unnamed vector. An associative array holds named vectors, and each value can be single or multi. An unnamed multi-vector (a list of lists) throws `InvalidInputException`, because Python crashes on it |
| `tenant` | `tenant` | `string\|Tenant\|null` | `null` | A `Tenant` object is reduced to `->name` |
| returns `UUID` | returns `string` | | | The object's UUID, whether given or generated |

The **existing object is replaced** if the UUID already exists (the server upserts). Generating the UUID on the client **at add time** is what makes retries safe: re-sending the same object is idempotent.

### 3.4 Other `Batcher` methods

| Python | PHP | Behaviour |
|---|---|---|
| `add_reference(from_uuid, from_collection, from_property, to, tenant=None)` | `ClientBatcher::addReference(string\|UuidInterface $fromUuid, string $fromCollection, string $fromProperty, string\|UuidInterface\|array\|ReferenceToMulti $to, string\|Tenant\|null $tenant = null): void` | A list `to` becomes **one reference per UUID**. `ReferenceToMulti` sets `to_collection` |
| collection `add_object(properties, references, uuid, vector)` | `CollectionBatcher::addObject(?array $properties = null, ?array $references = null, $uuid = null, ?array $vector = null): string` | Collection and tenant come from the handle |
| collection `add_reference(from_uuid, from_property, to)` | `CollectionBatcher::addReference($fromUuid, string $fromProperty, $to): void` | |
| `flush()` | `flush(): void` | §6.2 |
| `number_errors` | `numberErrors(): int` | Failed objects plus failed references, **live** |
| — | `failedObjects()`, `failedReferences()`, `results()` | **Live** views of this batch. That's a PHP addition, since Python only exposes them on the wrapper after exit |
| — | `poll(): void` | One non-blocking pump step. Long-lived producers call it when idle (§6.1) |
| (`__exit__`) | `close(): BatchReport` | §6.3. Idempotent |
| — | `abort(): BatchReport` | PHP addition. Drops queued, unsent items, which aren't reported as failed. Waits for in-flight requests and then closes |

Both batchers implement the `Batcher` interface (`flush`, `poll`, `close`, `abort`, `numberErrors`, `failedObjects`, `failedReferences`, `results`). **[03](03-api-design.md#batch) should type-hint the concrete class** (`ClientBatcher` / `CollectionBatcher`), because `addObject` has a different signature on each.

### 3.5 `BatchReport` (PHP addition)

A `final readonly class` that `close()` returns: `failedObjects`, `failedReferences`, `results: BatchResult`, `numberErrors`, `hasErrors`, `objectsAdded`, `referencesAdded`, `importedShards: list<Shard>`, `elapsedSeconds`, and `mode`. `mode` holds the **effective** mode, so a stream that fell back reports `dynamic`.

## 4. Modes

### 4.1 `dynamic`: sizing driven by the server's queue

Start values (Python `_BatchBase.__init__`):

| | Without vectorizer | With vectorizer |
|---|---|---|
| Objects per request | 10 | 48 (`VECTORIZER_BATCHING_STEP_SIZE`, half of Cohere's max of 96) |
| Concurrent requests | 2 | 2 |
| References per request | 50 (fixed in every client-side mode) | 50 |
| Upper bound | 1000 objects (`__max_batch_size`), 10 concurrent (`MAX_CONCURRENT_REQUESTS`) | — |

**Vectorizer detection** happens once, before the batch starts:
- Client batch: `GET /v1/schema` (`list_all(simple=True)`). If any collection has a named vector whose vectorizer isn't `none`, or a legacy `vectorizer_config`, it's `true`. **A 403 means `false`.** Python re-checks this on every batch while the cached value is `false`; PHP caches it per client for 60 s.
- Collection batch: `GET /v1/schema/{name}`. **A 404 means `false`**, because autoschema may create the collection later. The result is cached on the wrapper.

**The sizing loop, once a second.** In PHP it runs inside the pump, at most once per second:
1. Call `GET /v1/nodes`, and read `nodes[0].batchStats.queueLength` and `ratePerSecond`. Only the **first node** is used, as in Python.
2. If `batchStats.queueLength` is missing, the server is using **async indexing**. Switch permanently to fixed 1000 objects and 10 concurrent requests. Python does this **even with a vectorizer**; see §12.
3. **Without a vectorizer:**
   - If `queueLength == 0`, add 50 objects (up to 1000). If the size is already 1000, the local queue is over 1000, at least 1 s has passed since the last scale-up, and concurrency is below 10, add one concurrent request.
   - Otherwise work out `ratio = queueLength / ratePerSecond` and `perWorker = rate / concurrency`:
     - `1.9 < ratio < 2.1`: size = `floor(perWorker)`.
     - `ratio ≤ 1.9`: size = `floor(min(size × 1.5, perWorker × 2 / ratio))`. If the size is 1000, add one concurrent request.
     - `ratio < 10`: size = `floor(perWorker × 2 / ratio)`. If the size is below 100 and concurrency is above 2, drop one concurrent request.
     - Otherwise, size = **0** and concurrency = 2. **Sending stops and `addObject` blocks** until a later poll lowers the ratio.
4. **With a vectorizer** (only after a request has completed since the last check), take `maxTook` = the slowest of the last 2 request durations, against the target `BATCH_TIME_TARGET` = 10 s:
   - `> 20 s`: concurrency 1, size 48.
   - `> 10 s`: drop one concurrent request. At 1, drop one 48-object step. At one step, sleep `maxTook − 10` s between requests.
   - `< 7 s` (`3 × 10 // 4`): clear the sleep. Otherwise, if concurrency is below 3, add one. Otherwise add one 48-object step.

PHP deviations. Each is logged at `debug` and covered by tests:
- **Clamp the size to 1–1000 and concurrency to 1–10.** Python's `ratio ≤ 1.9` branch can go above 1000, and its `+1` concurrency there is unbounded.
- **Guard `ratePerSecond == 0`.** In Python, that division raises, and the exception is swallowed at `debug`.
- **If `/v1/nodes` fails** (for example an RBAC user without `nodes` read access), Python silently stays at **10 objects and 2 concurrent requests forever**, because `get_nodes_status()` returns `[]`, `status[0]` raises, and the error is logged at `debug`. PHP logs **one warning** and switches to `fixedSize(100, 2)`, the Python `fixed_size` defaults. **This needs sign-off**, since it changes behaviour.

### 4.2 `fixedSize(batchSize, concurrentRequests)`

- Each request carries up to `batchSize` objects. References are always 50 per request, as in Python, whatever `batchSize` says, even though the Python docstring says "objects/references".
- At most `concurrentRequests` `BatchObjects` calls are in flight at once. On the curl transport that means `curl_multi` handles (§5.1).
- There's no server polling.

### 4.3 `rateLimit(requestsPerMinute)`

`requestsPerMinute` is really **objects per minute** (the Python docstring says "the number of objects to send to Weaviate per minute"). It's meant to keep a vectorizer under its quota.

| Derived value | Formula (Python) | Example: 600 | Example: 3000 |
|---|---|---|---|
| `concurrentRequests` | `(rpm + 1000) // 1000` | 1 | 4 |
| Objects per request | `rpm // concurrentRequests` | 600 | 750 |
| Minimum gap between requests | `baseTime × objectsInPreviousRequest / rpm`, with `baseTime` = **62 s** (2 s of margin per minute) | 62 s | 15.5 s |

- The first request goes out immediately.
- In PHP, **the gap is enforced by blocking the caller** (`usleep` inside the pump) once the queue is full. In Python the scheduler thread sleeps instead. Either way the producer is throttled.
- `close()` also honours the gap, so closing a rate-limited batch can take up to about a minute.
- When a vectorizer rate-limit error comes back (§7.2), the next allowed send moves to `now + 62 × (highestRetry + 1)` s, and `baseTime` goes up by 1 s for the rest of the batch.

### 4.4 `stream`: server-side batching (`BatchStream`)

Prerequisites:
1. **Server 1.36.0 or later.** Otherwise throw `UnsupportedFeatureException("Server-side batching", <version>, "1.36.0")`. That's a hard check, as in Python, and it happens **before** the transport check. [02 §4](02-feature-parity-matrix.md#4-batch-clientbatch-collectionbatch) says "1.34 preview"; the Python client doesn't allow anything before 1.36.
2. **A bidi transport** (`$transport->supportsBidiStreaming()`), which means ext-grpc or AMPHP. On curl or grpc-web:
   - If `fallbackToDynamic` is true (the default), log a **one-time `notice`** ("BatchStream needs bidirectional gRPC; using client-side dynamic batching. Install ext-grpc or use weaviate-php-async for server-side batching") and run `dynamic` ([ADR 0002](decisions/0002-pluggable-grpc-transport.md)). `BatchReport::$mode` is `dynamic`.
   - If it's false, throw `UnsupportedFeatureException`.
   - Python's async client fails early over grpc-web in the same way.

The protocol and state machine are in §9. The key point for PHP: **Python's stream client lets only about one message be unacknowledged at a time.** `add_object` blocks while `len(inflight_objs) >= batch_size`, and one message carries `batch_size` items. So a **synchronous lock-step loop** (write one `Data` message, then read replies until its `Acks` arrives) has the same flow control as Python's two threads. That's what makes streaming practical on ext-grpc without threads.

## 5. Concurrency per transport (sync client)

### 5.1 The transport extension this needs

[01](01-architecture.md#grpc) currently defines only a blocking `unary()`. Batching needs a non-blocking start:

```php
/** @internal */
interface GrpcTransport
{
    // existing: unary(), supportsBidiStreaming(), bidiStream()

    /** Start a unary call without waiting. */
    public function startUnary(string $method, Message $request, string $responseClass, ?float $timeout = null): PendingUnary;

    /** Make progress on every started call; block at most $maxWait seconds (0 = non-blocking). */
    public function drive(float $maxWait): void;
}

/** @internal */
interface PendingUnary
{
    public function isDone(): bool;
    /** @throws GrpcException */
    public function result(): Message;
}
```

| Transport | `startUnary` / `drive` | Real concurrency for `concurrentRequests > 1` |
|---|---|---|
| `CurlGrpcTransport` | Adds an easy handle to a shared `curl_multi` handle, with `CURLMOPT_PIPELINING = CURLPIPE_MULTIPLEX`, so all calls are HTTP/2 streams on **one connection**. `drive()` calls `curl_multi_exec` plus `curl_multi_select($maxWait)` and completes handles from `curl_multi_info_read` | **Yes.** Requests progress while PHP encodes the next objects, but only during pump calls. Between pumps, the server keeps processing whatever it has already received |
| `ExtGrpcTransport` | `UnaryCall::start()` sends the request and returns. `result()` calls `wait()` | **Believed yes.** Several calls can be started before the first `wait()`, so the server works on them in parallel. **Verify this in the P3 spike** (§13) |
| `GrpcWebTransport` | PSR-18 is synchronous, so `startUnary` completes the call at once | **No.** It's effectively `concurrentRequests = 1`, and a `notice` is logged once when N > 1 |

REST references (§8.3) go through the PSR-18 client, which is synchronous. They're sent from the pump after the object requests have been started, so they overlap with in-flight gRPC calls.

### 5.2 Swoole and OpenSwoole

With coroutine hooks, `curl_multi` is non-blocking already, so no special handling is needed. ext-grpc isn't hooked. Document that.

## 6. Behaviour rules

### 6.1 `addObject` / `addReference` (the pump and backpressure)

`addObject`:
1. Check that the batcher isn't closed or failed. Otherwise throw `BatchException` (Python: "Batch thread died unexpectedly" / the background exception).
2. **Validate synchronously** and throw `InvalidInputException` (a `BatchValidationException`) **before queueing**:
   - an empty collection,
   - a bad UUID,
   - an `id` key at the top level of `properties`, or a `vector` key at any level,
   - a malformed vector.

   **This is a deviation from Python**, where `_validate_props` runs at send time inside the worker, and one bad object fails **every object in its request**.
3. Encode the object to protobuf now (§8.1), and keep both the encoded bytes and the domain object. If the encoded size is over `grpcMaxMessageSize − 4`, throw `BatchValidationException`; Python raises this only in stream mode.
4. Append it to the queue, add its UUID to the **in-flight lookup**, and add `Shard(collection, tenant)` to the imported shards.
5. `pump(nonBlocking)`.
6. **Block while** `recommendedObjects == 0 || queued >= 2 × recommendedObjects` (Python's rule). While blocked, call `pump(blocking)`: `drive()` waits on I/O, and the wait is capped by the next sizing poll or the retry and rate-limit hold. When the size is 0, re-poll `/v1/nodes` every second.
7. Return the UUID.

`addReference` works the same way, except:
- There's no UUID to generate.
- The reference is held back while its `fromUuid` **or** `toUuid` is still in the in-flight lookup, meaning the object was added in this batch and hasn't finished yet. That ensures a reference never goes out before its objects.
- It blocks only while `recommendedObjects == 0`.

**A partial batch goes out when** any of these is true:
- `flush()` or `close()` is called,
- the oldest queued item is at least **1 s** old and a pump runs (Python sends whatever it has after 1 s without new items), or
- the queue reaches the batch size.

A producer that goes quiet without calling `poll()`, `flush()` or `close()` leaves items queued. That's documented; it's the price of having no timer thread.

### 6.2 `flush()`

This loops `pump(blocking, flushing: true)` until **the queue is empty, no requests are in flight, and no references are held back**. References become sendable as their objects finish, whether they succeeded or failed.

- **After `flush()`, `failedObjects()` and `failedReferences()` cover everything added so far, in every mode.**
- In stream mode, PHP's `flush()` also waits until every sent item has a **result**. Python's stream `flush()` returns once the local queue is empty, which only means the items were handed to the stream. That's a deliberate difference, so that the semantics are the same across modes.

### 6.3 `close()` (Python `__exit__` → `_shutdown()` + `_wait()`)

- **Client-side modes:** `flush()`, then publish the results to the wrapper.
- **Stream:** `flush()`, then write `Stop` and half-close. Read until the server ends the stream, which happens after it has drained its workers and sent its last `Results` (§9.3). This wait is bounded by `Timeout::insert + 5` s (Python's `shutdown_timeout`). If the bound is exceeded, throw `BatchStreamException("… did not terminate after forced shutdown")`. Python's `Thread.join(timeout)` doesn't raise, so Python returns silently in that case. PHP marks any unresolved items as failed and throws.

Calling it again returns the same `BatchReport`.

### 6.4 Multi-tenancy

- `ClientBatcher`: `tenant:` per object and per reference, as a `string` or a `Tenant`. Objects for different tenants and collections can share one request, because the tenant is a field on each `BatchObject`.
- `CollectionBatcher`: the handle's tenant (`$col->withTenant('acme')->batch->…`) is applied to every object and reference.
- `waitForVectorIndexing()` uses the `(collection, tenant)` pairs recorded at add time.

### 6.5 Consistency level

| Mode | Where it comes from | Wire |
|---|---|---|
| Client-side, client batch | the `consistencyLevel:` argument | `BatchObjectsRequest.consistency_level`, **left unset when null** (the server defaults to QUORUM); the REST `?consistency_level=` query parameter for references |
| Client-side, collection batch | `withConsistencyLevel()` on the handle | same |
| Stream | the argument or the handle, **defaulting to `QUORUM` when null** (Python sets this explicitly) | `BatchStreamRequest.Start.consistency_level`, sent once per stream |

## 7. Results, errors and retries

### 7.1 Result types (Python `weaviate.outputs.batch`)

| Python | PHP (`final class`, mutable only inside the engine) | Fields |
|---|---|---|
| `ErrorObject(message, object_, original_uuid=None)` | `ErrorObject` | `message: string`, `object: BatchObject`, `originalUuid: ?string` |
| `ErrorReference(message, reference)` | `ErrorReference` | `message`, `reference: BatchReference` |
| `BatchObject` (pydantic) | `BatchObject` (readonly) | `collection`, `properties`, `references`, `uuid`, `vector`, `tenant`, `index`, `retryCount` |
| `BatchReference` | `BatchReference` (readonly) | `fromObjectCollection`, `fromObjectUuid`, `fromPropertyName`, `toObjectUuid`, `toObjectCollection`, `tenant`, `index` |
| `BatchObjectReturn` | `BatchObjectReturn` | `uuids: array<int,string>` (keyed by **add order**, the `index`), `errors: array<int,ErrorObject>`, `hasErrors`, `elapsedSeconds` |
| `BatchObjectReturn.all_responses` | — | **Not ported**. It's deprecated (Dep020) |
| `BatchReferenceReturn` | `BatchReferenceReturn` | `errors: array<int,ErrorReference>`, `hasErrors`, `elapsedSeconds` |
| `BatchResult` | `BatchResult` | `objs: BatchObjectReturn`, `refs: BatchReferenceReturn` |
| `Shard(collection, tenant=None)` | `Shard` (readonly, from `Weaviate\Client\Batch`) | `collection`, `tenant` |

Memory bounds, kept from Python:
- `uuids` keeps only the most recent **100 000** entries (`MAX_STORED_RESULTS`), dropping the oldest indices first. In PHP that's about 12 MB at the limit, so `BatchOptions::$maxStoredResults` (a PHP addition, default 100 000) lets FPM jobs lower it.
- `failedObjects` is **unbounded** and keeps the full object, vector included, as in Python. That's documented.
- `BatchReferenceReturn` error keys: in client-side modes, Python renumbers them on merge (`prev_max + key + 1`); in stream mode they're keyed by add index. **PHP always keys them by add index.** That's a small deviation.

### 7.2 What is retried

| Failure | Python behaviour | PHP |
|---|---|---|
| gRPC `UNAVAILABLE` on `BatchObjects` | `_Retry.with_exponential_backoff`: sleep `2^n` s for n = 0, 1, …, until `n > MAX_RETRIES`. `MAX_RETRIES = float(env WEAVIATE_BATCH_MAX_RETRIES or 9.299)`. The last attempt raises `WeaviateRetryError`, and **every object in the request** becomes an `ErrorObject` with that message | Same count, and the env var is read for parity. **The backoff doesn't block the other slots**: the request is re-dispatched at `now + 2^n`, while other in-flight requests keep being driven. Configurable via `BatchOptions::$maxRetries`. See §12 about the worst-case time |
| Any other gRPC status (`DEADLINE_EXCEEDED`, `INTERNAL`, `RESOURCE_EXHAUSTED`, `PERMISSION_DENIED`, …) | No retry. Every object in the request fails with `repr(e)`. Python catches the error inside the worker, so **not even 403 is raised** | Same, but the message is `"[STATUS] details"`. Log one `error` for each failed request, and stop logging after 30 (as Python does) |
| **Every** object in a request fails | `WeaviateInsertManyAllFailedError` is raised, then caught, and each object's message becomes `"Every object failed during insertion. Here is the set of all errors: …"` | **Each object keeps its own server message.** That's a deviation (an improvement): the rate-limit matching below works either way |
| Per-object vectorizer rate limit | A message matching one of the patterns below is **re-queued at the front** with `retryCount + 1`, up to `retryCount > 5` (so at most 6 retries). Warning `Bat005` is logged. Then: dynamic and fixed sleep `2^highestRetry` s; rate-limit pushes the next send (§4.3) | Same patterns and limits, via the PSR-3 `warning`. The sleep becomes a **dispatch hold** for new requests, while in-flight requests continue |
| References (REST) failing at transport level | httpx retries connection errors only (`session_pool_max_retries`). Everything else fails all references in the request | Retry **only on connect failures**, because re-POSTing references can **create duplicates**. Everything else becomes one `ErrorReference` per reference |
| Stream errors | §9.4 | §9.4 |

The vectorizer rate-limit patterns are copied verbatim. They're case-sensitive substrings of the per-object error message:
- **Cohere:** `support@cohere.com` **and** (`rate limit` **or** `500 error: internal server error`).
- **OpenAI:** `OpenAI` **and** (`Rate limit reached` **or** `on tokens per min (TPM)` **or** `503 error: Service Unavailable.` **or** `500 error: The server had an error while processing your request.`).
- **HuggingFace:** `failed with status: 503 error`.

Keep them in one `VectorizerRateLimitMatcher` class so new providers can be added in one place.

Objects are released from the in-flight lookup when their request finishes, whether it succeeded or failed. Re-queued objects stay in the lookup, so their references keep waiting.

### 7.3 Error types

| Python | PHP | When |
|---|---|---|
| `WeaviateBatchValidationError` | `BatchValidationException extends InvalidInputException` | Bad input at add time; an object too large for one message |
| `WeaviateInsertInvalidPropertyError` | `BatchValidationException` | `id` or `vector` inside `properties` |
| `WeaviateInvalidInputError` | `InvalidInputException` | Bad mode parameters, a malformed vector or reference |
| `WeaviateUnsupportedFeatureError` | `UnsupportedFeatureException` | `stream` on a server older than 1.36; `stream` without bidi and with `fallbackToDynamic: false` |
| `WeaviateBatchError` (gRPC batch failure) | `BatchException extends GrpcException` | Only raised by `insertMany`. Inside a batcher it's turned into `ErrorObject`s |
| `WeaviateBatchStreamError` | `BatchStreamException extends BatchException` | A fatal stream error (§9.4) |
| `WeaviateBatchFailedToReestablishStreamError` | `BatchStreamReestablishException extends BatchStreamException` | Couldn't reopen the stream within `wait_time` after OOM, or after 5 reconnect attempts |
| `WeaviateRetryError` | (the message of an `ErrorObject`) | `UNAVAILABLE` retries were exhausted |
| `InsufficientPermissionsError` | `ForbiddenException` | Raised from stream open or `Start`. In client-side modes it becomes an `ErrorObject`, as in Python |
| `_BatchStreamShutdownError` (gRPC `ABORTED`) | internal | See §9.4 |

These go into the [01](01-architecture.md#errors) tree under `BatchException`.

### 7.4 `waitForVectorIndexing(?array $shards = null, int $howManyFailures = 5)`

- If `$shards` is null, use the imported shards of the **last** batch. Otherwise it must be a `list<Shard>`; anything else throws `InvalidInputException` (Python raises `TypeError`).
- Poll every 0.25 s. For each shard, call `GET /v1/schema/{Collection}/shards` with `?tenant={t}` when a tenant is set. A shard is ready when `status == "READY"`, or, when `per_node_status` is present, when every node's status is `READY`.
- On an HTTP or network error, retry with `2^n` s backoff up to `$howManyFailures` times, then rethrow.
- There's no overall timeout, as in Python. PHP adds an optional `?float $timeout = null` that throws `TimeoutException`.

## 8. Wire mapping (client-side modes)

### 8.1 `BatchObjectsRequest` → `/weaviate.v1.Weaviate/BatchObjects`

The timeout is `Timeout::insert` (90 s). The `BatchObject` fields:

| Proto field | Source |
|---|---|
| `uuid` (1) | the add-time UUID string |
| `collection` (4) | `ucfirst($collection)` |
| `tenant` (5) | the tenant name, or `""` |
| `properties` (3) | §8.2. Python leaves this **unset when `properties` is null, and that silently drops inline `references`** (§12). PHP sends `Properties` whenever there are properties **or** references |
| `vector_bytes` (6) | an unnamed single vector: `pack('g*', ...$v)` (float32 little-endian; Python's `struct.pack("f")` uses native order, which is LE on every supported platform) |
| `vectors` (23) | named vectors: `Vectors{name, vector_bytes, type}`. Single: `VECTOR_TYPE_SINGLE_FP32` and `pack('g*')`. Multi: `VECTOR_TYPE_MULTI_FP32` and `pack('v', dim) . pack('g*', ...flatten)` |
| `vector` (2, deprecated `repeated float`) | never sent |

`consistency_level` (2) is set only when it isn't null.

**Reply:** `BatchObjectsReply{took, errors[{index, error}]}`. `index` is the position **within that request**, and is mapped back to the object's add `index`. Anything without an error counts as a success, and its UUID goes into `uuids`.

**Request splitting (a PHP addition).** A request is closed early when the encoded size would reach `grpcMaxMessageSize` (from `/v1/meta`, or 104 858 000 by default), with 4 bytes of overhead per object (Python's stream `per_object_overhead`). Python splits by size only in stream mode. Because objects are encoded at add time, a request is built by **concatenating pre-encoded bytes** (field 1, length-delimited). Retries don't re-encode.

### 8.2 Properties → `BatchObject.Properties`

This ports `__translate_properties_from_python_to_grpc`. PHP needs extra rules because a PHP `array` is both a list and a map.

| PHP value | Proto |
|---|---|
| scalar (`string`, `int`, `float`, `bool`, `null`) | `non_ref_properties` (a `google.protobuf.Struct`) |
| `DateTimeInterface` | `non_ref_properties`, as an RFC 3339 string with microseconds (Python `_datetime_to_string`) |
| `UuidInterface` | `non_ref_properties` string |
| `GeoCoordinate`, `PhoneNumber` value objects | `non_ref_properties`, as their dictionary form |
| `[]` (empty array) | `empty_list_props[]`. The server infers the type from the schema |
| an associative array (`!array_is_list`) | `object_properties{prop_name, value: ObjectPropertiesValue}`, recursively. Nested levels allow `id` but not `vector` |
| a list of associative arrays | `object_array_properties` |
| a list of `bool` | `boolean_array_properties` |
| a list of `string` / `DateTimeInterface` / `UuidInterface` | `text_array_properties` (dates and UUIDs are stringified) |
| a list of `int` only | `int_array_properties` |
| a list with **any** `float` | `number_array_properties.values_bytes = pack('e*', ...)` (float64 LE). **This is a deviation**: Python picks the type from the **first** element, so `[1, 2.5]` becomes an int array |
| a mixed list (for example `int` and `string`) | throws `BatchValidationException` |

Inline `references`:
- A UUID or a list of UUIDs becomes `single_target_ref_props{prop_name, uuids}`.
- `ReferenceToMulti` becomes `multi_target_ref_props{prop_name, uuids, target_collection}`.

### 8.3 References → REST `POST /v1/batch/references`

The client-side modes **use REST, not gRPC `BatchReferences`**, in Python (`_BatchREST.references`). PHP does the same for parity and because it works on the whole supported range.

```
POST /v1/batch/references?consistency_level=QUORUM        (the parameter only when it's set)
[
  {"from": "weaviate://localhost/Article/<fromUuid>/author",
   "to":   "weaviate://localhost/Author/<toUuid>",          // "weaviate://localhost/<toUuid>" without a target collection
   "tenant": "acme"}                                         // only when it's set
]
```

- Expect `200`, with one entry per reference.
- An entry whose `result.status == "FAILED"` becomes an `ErrorReference(message: result.errors.error[0].message)`.
- A non-200 response, or a transport error, fails every reference in the request.
- There are **50 references per request** in every client-side mode.
- **Open question:** gRPC `BatchReferences` exists in `batch.proto`. Switching to it when the server supports it would let references use `curl_multi` too. The minimum server version for that RPC wasn't verified.

### 8.4 Other endpoints the batcher calls

| Call | When |
|---|---|
| `GET /v1/nodes` | dynamic sizing (once per second, only while pumping); stream reconnect health gate (§9.4) |
| `GET /v1/schema` / `GET /v1/schema/{name}` | vectorizer detection (§4.1) |
| `GET /v1/schema/{name}/shards[?tenant=]` | `waitForVectorIndexing` |

## 9. `BatchStream` protocol and PHP state machine

### 9.1 Messages (`batch.proto`)

| Direction | Message | Meaning |
|---|---|---|
| C→S | `Start{consistency_level}` | **Must be first.** Any other first message makes the server fail the stream ("first message must be a start message") |
| C→S | `Data{objects.values[], references.values[]}` | A batch of `BatchObject` / `BatchReference`. An empty `Data` is ignored by the server |
| C→S | `Stop{}` | "I'm done". The server marks the stream as stopping. The client then half-closes |
| S→C | `Started{}` | Sent right after `Start` is accepted |
| S→C | `Acks{uuids[], beacons[]}` | Sent after **each** `Data` message is admitted to the server's processing queue. **This is the flow-control signal.** Under memory pressure the server *delays* it (soft backpressure: quadratic in the heap ratio between the engage and gate ratios, capped at `MaxAckDelay`) |
| S→C | `Results{errors[{error, uuid\|beacon}], successes[{uuid\|beacon}]}` | Sent as workers finish, not tied to any particular `Data` message. The server retries transient replication errors itself (up to 5 times, with `2^n × 100 ms`) before reporting |
| S→C | `Backoff{batch_size}` | Sent **every 5 s**. The server's recommended items per `Data` message: an EMA aiming for 1 s of processing, clamped to 100–1000, starting at 200. **It doubles as a heartbeat** |
| S→C | `OutOfMemory{uuids[], beacons[], wait_time}` | The hard memory gate was still closed after `HoldSeconds`. The listed items were **not** accepted. `wait_time` is 300 s on the server. **The server then closes its side** |
| S→C | `ShuttingDown{}` | The node is draining. The server stops receiving (after a 75 s grace period it force-closes), finishes the queued work, sends the remaining `Results`, and closes the stream |

**Identity of items:**
- Objects are identified by `uuid`.
- References are identified by the beacon `weaviate://localhost/{FromCollection}/{fromUuid}/{propName}`, the server's `toBeacon`. **That beacon doesn't include the target**, so two references from the same object property to different targets have the **same key**. Python keeps a dictionary and the second one overwrites the first. **PHP keeps a FIFO multimap per key**, so every reference gets a result. Suggest that the server team add `to_uuid` (§12).
- Duplicate object UUIDs within one stream are handled with the same multimap.

A new stream opened against a node that's already shutting down is rejected with `UNAVAILABLE` ("not accepting new streams").

### 9.2 Lock-step driver (sync client, ext-grpc)

```
open():
    call = transport->bidiStream('/weaviate.v1.Weaviate/BatchStream', timeout: Timeout::stream)
    write(Start{cl}); readUntil(Started)                 # other messages are handled on the way
    batchSize = 100; openedAt = now

addObject(): enqueue + cache[uuid][] = obj; if queued >= batchSize: sendOne()
                                                         # note: Python blocks on in-flight >= batchSize;
                                                         # lock-step never has more than one message un-acked
sendOne():
    if isGcpOnWcd && now - openedAt > 160: renew()        # GCP LB caps streams at 180 s
    items = objects first, then references not in the in-flight lookup, up to batchSize,
            split into several Data messages if a message would reach grpcMaxMessageSize
    for each Data: write(Data); readUntil(Acks covering it)
read handlers:
    Results   → resolve cache entries → uuids / failedObjects / failedReferences;
                release the lookup on success **and** on error (Python only releases on success, §12)
    Backoff   → batchSize = n  (ignored while stopping, draining, OOM or renewing)
    Acks      → mark acked
    ShuttingDown → state = DRAINING (stop writing Data)
    OutOfMemory  → requeue the listed items at the front; state = OOM(waitTime)
flush(): while queued: sendOne(); then read until every cached item is resolved
close(): flush(); write(Stop); writesDone(); read until end (≤ Timeout::insert + 5 s)
renew(): write(Stop); writesDone(); read until end; open()
```

- **The ack wait is naturally bounded**, because a healthy stream sends `Backoff` every 5 s. AMPHP can enforce a read timeout: silence for 3 × 5 s counts as a hang-up. ext-grpc's `read()` has no per-read timeout, only the call deadline (`Timeout::stream`). That's documented.
- **Memory:** at most one `Data` message is in flight, plus the local queue (at most 2 × `batchSize`, using the same blocking rule as §6.1), plus the unresolved cache. The unresolved cache is bounded by the server's own queue, since unacked items can't pile up.

### 9.3 Stream end

| How the stream ended | State | Action |
|---|---|---|
| OK, after our `Stop` | CLOSING | Done. Anything still unresolved is marked failed ("stream closed before a result was received") |
| OK | DRAINING (after `ShuttingDown`) | Reconnect (§9.4). **Requeue every unresolved item** and open a new stream. Objects are idempotent by UUID. References may, rarely, be duplicated; that's documented, and Python's hang-up path has the same risk |
| OK | OOM | Wait with backoff (1, 2, 4 … up to 30 s) and reopen, until `wait_time` has passed since the OOM. When the server accepts again, resend the requeued items. If it doesn't, throw `BatchStreamReestablishException` and mark everything unresolved as failed |
| OK | RENEWING (GCP) | Open again and continue |
| OK | none of the above | The server closed without warning. Treat it like a hang-up |

**Python's OOM path, as read, doesn't recover.** After `OutOfMemory` the server closes. `__recv` sees neither shutdown nor renew and sets `shutdown_loop`. The loop thread keeps pausing on `is_oom` for up to `wait_time`, then raises. `_wait()` returns after `insert + 5` s. The requeued objects are **neither sent nor reported as failed** (§12). PHP follows the proto comment instead ("how long the client should wait … before reopening the stream").

### 9.4 Stream errors and reconnect

| gRPC error | Python | PHP |
|---|---|---|
| `UNAVAILABLE`, or a message containing `Socket closed` / `context canceled` / `Connection reset` / `Received RST_STREAM with error code 2` | "Hang-up": reconnect, re-prepend **all** cached objects and references, clear the in-flight sets, restart | Same |
| `ABORTED` | `_BatchStreamShutdownError`, which **isn't handled** by `sync.py`, so it becomes a fatal background exception | Treat it as a hang-up. **This is a deviation**, pending confirmation with the server team about what `ABORTED` means here |
| `PERMISSION_DENIED` / `UNAUTHENTICATED` | `InsufficientPermissionsError` | Throw `ForbiddenException` / `AuthenticationException` and mark unresolved items failed |
| anything else | `WeaviateBatchStreamError("{code}({details})")`, fatal | Throw `BatchStreamException` and mark unresolved items failed |

**Reconnect.** This ports `__reconnect`:
1. If the consistency level is `ALL`, **or** the cluster has a single node, poll `/v1/nodes` every 5 s until the node count matches the count recorded at start and every node is `HEALTHY`. Python waits **without a limit** here; PHP caps it at `wait_time` (300 s).
2. Call `$connection->close()` and then `connect(force: true)`. If that throws `WeaviateStartUpException` or `WeaviateGrpcUnavailableException`, retry up to 5 times, sleeping `2^n` s. Then throw `BatchStreamReestablishException`.
3. Open a new stream with `Start` again.

**GCP on Weaviate Cloud:** when the HTTP host contains `gcp` **and** is a Weaviate domain (Python `is_gcp_on_wcd`), renew the stream every **160 s**, because GCP load balancers cap it at 180 s.

## 10. Async package (`weaviate/weaviate-php-async`)

Python's async client offers **only `stream()`** on `client.batch` and `collection.batch`. It has no dynamic, fixed-size or rate-limit modes.

| | Python async | PHP async |
|---|---|---|
| `stream()` | ✅ | ✅. Two fibers mirror Python's tasks: a writer that fills a queue of capacity 1 and a reader that drives `AmpGrpcTransport` bidi. `addObject()` suspends while in-flight objects ≥ `batchSize`, in-flight references ≥ `2 × batchSize`, or the stream is renewing, shutting down or out of memory. Reads have real timeouts (§9.2) |
| `dynamic` / `fixedSize` / `rateLimit` | ✗ | ✅ **PHP addition.** The same engine as the sync client, with `startUnary()` returning `Amp\Future`. `concurrentRequests` calls run multiplexed over one HTTP/2 connection. A `Revolt\EventLoop::repeat(1.0)` timer drives the `/v1/nodes` sizing and the 1 s partial flush, so **a quiet producer doesn't stall**, which fixes the sync limitation in §6.1 |
| Closure API | `async with client.batch.stream() as b:` | `$client->batch->stream(function (ClientBatcher $b) { … })`. It runs inside the caller's fiber, and `addObject()` suspends instead of blocking |

The sync package on curl can't stream. The async package streams without ext-grpc ([ADR 0002](decisions/0002-pluggable-grpc-transport.md) consequence).

## 11. Examples

```php
use Weaviate\Client\Batch\{BatchMode, ClientBatcher, CollectionBatcher, Shard};
use Weaviate\Client\ConsistencyLevel;

// 1. Dynamic, client level, mixed collections and tenants, with explicit UUIDs and vectors
$report = $client->batch->dynamic(function (ClientBatcher $b) use ($rows) {
    foreach ($rows as $r) {
        $b->addObject(
            collection: 'Article',
            properties: ['title' => $r['title'], 'tags' => $r['tags'], 'published' => new DateTimeImmutable($r['date'])],
            uuid: Weaviate\Client\Util\generateUuid5($r['id']),
            vector: ['title_vec' => $r['emb']],        // named vector
            tenant: $r['tenant'],
        );
    }
}, consistencyLevel: ConsistencyLevel::One);

foreach ($report->failedObjects as $err) {
    error_log("{$err->object->uuid}: {$err->message}");
}

// 2. Fixed size with 4 concurrent requests (curl_multi on the default transport)
$articles = $client->collections->use('Article');
$articles->batch->fixedSize(function (CollectionBatcher $b) use ($csv) {
    foreach ($csv as $row) {
        $b->addObject(properties: $row, vector: array_map('floatval', $row['embedding']));
    }
}, batchSize: 500, concurrentRequests: 4);

// 3. Objects, then references between them, in one batch (references wait for their objects)
$client->batch->fixedSize(function (ClientBatcher $b) use ($authors, $books) {
    foreach ($authors as $a) { $b->addObject(collection: 'Author', properties: $a, uuid: $a['id']); }
    foreach ($books as $bk) {
        $id = $b->addObject(collection: 'Book', properties: ['title' => $bk['title']]);
        $b->addReference(fromUuid: $id, fromCollection: 'Book', fromProperty: 'writtenBy', to: $bk['authorIds']);
    }
});
if ($client->batch->failedReferences() !== []) { /* … */ }

// 4. A rate-limited vectorizer (for example 3 000 objects a minute on an OpenAI tier)
$docs = $client->collections->use('Doc')->withTenant('acme');
$docs->batch->rateLimit(fn (CollectionBatcher $b) => array_map(fn ($t) => $b->addObject(properties: ['text' => $t]), $texts),
    requestsPerMinute: 3000);

// 5. Server-side streaming: needs ext-grpc (sync) or the async package; otherwise falls back to dynamic
$report = $articles->batch->stream(function (CollectionBatcher $b) use ($gen) {
    foreach ($gen as $row) { $b->addObject(properties: $row); }
});
echo $report->mode->name; // "Stream", or "Dynamic" after a fallback

// 6. Explicit lifecycle in a long-running queue worker
$b = $client->batch->start(BatchMode::dynamic());
try {
    while ($msg = $consumer->receive(timeoutMs: 500)) {
        $b->addObject(collection: 'Event', properties: $msg->body, uuid: $msg->id);
        $b->poll();                                        // lets aged partial batches go out
        if ($consumer->shouldCommit()) { $b->flush(); $consumer->commit(); }
    }
} finally {
    $b->close();
}

// 7. Wait for async indexing to finish
$client->batch->waitForVectorIndexing();                               // the shards of the last batch
$client->batch->waitForVectorIndexing(shards: [new Shard('Doc', 'acme')]);
```

## 12. Python behaviours we don't copy, and things we could not verify

**Deliberate deviations.** These go into the test suite and into `UPGRADING.md` as "differences from Python":
1. Property and vector validation happens at add time and throws. Python fails the whole request at send time.
2. Inline `references` are sent even when `properties` is null. Python drops them: `properties=… if obj.properties is not None else None` in `grpc_object`.
3. Each object keeps its own error message when every object in a request fails. Python wraps them in `WeaviateInsertManyAllFailedError`.
4. Number arrays use float64 when **any** element is a float. Python decides from the first element.
5. Dynamic sizing is clamped (1–1000 objects, 1–10 concurrent), and `rate == 0` is guarded.
6. If `/v1/nodes` isn't accessible, dynamic mode falls back to fixed 100/2 with a warning. Python stays at 10/2 silently. **This needs sign-off.**
7. Stream: the lookup is released on error too. In Python, a failed object's UUID stays in `__uuid_lookup`, so references to it are **never sent**: the loop keeps skipping them until the `_wait()` timeout, and then they're lost silently.
8. Stream: references are tracked in a multimap per beacon, because of the beacon collision described in §9.1.
9. Stream: the OOM path reopens the stream after a wait (§9.3), where Python effectively ends the batch. `ABORTED` is treated as a hang-up.
10. Stream `flush()` waits for results (§6.2). A `close()` that times out throws instead of returning silently.
11. REST references are retried only on connect errors.

**Could not verify:**
- **The worst-case `UNAVAILABLE` retry time.** The Python comment says "approximately 10m30s". By my reading of `_Retry`, the sleeps run for n = 0 to 10 (1 + 2 + … + 1024 s ≈ 34 min) before `n > 9.299` raises. The per-attempt `insert` timeout isn't included in that. Confirm with the Python maintainers before copying the default.
- **Whether `/v1/nodes` without `output=verbose` always includes `batchStats`.** Python assumes it does for the minimal output, and switches to fixed 1000/10 when `queueLength` is missing. Verify against 1.29 (the floor) and the latest server.
- **Whether ext-grpc lets several started `UnaryCall`s run in parallel** before `wait()` (§5.1). Also whether its `BidiStreamingCall::read()` behaves well in lock-step, with no read timeout. This is a P3 spike item.
- **Whether `ABORTED` on `BatchStream` means "retry elsewhere"** or is a real error. Ask the server team.
- **The minimum server version for gRPC `BatchReferences`** (§8.3).
- **Whether the server de-duplicates re-sent references.** If it doesn't, the reconnect requeue can add duplicate references.
- **Switching to fixed 1000/10 under async indexing even with a vectorizer.** Python does it, and it may break vectorizer quotas. We copy it for now; revisit.

## 13. Performance expectations

These are **targets and hypotheses, not measurements.** P3 replaces them with benchmark numbers ([04](04-roadmap.md) P3).

- **Client-side encoding is likely the bottleneck in PHP**, not the network. Per object, the cost is building the `Struct` for scalar properties, typed arrays, and `pack('g*')` for vectors. `pack` runs in C and is fast; the pure-PHP `google/protobuf` runtime is slow for `Struct`. **Recommend `ext-protobuf`** (the C runtime) for large imports, and measure both.
- **Encoding once at add time** means retries and request splitting cost nothing extra.
- **`concurrentRequests` hides the round trip.** With N in flight, throughput ≈ `min(encodeRate, N × batchSize / roundTrip, serverIngestRate)`. On curl, the in-flight requests progress only while the caller is inside a pump, but encoding is itself a series of pump calls, so overlap stays high as long as the producer keeps adding.
- **Stream (lock-step on ext-grpc)** should match Python's stream throughput for the same server, because both allow about one un-acked message. The server's `Backoff` sets the message size (100–1000).
- **Targets for the P3 benchmark** (100k objects, 1536-d self-provided vectors, a local single node):
  - `fixedSize(500, 4)` on curl with ext-protobuf reaches at least **70 %** of Python `fixed_size(500, 4)` throughput on the same machine;
  - ext-grpc is within **20 %** of curl;
  - async is at least on a par with curl.

  If curl comes out far behind, the README recommends ext-grpc or async for bulk loads ([ADR 0002](decisions/0002-pluggable-grpc-transport.md)).
- **Peak memory** is bounded by: queue (2 × batchSize) + in flight (N × batchSize) + result UUIDs (≤ `maxStoredResults`) + failed objects. With the defaults and 1536-d vectors, that should stay under **64 MB** apart from failures. Assert it in the benchmark with `memory_get_peak_usage()`.
- **PHP-FPM:** `max_execution_time` applies. Imports over a few thousand objects belong in CLI workers. That's documented.

## 14. Test checklist (becomes unit and integration tests in P3)

**Unit tests (fake transport with a scripted clock, no server):**
- Mode validation: `batchSize` 0 or 10 001, `concurrentRequests` 0 or 33, and `requestsPerMinute` 0 all throw before any I/O. `stream` on 1.35 throws `UnsupportedFeatureException`.
- The rate-limit formulas for rpm 1, 600, 1000, 3000 and 10 000; the 62 s gap; `baseTime` going up after a rate-limit error; `close()` honouring the gap.
- Dynamic sizing: every branch of §4.1, with and without a vectorizer, with a table of `(queueLength, rate, size, concurrency)` values and the expected result; the clamps; `rate = 0`; a missing `batchStats` switching to 1000/10; a 403 on `/v1/nodes` switching to fixed 100/2 with exactly one warning.
- Backpressure: `addObject` blocks at `2 × size` and when the size is 0, and returns once a slot frees. The blocking waits for I/O rather than spinning (assert the calls to `drive(maxWait > 0)`).
- References are never sent before their from/to objects finish, and are sent after those objects **fail** too.
- Retries: `UNAVAILABLE` n times, then success; exhausted retries fail every object with the retry message; `DEADLINE_EXCEEDED` isn't retried; the retry hold doesn't block other slots.
- Vectorizer rate limit: each of the 7 patterns is re-queued; a retry count above 5 gives up; non-matching errors fail immediately.
- Results: `uuids` keyed by add index; the 100 000 cap with oldest-first eviction; `numberErrors`; the report and wrapper accessors after close; the reset on the next batch.
- Validation: `id` or `vector` in properties; a bad UUID; a beacon or href as the UUID; an unnamed multi-vector; mixed-type lists; an oversized object.
- Property encoding: golden protobuf bytes for every row of §8.2 and for vectors (single, named single, named multi with the `uint16` dim prefix), checked against the bytes Python produces for the same input (fixtures generated from Python).
- The closure form: an exception in the body still flushes, the body's exception wins over a `close()` exception, and `abort()` drops queued items.
- Stream state machine: lock-step ack waiting; `Backoff` resizing, including when it's ignored; `Results` for both uuid and beacon; the beacon multimap with two references from one property; `OutOfMemory` requeue, reopen and give-up after `wait_time`; `ShuttingDown` → drain → reconnect → requeue; hang-up strings and `ABORTED`; GCP renewal at 160 s; the `close()` timeout.

**Integration tests (docker-compose, real Weaviate, 1.29 floor and latest):**
- Each mode imports 10k objects with no errors, and the count is checked with `aggregate->overAll(totalCount: true)`.
- Multi-tenant: objects for 3 tenants from one client batcher; a collection batcher on a `withTenant` handle; a missing tenant produces per-object errors.
- A consistency level on a 3-node cluster (`ALL` succeeds with every node up and fails per object with one down).
- References: single-target and multi-target (`ReferenceToMulti`), inline and through `addReference`; a reference to a non-existent property fails per reference.
- Vectorizer collection: detected, so it starts at 48; a mocked rate-limit module (a text2vec proxy that returns the OpenAI 429 text) triggers re-queue and a warning.
- `waitForVectorIndexing` with async indexing enabled.
- Stream (ext-grpc image and async package): 100k objects; server restart mid-stream (graceful shutdown → reconnect, and no object lost when compared against the ids we sent); `kill -9` of the node (hang-up path); a low memory limit to provoke `OutOfMemory`.
- Fallback: `stream()` on curl logs a notice once and imports through dynamic; with `fallbackToDynamic: false` it throws.
- Transports: `fixedSize(…, concurrentRequests: 4)` on curl really overlaps requests (use toxiproxy with 200 ms latency: wall time is about ¼ of the sequential run); grpc-web degrades to concurrency 1 with a notice.
- The memory ceiling from §13 holds for a 100k import.
