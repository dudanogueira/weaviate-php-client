# 13: Aggregate specification

This maps **every** aggregate method, parameter, metric flag and result field in Python v4 to PHP. It was written from the Python client's `main` branch on 2026-09-25, reading:

- `weaviate/collections/aggregations/*` (`base_executor.py`, and `executor.py` plus `sync.pyi` for `over_all`, `near_text`, `near_vector`, `near_object`, `near_image`, `hybrid`)
- `weaviate/collections/classes/aggregate.py` (the `Metrics` builders, `GroupByAggregate` and the result dataclasses)
- `weaviate/collections/grpc/aggregate.py` and `grpc/shared.py` (building the request)
- `weaviate/connect/v4.py` (`grpc_aggregate`), `weaviate/retry.py`, and `collection/sync.py` / `async_.py` (`__len__` / `length()`)
- the tests `integration/test_collection_aggregate.py` and `test/collection/test_aggregates.py`

The server side was read from `weaviate/weaviate` `main`: `grpc/proto/v1/aggregate.proto`, `adapters/handlers/grpc/v1/parse_aggregate_request.go` and `prepare_aggregate_reply.go`. Rows in [02 §7](02-feature-parity-matrix.md#7-aggregate-collectionaggregate-grpc-aggregate) link here. Types shared with search (`Filter`, `Move`, `HybridVector`, `BM25Operator`, the blob input, the vector packing) are specified in [12](12-query-and-generate.md). This document only covers how aggregate uses them.

## 1. The model

Aggregation is a single unary gRPC call, `weaviate.v1.Weaviate/Aggregate(AggregateRequest) → AggregateReply`. Each request has four independent parts:

| Part | What it does | Request fields |
|---|---|---|
| **Object set** | Which objects are aggregated: everything, the objects matching a filter, and/or the results of one vector or hybrid search | `filters`, the `search` oneof, `object_limit` |
| **Metrics** | What is computed for each property | `aggregations[]` |
| **Count** | Whether the total number of matching objects is returned | `objects_count` |
| **Grouping** | Optionally split the set by the values of one property, and cap the number of groups | `group_by`, `limit` |

The six public methods only differ in the search part. `overAll` has no search. `nearText`, `nearVector`, `nearObject`, `nearImage` and `hybrid` each set one branch of the `search` oneof. Every method takes the same trailing arguments: `filters`, `groupBy`, `totalCount` and `returnMetrics`.

```php
namespace Weaviate\Client\Collections\Aggregate;

final class AggregateCollection   // $collection->aggregate
{
    public function overAll(...): AggregateReturn|AggregateGroupByReturn;
    public function nearText(...): AggregateReturn|AggregateGroupByReturn;
    public function nearVector(...): AggregateReturn|AggregateGroupByReturn;
    public function nearObject(...): AggregateReturn|AggregateGroupByReturn;
    public function nearImage(...): AggregateReturn|AggregateGroupByReturn;
    public function hybrid(...): AggregateReturn|AggregateGroupByReturn;
}
```

**Return type.** Python uses `@overload` so that `group_by=None` returns `AggregateReturn` and any `group_by` returns `AggregateGroupByReturn`. PHP can't overload methods, so each method declares the union type and gives PHPStan a conditional return type:

```php
/** @return ($groupBy is null ? AggregateReturn : AggregateGroupByReturn) */
```

With this, `$res->totalCount` after a call without `groupBy`, or `$res->groups` after a call with one, type-checks without a cast.

`AggregateCollection` is built by `Collection` and carries the collection name and tenant from the handle ([01](01-architecture.md#cross-cutting-concerns)). The Python class name `_AggregateCollection` is private, so the PHP class name is our choice.

## 2. Methods

In the tables below, **all** parameters after the first one are named-only in Python (`*`). PHP can't enforce that, so the parameter order below is fixed as part of the public API, and docs always use named arguments. When one type is written for several methods, see §2.7.

### 2.1 `overAll()` (Python `over_all`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `filters` | `filters` | `?Filter` | `null` | [12](12-query-and-generate.md) filter DSL |
| `group_by` | `groupBy` | `string\|GroupByAggregate\|null` | `null` | A string means `new GroupByAggregate(prop: $s)` (§4) |
| `total_count` | `totalCount` | `bool` | `true` | Sets `objects_count` |
| `return_metrics` | `returnMetrics` | `Metric\|list<Metric>\|null` | `null` | A single metric is wrapped in a list, like Python (§3) |

There is no search and no `objectLimit`. The metrics are computed over the whole collection, or over the objects matching `filters`.

### 2.2 `nearText()` (Python `near_text`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `query` | `query` | `string\|list<string>` | required, positional | A string is wrapped in a list |
| `certainty` | `certainty` | `int\|float\|null` | `null` | Sent as a double |
| `distance` | `distance` | `int\|float\|null` | `null` | Sent as a double |
| `move_to` | `moveTo` | `?Move` | `null` | [12](12-query-and-generate.md) `Move(force, concepts, objects)` |
| `move_away` | `moveAway` | `?Move` | `null` | |
| `object_limit` | `objectLimit` | `?int` | `null` | §5 |
| `filters` | `filters` | `?Filter` | `null` | |
| `group_by` | `groupBy` | `string\|GroupByAggregate\|null` | `null` | |
| `target_vector` | `targetVector` | `?string` | `null` | §5.3 |
| `total_count` | `totalCount` | `bool` | `true` | |
| `return_metrics` | `returnMetrics` | `Metric\|list<Metric>\|null` | `null` | |

At least one of `certainty`, `distance` and `objectLimit` is required (§6). The collection needs a text-capable vectorizer.

### 2.3 `nearVector()` (Python `near_vector`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `near_vector` | `nearVector` | `list<float>\|list<list<float>>\|array<string, list<float>\|list<list<float>>>` | required, positional | A single vector, a multi-vector (ColBERT style), or a one-entry map `targetName => vector` |
| `certainty` | `certainty` | `int\|float\|null` | `null` | |
| `distance` | `distance` | `int\|float\|null` | `null` | |
| `object_limit` | `objectLimit` | `?int` | `null` | |
| `filters` | `filters` | `?Filter` | `null` | |
| `group_by` | `groupBy` | `string\|GroupByAggregate\|null` | `null` | |
| `target_vector` | `targetVector` | `?string` | `null` | Python types this `TargetVectorJoinType` (a string, a list, or `TargetVectors.*`). PHP narrows it to one name; see §5.3 |
| `total_count` | `totalCount` | `bool` | `true` | |
| `return_metrics` | `returnMetrics` | `Metric\|list<Metric>\|null` | `null` | |

Python also accepts numpy, pandas, polars and TensorFlow inputs. PHP accepts plain arrays, plus any `\Traversable` of floats, which is materialized with `iterator_to_array()`.

### 2.4 `nearObject()` (Python `near_object`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `near_object` | `nearObject` | `string\|UuidInterface` | required, positional | Sent as a string |
| `certainty` | `certainty` | `int\|float\|null` | `null` | |
| `distance` | `distance` | `int\|float\|null` | `null` | |
| `object_limit` | `objectLimit` | `?int` | `null` | |
| `filters` | `filters` | `?Filter` | `null` | |
| `group_by` | `groupBy` | `string\|GroupByAggregate\|null` | `null` | |
| `target_vector` | `targetVector` | `?string` | `null` | |
| `total_count` | `totalCount` | `bool` | `true` | |
| `return_metrics` | `returnMetrics` | `Metric\|list<Metric>\|null` | `null` | |

### 2.5 `nearImage()` (Python `near_image`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `near_image` | `nearImage` | `string\|\SplFileInfo\|resource` | required, positional | A file path, a base64 string, a file object or an open stream. Converted to base64 by the shared blob parser ([12](12-query-and-generate.md), Python `parse_blob`) |
| `certainty` | `certainty` | `int\|float\|null` | `null` | |
| `distance` | `distance` | `int\|float\|null` | `null` | |
| `object_limit` | `objectLimit` | `?int` | `null` | |
| `filters` | `filters` | `?Filter` | `null` | |
| `group_by` | `groupBy` | `string\|GroupByAggregate\|null` | `null` | |
| `target_vector` | `targetVector` | `?string` | `null` | |
| `total_count` | `totalCount` | `bool` | `true` | |
| `return_metrics` | `returnMetrics` | `Metric\|list<Metric>\|null` | `null` | |

The collection needs an image-capable vectorizer, such as `multi2vec-clip` or `img2vec-neural`.

### 2.6 `hybrid()` (Python `hybrid`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `query` | `query` | `?string` | required, positional, **nullable** | The keyword part. When `null`, alpha is forced to 1, so the search is pure vector |
| `alpha` | `alpha` | `int\|float\|null` | `null` | Weighting between BM25 (0) and vector (1). See §7.4 for how it's sent |
| `vector` | `vector` | `list<float>\|list<list<float>>\|HybridVector\|null` | `null` | Python annotates `Optional[List[float]]`, but its gRPC path accepts the full `HybridVectorType`. PHP accepts the same shapes as `query->hybrid()` ([12](12-query-and-generate.md)) |
| `query_properties` | `queryProperties` | `?list<string>` | `null` | BM25 properties. `null` means all of them |
| `object_limit` | `objectLimit` | `?int` | `null` | Caps the hybrid results before aggregation |
| `bm25_operator` | `bm25Operator` | `?BM25Operator` | `null` | `BM25Operator::or(minimumMatch:)`, `::and()`, `::andCross()`. Version-gated (§8) |
| `filters` | `filters` | `?Filter` | `null` | |
| `group_by` | `groupBy` | `string\|GroupByAggregate\|null` | `null` | |
| `target_vector` | `targetVector` | `?string` | `null` | |
| `max_vector_distance` | `maxVectorDistance` | `int\|float\|null` | `null` | Sent as `Hybrid.vector_distance` |
| `total_count` | `totalCount` | `bool` | `true` | |
| `return_metrics` | `returnMetrics` | `Metric\|list<Metric>\|null` | `null` | |

Unlike the `near*` methods, `hybrid` **doesn't** require `certainty`, `distance` or `objectLimit`, which matches Python. Aggregate `hybrid` has no `fusionType` parameter: Python always sends `FUSION_TYPE_UNSPECIFIED`, and so does PHP.

If both `query` and `vector` are `null`, Python sends **no** search, and the call behaves like `overAll` (with `objectLimit` still sent). PHP does the same.

### 2.7 Shared parameter types

- **`filters`** is converted by the shared `Transport\Mapper\FilterMapper` into `weaviate.v1.Filters`, the same mapper that search uses. That includes `Filter::byRef(...)`. On the GraphQL path, Python rejects reference filters for aggregate; gRPC supports them.
- **`certainty` / `distance`** accept `int|float`, and are cast to `float` before being set.
- **`groupBy`** accepts a `string` or a `GroupByAggregate`. Anything else is a `TypeError` in PHP. Python raises `WeaviateInvalidInputError`.
- **`returnMetrics`** accepts a single `Metric` or a list of them. `Metric` is the common interface of the six metric classes in §3.

There are no `null`-means-something-else parameters. `totalCount: false` is the only way to leave out the count.

## 3. Metrics builders

```php
namespace Weaviate\Client\Query;

final class Metrics
{
    public function __construct(string $property) {}
    public static function of(string $property): self;   // sugar for new Metrics(...)

    public function text(bool $count = false, bool $topOccurrencesCount = false, bool $topOccurrencesValue = false, ?int $limit = null): MetricsText;
    public function integer(bool $count = false, bool $maximum = false, bool $mean = false, bool $median = false, bool $minimum = false, bool $mode = false, bool $sum = false): MetricsInteger;
    public function number(bool $count = false, bool $maximum = false, bool $mean = false, bool $median = false, bool $minimum = false, bool $mode = false, bool $sum = false): MetricsNumber;
    public function boolean(bool $count = false, bool $percentageFalse = false, bool $percentageTrue = false, bool $totalFalse = false, bool $totalTrue = false): MetricsBoolean;
    public function date(bool $count = false, bool $maximum = false, bool $median = false, bool $minimum = false, bool $mode = false): MetricsDate;
    public function reference(bool $pointingTo = false): MetricsReference;
}
```

**The all-or-nothing rule** (copied from Python): if **every** flag of a builder is `false`, which is also the case when it's called with no arguments, then **all** flags of that builder are switched on. `Metrics::of('year')->integer()` asks for all seven integer metrics. `->integer(mean: true)` asks for the mean only.

Each builder returns a `final readonly` value object (`MetricsText`, `MetricsInteger` and so on) that implements `Metric` and exposes `string $propertyName` plus its resolved flags. These objects are public, so they can be built once and reused, but their constructors are `@internal`. Build them through `Metrics`.

### 3.1 Per data type

The builder has to match the property's data type. The builder for a scalar type also covers its array type.

| Builder (Python → PHP) | For data types | Flags (Python → PHP) | Wire message `AggregateRequest.Aggregation.*` |
|---|---|---|---|
| `text()` → `text()` | `text`, `text[]` (also `uuid`, `uuid[]`, † not verified) | `count` → `count`; `top_occurrences_count` → `topOccurrencesCount`; `top_occurrences_value` → `topOccurrencesValue`; `limit` → `limit` | `Text{count, top_occurences, top_occurences_limit}` |
| `integer()` → `integer()` | `int`, `int[]` | `count`, `maximum`, `mean`, `median`, `minimum`, `mode`, `sum_` → `sum` | `Integer{count, sum, mean, mode, median, maximum, minimum}` |
| `number()` → `number()` | `number`, `number[]` | same as integer | `Number{…same…}` |
| `boolean()` → `boolean()` | `boolean`, `boolean[]` | `count`, `percentage_false` → `percentageFalse`, `percentage_true` → `percentageTrue`, `total_false` → `totalFalse`, `total_true` → `totalTrue` | `Boolean{count, total_true, total_false, percentage_true, percentage_false}` |
| `date_()` → `date()` | `date`, `date[]` | `count`, `maximum`, `median`, `minimum`, `mode` (no mean, no sum) | `Date{count, median, mode, maximum, minimum}` |
| `reference()` → `reference()` | cross-reference properties | `pointing_to` → `pointingTo` | `Reference{pointing_to}` |

Naming:
- Python's `date_` and `sum_` have trailing underscores only to avoid shadowing Python built-ins. PHP uses `date()` and `sum`, following the camelCase rule in [03](03-api-design.md#conventions).
- The proto misspells `top_occurences`. That spelling stays inside `Transport\Mapper`; the public API always spells it `occurrences`.

### 3.2 Text specifics

- `limit` is the maximum number of top occurrences returned. It maps to `top_occurences_limit` (`optional uint32`). When it's `null`, the field is left unset and the server uses its own default. † The default size isn't verified; GraphQL's documented default is 5.
- **Python's deprecated `min_occurrences` argument is not ported.** It's an alias for `limit` that emits `Dep028`, and passing it together with `limit` raises. A new client has no callers to keep working.
- **Wire quirk, and a deliberate deviation.** The proto has one flag, `top_occurences`, for "compute the top occurrences". Python sends only `top_occurences = top_occurrences_count` and **drops** `top_occurrences_value`. So in Python, `text(topOccurrencesValue: true)` alone sends `top_occurences = false`. PHP sends `top_occurences = topOccurrencesCount || topOccurrencesValue`, which is what the caller meant.
  - Either way, both `value` and `occurs` come back for each item, because the wire always carries both. PHP doesn't blank out the one that wasn't asked for, which matches Python.
  - † Python's integration test `test_over_all_with_filters_ref` asks for `count=True, top_occurrences_value=True`, which puts `top_occurences = false` on the wire, and still expects `top_occurrences[0].value`. That suggests the server returns top occurrences for text regardless of the flag. Settle this with the integration test in §11.
- † In `parse_aggregate_request.go`, `top_occurences = true` **without** a limit adds `TotalTrueAggregator` instead of a top-occurrences aggregator. It looks like a server bug, which the server returning top occurrences anyway would hide. Report it upstream. The client behaviour doesn't change.

### 3.3 Things the wire has that Python doesn't expose

Each request metric message has a `bool type` field, and each reply has an `optional string type` that gives the schema type. Python never sets or reads them, and PHP doesn't expose them either (parity). They can be added later without breaking anything.

## 4. Group-by

```php
final readonly class GroupByAggregate
{
    public function __construct(
        public string $prop,        // property name to group by, must not be empty
        public ?int $limit = null,  // max number of groups, >= 0
    ) {}
}
```

| Python | PHP | Wire |
|---|---|---|
| `GroupByAggregate(prop, limit=None)` | `new GroupByAggregate(prop:, limit:)` | `group_by = GroupBy{collection: "", property: prop}` and **top-level** `limit = limit` |
| `group_by="prop"` | `groupBy: 'prop'` | same as `new GroupByAggregate(prop: 'prop')` |

Semantics:
- `GroupBy.collection` is always sent as `""`. The server resolves the property on the request's collection. Python never sets it and PHP doesn't expose it.
- `limit` caps the **number of groups**, not the number of objects per group. It goes into the top-level `AggregateRequest.limit`, which Python only sets together with group-by. PHP doesn't expose top-level `limit` any other way.
- The object set (filters, search, `objectLimit`) is resolved **first**. The matching objects are then split by the distinct values of `prop`, and the metrics and count are computed **per group**. With a near search and `objectLimit: 10`, the groups only partition those 10 objects.
- An array property puts each object into the groups of **each** of its values († not verified; this is how GraphQL group-by has behaved).
- A cross-reference property groups by beacon: `GroupedBy::$value` is the beacon string `weaviate://localhost/<Class>/<uuid>`. On namespaced clusters, the server strips the caller's own namespace from the beacon.
- Group order is whatever the server returns. PHP keeps it. † The ordering, probably by count descending, isn't verified; tests mustn't depend on it.
- When no object matches, the reply has no `result`, and PHP returns `new AggregateGroupByReturn(groups: [])`, the same as Python (`test_aggregation_groupby_no_results`).

## 5. How filters, search, `objectLimit` and `targetVector` interact

### 5.1 The object set

| Given | Aggregated objects |
|---|---|
| nothing (`overAll()`) | every object in the collection, or in the tenant |
| `filters` only | the objects that match the filter |
| a search only | the search results, limited by `certainty`/`distance`/`maxVectorDistance` and/or `objectLimit` |
| a search **and** `filters` | the search runs **pre-filtered**: only filtered objects are candidates, and the thresholds and limit apply to them. This is the same semantics as `query->near*` with `filters` |

### 5.2 `objectLimit`

- It maps to `object_limit` (`optional uint32`) and is sent only when it isn't `null`.
- It is the maximum number of **search results** passed on to aggregation. It isn't the number of groups (that's `GroupByAggregate::$limit`), and it isn't the top-occurrences size (that's `text(limit:)`).
- For `nearText`, and for the `nearText` inside a hybrid `HybridVector`, the server also passes it on as the text search's limit.
- `objectLimit` combines with `certainty` or `distance`: the smaller resulting set wins.
- It's **only exposed on search methods**. `overAll` has no `objectLimit`, which matches Python.
- † It isn't verified what hybrid does with no `objectLimit` and no `maxVectorDistance`, whether it uses the server's default query limit or all objects. Python's tests always pass `object_limit` to hybrid. The docs recommend always setting it.

### 5.3 `targetVector`

- It's sent as `Targets{target_vectors: [name]}` on the search message, using the non-deprecated `targets` field. The shared `TargetVectorMapper` ([12](12-query-and-generate.md)) does this.
- **Aggregate supports exactly one target vector.** The server rejects more than one ("found more than one target vector for aggregation") and ignores combination methods ("targetCombination is not supported with Aggregate queries"). Python still accepts `List[str]` and `TargetVectors.*` for `near_vector` and lets the server fail. PHP narrows the parameter to `?string` and documents why, so the mistake shows up in the IDE instead of at runtime.
- `nearVector` with a keyed map (`['body_vec' => $vec]`):
  - The map must have **exactly one** entry, or the call throws `InvalidInputException`.
  - The key is the target, and `targetVector` may be left out.
  - If `targetVector` is also given, it must equal the key, or the call throws `InvalidInputException` ("near_vector keys [...] must match the target vectors [...]", Python's wording).
- If `targetVector` is left out:
  - With a single named vector, the server uses it.
  - With **several** named vectors, the server fails ("class X has multiple vectors, but no target vectors were provided"), and that surfaces as a `QueryException`.
  - A collection that only has a legacy (unnamed) vector needs no target.
- An unknown name is a server error ("does not have named vector … configured").
- `targetVector` has no effect on `overAll` (there's no parameter) or on the BM25 half of hybrid.

## 6. Validation (before any I/O)

| Rule | Methods | Python | PHP |
|---|---|---|---|
| At least one of `certainty`, `distance`, `objectLimit` | `nearText`, `nearVector`, `nearObject`, `nearImage` | `WeaviateInvalidInputError("You must provide at least one of the following arguments: certainty, distance, object_limit when vector searching")`. It's checked only when `validate_arguments` is on, which is the default | Always checked, `InvalidInputException` with the same message |
| `certainty` and `distance` together | `near*` | not checked client-side; the server errors with "cannot provide distance and certainty" | `InvalidInputException`, per [03](03-api-design.md#conventions) |
| `objectLimit >= 1` | search methods | type check only; a negative value fails in protobuf | `InvalidInputException` when it's `< 1` († check whether the server treats `0` as "no limit"; if it does, relax the rule to `>= 0`) |
| `GroupByAggregate::$limit >= 0`, `$prop` not empty | all | type check only | `InvalidInputException` in the constructor |
| `text(limit:) >= 0` | — | none | `InvalidInputException` in the builder |
| The property name in `Metrics` isn't empty | — | none | `InvalidInputException` |
| **Duplicate property names** in `returnMetrics` | all | not checked; the result is a dict keyed by property, so one silently wins | `InvalidInputException`, because the result is keyed by property name and can't hold two entries. To get more metrics, turn on more flags in one builder |
| `nearText` `query` not empty (`''` or `[]`) | `nearText` | none | `InvalidInputException` |
| `nearVector` is empty, ragged or not numeric | `nearVector` | shared vector validation | the shared vector validation in [12](12-query-and-generate.md) |
| A keyed `nearVector` with more than one key, or a key that doesn't match `targetVector` | `nearVector` | a server error, or the key-matching check | `InvalidInputException` (§5.3) |
| `alpha` outside `[0, 1]` | `hybrid` | none | not checked, which matches the query spec. The server decides |
| Wrong PHP types (`filters: 'x'`, `groupBy: 42`, `totalCount: 'x'`) | all | `WeaviateInvalidInputError` | a native `TypeError` from the typed signature |

The metric builder doesn't have to match the property's schema type on the client side. The server decides what comes back (§7.3).

## 7. Wire mapping

### 7.1 `AggregateRequest`

| Field | Source | Notes |
|---|---|---|
| `collection` (1) | collection name | |
| `tenant` (10) | the handle's tenant, `''` when there is none | |
| `objects_count` (20) | `totalCount` | |
| `aggregations` (21) | `returnMetrics`, in order: `{property, oneof int/number/text/boolean/date/reference}` | An empty list when it's `null` |
| `object_limit` (30) | `objectLimit` | Unset when `null`. Never set by `overAll` |
| `group_by` (31) | `GroupBy{collection: "", property: prop}` | Unset when there's no group-by |
| `limit` (32) | `GroupByAggregate::$limit` | Only with group-by |
| `filters` (40) | `FilterMapper` | Unset when `null` |
| `hybrid` (41) | §7.4 | |
| `near_vector` (42) | `NearVector{certainty?, distance?, targets?, vectors | vector_for_targets}` | The server floor for aggregate is 1.29, so PHP always uses the `Vectors` form: 1-D becomes `VECTOR_TYPE_SINGLE_FP32`, 2-D becomes `VECTOR_TYPE_MULTI_FP32`, and a keyed map becomes `vector_for_targets`. This is Python's ≥1.29 path. The deprecated `vector_bytes` / `vector_per_target` are never sent |
| `near_object` (43) | `NearObject{id, certainty?, distance?, targets?}` | |
| `near_text` (44) | `NearTextSearch{query[], certainty?, distance?, move_to?, move_away?, targets?}` | `Move{force, concepts, uuids}` |
| `near_image` (45) | `NearImageSearch{image: base64, certainty?, distance?, targets?}` | |
| `near_audio`, `near_video`, `near_depth`, `near_thermal`, `near_imu` (46–50) | — | Supported by the server but **not exposed by Python**, so it isn't ported. It's a candidate `nearMedia()` addition after 1.0 (§10) |

The search messages also have a `selection` (MMR) field. Aggregate never sets it.

**Consistency level.** `AggregateRequest` has **no** `consistency_level` field. Python passes `consistency_level` into `_AggregateGRPC` but never uses it. So `withConsistencyLevel()` has **no effect** on aggregate. PHP documents this on `withConsistencyLevel()` and on `AggregateCollection`, and doesn't warn at runtime.

### 7.2 Transport behaviour

- **Timeout:** the `query` timeout ([09 §4.1](09-connection.md#41-timeout-seconds-all--0)).
- **Retries:** Python retries **only `UNAVAILABLE`**, with exponential backoff (sleeping 1, 2, 4, 8, 16 and then 32 seconds, and giving up once the attempt count passes 4). PHP routes Aggregate through the same gRPC retry policy as `Search`: `UNAVAILABLE` only, with the backoff from `RetryConfig`. Aggregate is a read, so it's safe to retry.
- **Errors:**
  - `PERMISSION_DENIED` becomes `ForbiddenException`. Python raises `InsufficientPermissionsError`.
  - Any other gRPC error, or running out of retries, becomes `QueryException` (a `GrpcException` carrying `grpcStatus` and the message). Python raises `WeaviateQueryError(..., "GRPC search")`.
- Metadata: auth and user headers, the same as every gRPC call ([09 §5](09-connection.md#5-headers)).

### 7.3 `AggregateReply` → result objects

| Reply | PHP |
|---|---|
| `took` (float) | not exposed (Python ignores it) |
| `single_result{objects_count?, aggregations?}` with no group-by | `AggregateReturn` |
| `grouped_results{groups[]}` with group-by | `AggregateGroupByReturn` |
| no `result` set | an empty `AggregateReturn` (`properties: []`, `totalCount: 0`), or an empty `AggregateGroupByReturn` |

Which of the two to build is decided by **whether `groupBy` was passed**, not by which oneof comes back. That's the same as Python.

**The server picks the metric type from the schema, not from the request.** The reply's `aggregation` oneof comes from the property's data type: `int`/`int[]` gives `Integer`; other numerical types give `Number`; then text, boolean, date and reference. So `Metrics::of('year')->number()` on an `int` property returns an `AggregateInteger`. PHP builds the result class from the **reply's** oneof, like Python. If the oneof isn't set, PHP throws `QueryException("Unknown aggregation type …")`, which matches Python's `ValueError`.

**The server returns properties in random order.** It iterates a Go map. `properties` is keyed by property name, so order doesn't matter. Don't rely on the insertion order.

**Presence and `null`.** All the reply fields are proto3 `optional`, and PHP maps "not present" to `null` **for every field**, using `has*()`. Python does the same for everything except `count` (int, number, date and boolean) and `objects_count`, which it reads without checking presence, so they come back as `0` when missing. On an empty result set the server does send `count = 0` and `objects_count = 0`, so both clients agree there (`test_aggregate_empty_result_none_values`: count `0`, every other metric `null`).
- PHP difference: **`totalCount` is `null` when `totalCount: false` was passed**, even though the server always fills in `objects_count`. That matches Python's declared type, `Optional[int]`, and what its GraphQL path returned. Python's gRPC path returns the number anyway.

**Boolean metrics come back in full.** The server fills every boolean field whatever flags were set (a `// TODO: check if it was requested` in `prepare_aggregate_reply.go`). PHP passes the reply through without masking, like Python.

**int64.** The client needs 64-bit PHP. `google/protobuf` returns `int64` as `int` there. `Transport\Mapper` casts anyway, in case it gets a numeric string.

### 7.4 `hybrid` → `Hybrid` message

This uses the shared hybrid mapper from [12](12-query-and-generate.md) with `fusionType = null`, and follows Python's `_parse_hybrid`:

| PHP | `Hybrid` field | Rule |
|---|---|---|
| `query` | `query` | When it's `null`, the field is left unset and alpha is forced to `1` |
| `alpha` | `alpha_param` + `use_alpha_param = true` on servers **≥ 1.36.6** | When `alpha` is `null` on these servers, `alpha_param` is left unset and the server default (0.7) applies. † Python's code has a TODO to raise the version to 1.36.7 |
| `alpha` | `alpha` (deprecated) on servers **< 1.36.6** | `alpha ?? 0.7` |
| `queryProperties` | `properties` | |
| `vector` 1-D `list<float>` | `vector_bytes` (little-endian float32) | Python still uses this deprecated field for hybrid. PHP matches Python for safety, because it's unknown whether aggregate `Hybrid.vectors` was read on 1.29.0 |
| `vector` 2-D | `vectors[{MULTI_FP32}]` | |
| `vector` `HybridVector::nearText(...)` | `near_text` | Targets are not set inside the sub-message |
| `vector` `HybridVector::nearVector(...)` | `near_vector` with `vector_for_targets` / `vector_bytes` | |
| `targetVector` | `targets` | |
| `maxVectorDistance` | `vector_distance` (the `threshold` oneof) | |
| `bm25Operator` | `bm25_search_operator{operator, minimum_or_tokens_match?}` | |

When `query` and `vector` are both `null`, the whole `hybrid` is left unset (§2.6).

## 8. Version gates

| Feature | Min server | Behaviour on older servers |
|---|---|---|
| **All of `collection.aggregate`**, and `length()` | **1.29.0** | **No gate is needed**, because the client floor is 1.29.0 ([ADR 0004](decisions/0004-server-version-floor.md)). Checked: `aggregate.proto` first ships in `v1.29.0` (it's missing at the `v1.28.0` and `v1.28.4` tags), and it is identical to `main` |
| `hybrid(bm25Operator: BM25Operator::or()/::and())` | **1.31.0** † | Python doesn't gate this client-side (its test only skips below 1.31). PHP throws `UnsupportedFeatureException`, because an old server would silently ignore an unknown field and return wrong numbers |
| `BM25Operator::andCross()` | **1.37.15, 1.38.8 or 1.39.0 and later** | `UnsupportedFeatureException`, the same check as Python's `_BM25_AND_CROSS_MIN_VERSIONS` |
| hybrid `alpha_param` | 1.36.6 | Not a gate; this only picks the wire field (§7.4) |

**Python still has a GraphQL path for older servers.** When the server is below 1.29.0, every Python aggregate method builds a GraphQL `Aggregate { … }` query (`weaviate/gql/aggregate.py`, `_BaseExecutor._base` and `_do`) and posts it to `/v1/graphql`, marked "remove once 1.29 is the minimum supported version". That path has its own limitations:
- no reference filters;
- `near_vector` only takes a flat list and a single string target;
- `over_all(total_count=False)` with no metrics fails with "no body";
- group-by values come back as strings.

**PHP doesn't port it**: the client floor is 1.29.0 ([ADR 0004](decisions/0004-server-version-floor.md)), so the client can't connect to a server without gRPC aggregate.

**`length()`** is built on aggregate and always works at the 1.29 floor. (Python's `len(collection)` also works on 1.27–1.28 through its GraphQL path; PHP doesn't connect to those versions.)

## 9. Result objects

All of these live in `Weaviate\Client\Result\Aggregate` and are `final readonly`, with public promoted properties. Names follow Python, camelCased.

```php
final readonly class AggregateReturn {
    /** @param array<string, AggregateResult> $properties keyed by property name */
    public function __construct(public array $properties, public ?int $totalCount) {}
}

final readonly class AggregateGroupByReturn {
    /** @param list<AggregateGroup> $groups */
    public function __construct(public array $groups) {}
}

final readonly class AggregateGroup {
    /** @param array<string, AggregateResult> $properties */
    public function __construct(public GroupedBy $groupedBy, public array $properties, public ?int $totalCount) {}
}

final readonly class GroupedBy {
    /** @param string|int|float|bool|list<string>|list<int>|list<float>|list<bool>|GeoCoordinate|null $value */
    public function __construct(public string $prop, public mixed $value) {}
}

interface AggregateResult {}   // marker interface; Python's AggregateResult Union

final readonly class AggregateInteger implements AggregateResult {
    public function __construct(
        public ?int $count, public ?int $maximum, public ?float $mean, public ?float $median,
        public ?int $minimum, public ?int $mode, public ?int $sum,
    ) {}
}

final readonly class AggregateNumber implements AggregateResult {
    public function __construct(
        public ?int $count, public ?float $maximum, public ?float $mean, public ?float $median,
        public ?float $minimum, public ?float $mode, public ?float $sum,
    ) {}
}

final readonly class TopOccurrence {
    public function __construct(public ?int $count, public ?string $value) {}   // count ← wire `occurs`
}

final readonly class AggregateText implements AggregateResult {
    /** @param list<TopOccurrence> $topOccurrences */
    public function __construct(public ?int $count, public array $topOccurrences) {}
}

final readonly class AggregateBoolean implements AggregateResult {
    public function __construct(
        public ?int $count, public ?float $percentageFalse, public ?float $percentageTrue,
        public ?int $totalFalse, public ?int $totalTrue,
    ) {}
}

final readonly class AggregateDate implements AggregateResult {
    public function __construct(
        public ?int $count, public ?\DateTimeImmutable $maximum, public ?\DateTimeImmutable $median,
        public ?\DateTimeImmutable $minimum, public ?\DateTimeImmutable $mode,
    ) {}
}

/** @experimental Python marks reference aggregation "currently bugged on Weaviate's side" and doesn't export it */
final readonly class AggregateReference implements AggregateResult {
    /** @param list<string>|null $pointingTo */
    public function __construct(public ?array $pointingTo) {}
}
```

Mapping notes:
- **`GroupedBy::$prop`** is `path[0]`. **`$value`** comes from the reply's `value` oneof:
  - `text` gives a `string`, `int` an `int`, `number` a `float` and `boolean` a `bool`;
  - `texts`, `ints`, `numbers` and `booleans` give `list<…>`;
  - `geo` gives a `GeoCoordinate(latitude, longitude)`, shared with [11](11-data-references-tenants.md);
  - an unset value gives `null`, and logs a one-time warning through the PSR-3 logger, like Python's `Grpc002`.
- **Dates.** Python returns RFC 3339 **strings**. PHP returns `\DateTimeImmutable`, following [03 open question 2](03-api-design.md#open-design-questions). It uses the shared RFC 3339 parser, which accepts `Z` and offsets and cuts nanoseconds down to microseconds. A value that can't be parsed throws `QueryException` and doesn't silently become `null`. A date median of two values can fall between them (`2021-01-01T12:00:00Z` in the Python test).
- **Integer `mean` and `median`** are `float` (`1.5` for `[1, 2]`), and the other integer metrics are `int`.
- **`AggregateText::$topOccurrences`** is `[]` when the reply has no `top_occurences`.
- **Arrays.** For array properties, `count` counts **values**, not objects. In Python's test, one object with `ints: [1, 2]` gives `count == 2`.
- `sum` is Python's `sum_`. Python's `AggregateInteger.sum_` is declared as `int`, and so is PHP's.

## 10. Collection length

| Python | PHP | Behaviour |
|---|---|---|
| `len(collection)` (sync `__len__`) | `$collection->length(): int` | `aggregate->overAll(totalCount: true)->totalCount`, which sends `objects_count = true` and no metrics. It honours `withTenant()`. It throws `QueryException` if the count is missing, where Python uses `assert` |
| `await collection.length()` (async) | `$asyncCollection->length(): Future<int>` | the same call |

**`Countable` is not implemented.** `count($collection)` would hide a network call behind a language construct, which breaks the "I/O only in methods that clearly perform it" rule ([03](03-api-design.md#conventions)). It would also make failures surprising in code that treats `count()` as cheap. `length()` is explicit. The version gate is 1.29, as §8 describes.

**Not ported, and not in Python either:** `nearMedia()` for audio, video, depth, thermal and IMU, request `type` flags, and `took`. They're listed so nobody adds them by accident before 1.0. They can be added later as pure additions.

## 11. Examples

```php
use Weaviate\Client\Query\{Filter, Metrics, GroupByAggregate, Move, BM25Operator};

$articles = $client->collections->use('Article');

// Count everything
$n = $articles->length();                                   // int
$n = $articles->aggregate->overAll()->totalCount;           // same

// Metrics over a filtered subset
$res = $articles->aggregate->overAll(
    filters: Filter::byProperty('year')->greaterOrEqual(2020),
    returnMetrics: [
        Metrics::of('year')->integer(mean: true, maximum: true, minimum: true),
        Metrics::of('title')->text(topOccurrencesValue: true, topOccurrencesCount: true, limit: 3),
        Metrics::of('published')->boolean(percentageTrue: true),
        Metrics::of('createdAt')->date(),                   // all date metrics
    ],
);
$res->totalCount;                                           // ?int
$res->properties['year']->mean;                             // ?float (AggregateInteger)
foreach ($res->properties['title']->topOccurrences as $t) {
    echo $t->value, ': ', $t->count, PHP_EOL;
}
$res->properties['createdAt']->maximum?->format(DATE_ATOM);

// Group-by, with at most 5 groups
$byYear = $articles->aggregate->overAll(
    groupBy: new GroupByAggregate(prop: 'year', limit: 5),
    returnMetrics: Metrics::of('wordCount')->number(mean: true),   // a single metric is OK
);
foreach ($byYear->groups as $g) {
    printf("%s=%s → %d objects, mean %s\n", $g->groupedBy->prop, $g->groupedBy->value, $g->totalCount, $g->properties['wordCount']->mean);
}

// Aggregate over a semantic neighbourhood: the 50 nearest, then within a distance, on a named vector
$res = $articles->aggregate->nearText(
    query: ['vector databases'],
    objectLimit: 50,
    distance: 0.3,
    moveAway: new Move(force: 0.5, concepts: ['sql']),
    targetVector: 'body_vec',
    returnMetrics: Metrics::of('year')->integer(),
);

// nearVector with a keyed input (the key is the target)
$res = $articles->aggregate->nearVector(['body_vec' => $vec], certainty: 0.8);

// nearObject, grouped by a string prop
$res = $articles->aggregate->nearObject($uuid, objectLimit: 20, groupBy: 'category');

// nearImage from a file
$res = $products->aggregate->nearImage(new \SplFileInfo('shoe.jpg'), objectLimit: 10,
    returnMetrics: Metrics::of('price')->number(mean: true, median: true));

// Hybrid: keyword-heavy, capped, with a BM25 operator (needs 1.31 or later)
$res = $articles->aggregate->hybrid(
    query: 'banana two',
    alpha: 0.25,
    queryProperties: ['title'],
    objectLimit: 10,
    bm25Operator: BM25Operator::or(minimumMatch: 1),
    maxVectorDistance: 0.4,
);

// Tenant-scoped
$acmeCount = $client->collections->use('Doc')->withTenant('acme')->length();
```

## 12. Test checklist (becomes unit and integration tests in P3)

**Unit tests (request building, no server):**
- The builder default rule for each of the six builders: no flags means all flags; one flag means only that one; and every flag maps to the proto field with the same meaning. Watch for the proto field order differing from the argument order.
- `text()`: `limit` gives `top_occurences_limit`; a `null` limit leaves the field unset; `topOccurrencesValue` alone gives `top_occurences = true` (the PHP deviation).
- A single `Metric` gets wrapped in a list; `null` gives an empty `aggregations`; duplicate property names throw.
- `groupBy` as a string and as an object: `GroupBy{collection: "", property}` plus the top-level `limit`; no group-by leaves both unset.
- `totalCount` gives `objects_count`; `objectLimit` is unset when `null`; `overAll` never sets `object_limit`.
- Each search method sets the right `search` oneof branch and nothing else.
- `nearText`: a string is wrapped; `Move` is mapped; `targets`.
- `nearVector`: 1-D gives `SINGLE_FP32`; 2-D gives `MULTI_FP32`; a keyed one-entry map gives `vector_for_targets`; a keyed two-entry map throws; a key that doesn't match `targetVector` throws; a `Traversable` is accepted.
- `nearImage`: a path, base64, an `SplFileInfo` and a stream all give the same base64.
- `hybrid`:
  - `query: null` forces alpha to 1;
  - with both `query` and `vector` null, no hybrid is set;
  - alpha on servers ≥ 1.36.6 gives `alpha_param` plus `use_alpha_param`, and on older servers `alpha ?? 0.7`;
  - 1-D gives `vector_bytes`;
  - `maxVectorDistance` gives `vector_distance`;
  - the `bm25Operator` mapping and its gates at 1.31 and for `andCross`;
  - `fusion_type` stays UNSPECIFIED.
- Validation: the "at least one of certainty/distance/objectLimit" message for each `near*` (and not for hybrid); certainty and distance together; negative limits; an empty prop; an empty query. Assert that none of these reach the transport (use a mock transport).
- The floor: connecting to a fake 1.28.4 server fails in `connect()`, so aggregate needs no gate of its own.
- Reply mapping from fixture `AggregateReply` messages:
  - every oneof type;
  - a missing optional gives `null`;
  - `totalCount` is `null` when it wasn't requested;
  - the result type follows the reply oneof (a `number` request on an int property gives `AggregateInteger`);
  - an unknown oneof throws;
  - an empty reply gives an empty return of the right class;
  - every `GroupedBy` value type, including `geo` and arrays;
  - dates with `Z`, with offsets and with nanoseconds;
  - a date that can't be parsed throws.
- gRPC errors: `PERMISSION_DENIED` gives `ForbiddenException`; other statuses give `QueryException` with `grpcStatus`; `UNAVAILABLE` is retried and then gives `QueryException`.
- `withConsistencyLevel()` leaves the aggregate request unchanged (a golden-bytes comparison).

**Integration tests (a real server, the CI matrix from [05](05-testing-and-ci.md), 1.29 and later):** port every test in Python's `integration/test_collection_aggregate.py`:
- `length()` with 0, 1 and 10,000 objects; tenant-scoped `length()` (`tenant2` has 2×, `tenant3` has 0).
- An empty collection gives `totalCount` 0. A simple text count.
- Top occurrences with `limit: 1` gives a single item with count 2.
- Group-by `limit: 2` gives exactly two groups; group-by with no matches gives `[]`; group-by on text and on int gives typed values (`1`, not `"1"`).
- `overAll` with each filter operator. A reference filter (`Filter::byRef('ref')->byProperty('text')->equal('one')`).
- `nearObject`, `nearVector` and `nearText` with `objectLimit` 1 and 2, `certainty` and `distance`, and the missing-parameter error.
- `hybrid` with `objectLimit`; group-by with alpha 0; named vectors plus `targetVector`; BM25 `or(minimumMatch: 1)` gives `totalCount` 4 (1.31 and later).
- `nearImage`: Python skips it in CI (the img2vec module was removed). PHP runs it only in the e2e profile that has `multi2vec-clip`.
- All metric types over scalar **and** array properties, with the exact values from Python's `test_all_available_aggregations`. Skip the assertion on the date-array mode, which is flaky upstream.
- An empty filtered set: count is 0 and every other integer and number metric is `null` (issue #11219).
- **Settling the † items:**
  - does text `count`+`value` without the top-occurrences flag still return occurrences;
  - the default size of the top-occurrences list;
  - hybrid with no `objectLimit`;
  - whether `objectLimit: 0` is allowed;
  - group ordering;
  - array group-by membership;
  - whether `reference()` works (keep it `@experimental` if it doesn't).
- Named-vector collection: no `targetVector` gives `QueryException`; an unknown target gives `QueryException`.
- RBAC: a role without `read_data` gets `ForbiddenException`.

## 13. Couldn't be verified from source

- **Server-side semantics** marked † above: the default top-occurrences size; the text-flag behaviour (§3.2); hybrid without `objectLimit`; `objectLimit: 0`; group order; group membership for array properties; whether `text()` also applies to `uuid` properties; the exact state of the reference-aggregation bug.
- **The BM25 operator minimum version for aggregate**, taken as 1.31 from Python's test skip; the client itself doesn't enforce it. Also whether the `Hybrid.vectors` field is honoured in aggregate on 1.29.0. PHP stays on `vector_bytes` for 1-D hybrid vectors, as Python does.
- The final version for Python's `use_alpha_param` switch (its code has a TODO about 1.36.6 versus 1.36.7).
- Whether `withConsistencyLevel()` is meant to affect aggregate on the server. Today it can't, because the proto has no field for it.
