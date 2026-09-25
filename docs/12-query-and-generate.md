# 12: Query, filters and Generate (RAG) specification

This maps **every** search, filter, sort, group-by, return-shaping, iterator and generative option in Python v4 to PHP. Sources, read on 2026-09-25 from the Python client's `main` branch:

- `weaviate/collections/queries/*/{query,generate}/executor.py` (one per method), `queries/base_executor.py` (result parsing), `queries/fetch_object_by_id/executor.py`
- `weaviate/collections/grpc/query.py` (`SearchRequest` builder) and `grpc/shared.py` (vector packing, near-* and hybrid parsing, BM25 operator)
- `weaviate/collections/classes/filters.py` and `weaviate/collections/filters.py` (filter DSL and gRPC conversion)
- `weaviate/collections/classes/grpc.py` (`MetadataQuery`, `QueryReference`, `QueryNested`, `TargetVectors`, `HybridFusion`, `HybridVector`, `NearVector`, `Move`, `Rerank`, `GroupBy`, `Sort`, `Boost`, `Diversity`/`MMR`, `BM25OperatorFactory`, `NearMediaType`)
- `weaviate/collections/classes/generative.py` (`GenerativeConfig`, `GenerativeParameters`), `classes/internal.py` (result types, `_Generative`, `_QueryOptions`)
- `weaviate/collections/iterator.py` and `collections/collection/sync.py` (`iterator()`)
- Protos from `weaviate/weaviate` `main`, `grpc/proto/v1/`: `search_get.proto`, `base_search.proto`, `base.proto`, `generative.proto`, `properties.proto`
- Integration tests for version floors: `integration/test_collection_{filter,hybrid,boost,diversity,diversity_hybrid,query_profile,rerank}.py`

Rows in [02 §5](02-feature-parity-matrix.md#5-query-collectionquery-all-grpc-search) and [02 §6](02-feature-parity-matrix.md#6-generate--rag-collectiongenerate-grpc-search--generativeproto) link here. Everything in this document goes over gRPC `weaviate.v1.Weaviate/Search`. The same filter DSL is reused by `data->deleteMany()` (gRPC `BatchDelete`) and by aggregate (gRPC `Aggregate`); those docs point back to §5.

---

## 1. Surface

```php
$col->query->fetchObjects(...)        // QueryReturn
$col->query->fetchObjectById(...)     // ?ObjectSingleReturn
$col->query->fetchObjectsByIds(...)   // QueryReturn
$col->query->nearVector(...)          // QueryReturn | GroupByReturn
$col->query->nearObject(...)
$col->query->nearText(...)
$col->query->nearImage(...)
$col->query->nearMedia(...)
$col->query->hybrid(...)
$col->query->bm25(...)

$col->generate->fetchObjects(...)     // GenerativeReturn
$col->generate->fetchObjectsByIds(...)
$col->generate->nearVector(...)       // GenerativeReturn | GenerativeGroupByReturn
$col->generate->nearObject(...) / nearText / nearImage / nearMedia / hybrid / bm25

$col->iterator(...)                   // ObjectIterator (IteratorAggregate, cursor-paged)
```

There is **no** `generate->fetchObjectById()`; Python doesn't have one either.

Tenant and consistency level are taken from the handle (`withTenant()`, `withConsistencyLevel()`), never from method arguments.

### 1.1 Which arguments each method takes

✓ = accepted. Anything else is not a parameter of that method, in Python or in PHP.

| Argument | fetchObjects | fetchObjectById | fetchObjectsByIds | nearVector / nearObject / nearText / nearImage / nearMedia | hybrid | bm25 |
|---|---|---|---|---|---|---|
| `limit`, `offset` | ✓ | — (limit forced to 1) | ✓ | ✓ | ✓ | ✓ |
| `after` | ✓ | — | ✓ | — | — | — |
| `filters` | ✓ | — | — (built from ids) | ✓ | ✓ | ✓ |
| `sort` | ✓ | — | ✓ | — | — | — |
| `autoLimit` | — | — | — | ✓ | ✓ | ✓ |
| `groupBy` | — | — | — | ✓ | ✓ | ✓ |
| `rerank` | — | — | — | ✓ | ✓ | ✓ |
| `boost` | — | — | — | ✓ | ✓ | ✓ |
| `diversitySelection` | — | — | — | ✓ | ✓ | — |
| `targetVector` | — | — | — | ✓ | ✓ | — |
| `certainty`, `distance` | — | — | — | ✓ | — (`maxVectorDistance`) | — |
| `includeVector`, `returnMetadata`, `returnProperties`, `returnReferences` | ✓ | ✓ (no `returnMetadata`) | ✓ | ✓ | ✓ | ✓ |
| Generate args (§8.1) | ✓ | — | ✓ | ✓ | ✓ | ✓ |

---

## 2. Common arguments

These have the same meaning and wire mapping everywhere they appear.

| Python param | PHP param | PHP type | Default | Wire (`SearchRequest`) | Notes |
|---|---|---|---|---|---|
| `limit` | `limit` | `?int` | `null` (server default, `QUERY_DEFAULTS_LIMIT`) | `limit` (uint32) | Must be 0 to 4 294 967 295 |
| `offset` | `offset` | `?int` | `null` | `offset` (uint32) | Same range |
| `auto_limit` | `autoLimit` | `?int` | `null` | `autocut` (uint32) | Autocut: the number of result "jumps" to keep. 0 or null means disabled. Must be >= 0 |
| `filters` | `filters` | `?FilterExpression` | `null` | `filters` | §5 |
| `group_by` | `groupBy` | `?GroupBy` | `null` | `group_by` | §4.9. Switches the return type to `GroupByReturn` / `GenerativeGroupByReturn` |
| `rerank` | `rerank` | `?Rerank` | `null` | `rerank` | §4.8. Also forces metadata parsing so `rerankScore` is populated |
| `boost` | `boost` | `?Boost` | `null` | `boost` | §4.11, server 1.38.0 and later |
| `diversity_selection` | `diversitySelection` | `?MMR` | `null` | `<search>.selection.mmr` | §4.10, server 1.37.0 and later (hybrid: 1.38.6) |
| `target_vector` | `targetVector` | `string\|list<string>\|TargetVectors\|null` | `null` | `<search>.targets` | §4.3. Required when the collection has more than one named vector |
| `include_vector` | `includeVector` | `bool\|string\|list<string>` | `false` | `metadata.vector` / `metadata.vectors` | `true` = all vectors; a name or list of names = only those named vectors. A string is normalised to `[string]` (§12, Python quirk Q3) |
| `return_metadata` | `returnMetadata` | `MetadataQuery\|list<string>\|null` | `null` | `metadata` | §4.1. `null` = only the uuid |
| `return_properties` | `returnProperties` | `string\|QueryNested\|list<string\|QueryNested>\|bool\|null` | `null` | `properties` | §6.1 |
| `return_references` | `returnReferences` | `QueryReference\|list<QueryReference>\|null` | `null` | `properties.ref_properties` | §6.2. `null` = no references |

Python also accepts `TypedDict` classes for `return_properties`/`return_references` (generic ORM-style typing). PHP does not: see [03 open question 1](03-api-design.md#open-design-questions). PHPStan generics on `Collection` cover static typing.

### 2.1 Return type rules

| Call | No `groupBy` | With `groupBy` |
|---|---|---|
| `query->*` | `QueryReturn` | `GroupByReturn` |
| `generate->*` | `GenerativeReturn` | `GenerativeGroupByReturn` |
| `query->fetchObjectById` | `?ObjectSingleReturn` | n/a |

PHP can't overload on arguments, so the native return type is a union (`QueryReturn|GroupByReturn`) and the precise type is given to PHPStan/Psalm with a conditional return type:

```php
/** @return ($groupBy is null ? QueryReturn : GroupByReturn) */
public function nearText(string|array $query, /* … */ ?GroupBy $groupBy = null, /* … */): QueryReturn|GroupByReturn;
```

---

## 3. Methods

Primary inputs (the first parameter) may be passed positionally or by name. Everything else is named-only by convention: the order below follows Python, but callers should use names.

### 3.1 `fetchObjects()` (Python `fetch_objects`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `limit` | `limit` | `?int` | `null` | |
| `offset` | `offset` | `?int` | `null` | |
| `after` | `after` | `string\|UuidInterface\|null` | `null` | Cursor: return objects after this uuid, in uuid order. Sent as `SearchRequest.after` (string) |
| `filters` | `filters` | `?FilterExpression` | `null` | |
| `sort` | `sort` | `?Sorting` | `null` | §4.12 |
| `include_vector` | `includeVector` | see §2 | `false` | |
| `return_metadata` | `returnMetadata` | see §2 | `null` | |
| `return_properties` | `returnProperties` | see §2 | `null` | |
| `return_references` | `returnReferences` | see §2 | `null` | |

No search operator is set, so the server does a plain object listing. The Python builder can take `rerank`/`boost` here but the executor never passes them, so they aren't exposed.

### 3.2 `fetchObjectById()` (Python `fetch_object_by_id`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `uuid` | `uuid` | `string\|UuidInterface` | required | Positional #1 |
| `include_vector` | `includeVector` | `bool\|string\|list<string>` | `false` | Positional #2 in Python, so also allowed positionally in PHP |
| `return_properties` | `returnProperties` | see §2 | `null` | |
| `return_references` | `returnReferences` | see §2 | `null` | |

Behaviour:
- It sends `limit = 1`, `filters = Filter::byId()->equal($uuid)` and a forced `MetadataQuery(creationTime: true, lastUpdateTime: true, isConsistent: true)`.
- No result returns `null` (not an exception).
- The result is `ObjectSingleReturn`, whose metadata is `MetadataSingleObjectReturn { DateTimeImmutable $creationTime; DateTimeImmutable $lastUpdateTime; ?bool $isConsistent }` (both times non-null).

### 3.3 `fetchObjectsByIds()` (Python `fetch_objects_by_ids`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `ids` | `ids` | `iterable<string\|UuidInterface>` | required | |
| `limit`, `offset`, `after`, `sort`, `include_vector`, `return_*` | same | | | As §3.1. **No `filters`** |

Behaviour:
- **Empty `ids`** returns an empty `QueryReturn` (or `GenerativeReturn` with null generative fields) **without a request**.
- Otherwise the filter is `Filter::anyOf(array_map(fn ($id) => Filter::byId()->equal($id), $ids))`. That's `anyOf` of `Equal` filters, not `containsAny`, matching Python. A single id collapses to one `Equal`, because `anyOf` of one element returns that element.
- **Open question (§16):** Python does not raise `limit` to `count($ids)`, so the server's default limit may truncate the result. PHP proposes: when `limit === null`, send `limit = count(array_unique($ids))`. Confirm the server default before adopting this.

### 3.4 `nearVector()` (Python `near_vector`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `near_vector` | `nearVector` | `list<float>\|list<list<float>>\|array<string, list<float>\|list<list<float>>\|ListOfVectorsQuery>` | required | §7 |
| `certainty` | `certainty` | `int\|float\|null` | `null` | `NearVector.certainty` (double). Mutually exclusive with `distance` (§11) |
| `distance` | `distance` | `int\|float\|null` | `null` | `NearVector.distance` (double) |
| common | `limit`, `offset`, `autoLimit`, `filters`, `groupBy`, `rerank`, `boost`, `targetVector`, `includeVector`, `returnMetadata`, `returnProperties`, `returnReferences`, `diversitySelection` | | | §2 |

### 3.5 `nearObject()` (Python `near_object`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `near_object` | `nearObject` | `string\|UuidInterface` | required | `NearObject.id` (string) |
| `certainty`, `distance` | same | `int\|float\|null` | `null` | |
| common | as §3.4 | | | |

### 3.6 `nearText()` (Python `near_text`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `query` | `query` | `string\|list<string>` | required | A string becomes `[string]`. Sent as `NearTextSearch.query` (repeated) |
| `certainty`, `distance` | same | `int\|float\|null` | `null` | |
| `move_to` | `moveTo` | `?Move` | `null` | §4.5 |
| `move_away` | `moveAway` | `?Move` | `null` | §4.5 |
| common | as §3.4 | | | |

A text vectorizer module is required on the target vector. The server errors otherwise.

### 3.7 `nearImage()` (Python `near_image`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `near_image` | `nearImage` | `string\|\SplFileInfo\|resource` | required | §4.14 blob rules. Sent base64 as `NearImageSearch.image` |
| `certainty`, `distance` | same | | `null` | |
| common | as §3.4 | | | |

### 3.8 `nearMedia()` (Python `near_media`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `media` | `media` | `string\|\SplFileInfo\|resource` | required | §4.14 |
| `media_type` | `mediaType` | `NearMediaType` | required | Picks the oneof: `Audio` → `near_audio.audio`, `Depth` → `near_depth.depth`, `Image` → `near_image.image`, `Imu` → `near_imu.imu`, `Thermal` → `near_thermal.thermal`, `Video` → `near_video.video` |
| `certainty`, `distance` | same | | `null` | |
| common | as §3.4 | | | |

`multi2vec-bind` supports all media types. `multi2vec-clip` supports only `Image`.

### 3.9 `hybrid()` (Python `hybrid`)

| Python param | PHP param | Type | Default | Wire (`Hybrid.*`) | Notes |
|---|---|---|---|---|---|
| `query` | `query` | `?string` | required (nullable) | `query` | `null` means a vector-only search, and alpha is forced to 1 |
| `alpha` | `alpha` | `int\|float\|null` | `null` | `alpha_param` + `use_alpha_param = true` (server 1.36.6 and later); `alpha` (older) | 0 = pure BM25, 1 = pure vector. On servers older than 1.36.6 a `null` alpha is sent as **0.7** (Python's client default). On newer servers `null` is left unset, so the server default applies. Validated to `[0, 1]` (PHP addition) |
| `vector` | `vector` | `list<float>\|list<list<float>>\|array<string, …>\|HybridNearText\|HybridNearVector\|null` | `null` | §7.3 | `null` means the query text is vectorized |
| `query_properties` | `queryProperties` | `?list<string>` | `null` (all searchable text props) | `properties` | Supports the server's `prop^boost` syntax, e.g. `'title^2'` |
| `fusion_type` | `fusionType` | `?HybridFusion` | `null` (server default) | `fusion_type` | `Ranked` → `FUSION_TYPE_RANKED`, `RelativeScore` → `FUSION_TYPE_RELATIVE_SCORE` |
| `max_vector_distance` | `maxVectorDistance` | `int\|float\|null` | `null` | `vector_distance` (oneof `threshold`) | Vector-side distance threshold |
| `bm25_operator` | `bm25Operator` | `?BM25OperatorOptions` | `null` | `bm25_search_operator` | §4.7 |
| `diversity_selection` | `diversitySelection` | `?MMR` | `null` | `selection` | Hybrid needs server **1.38.6** and later |
| `target_vector` | `targetVector` | as §2 | `null` | `targets` | |
| common | `limit`, `offset`, `autoLimit`, `filters`, `groupBy`, `rerank`, `boost`, `includeVector`, `return*` | | | | |

If both `query` and `vector` are `null`, **no `hybrid_search` is sent**, so the request becomes a plain object listing (Python behaviour). PHP keeps this but logs a debug message.

### 3.10 `bm25()` (Python `bm25`)

| Python param | PHP param | Type | Default | Wire (`BM25.*`) | Notes |
|---|---|---|---|---|---|
| `query` | `query` | `?string` | required (nullable) | `query` | `null` means no `bm25_search` is sent (a plain listing), as in Python |
| `query_properties` | `queryProperties` | `?list<string>` | `null` (all) | `properties` | `prop^boost` supported |
| `operator` | `operator` | `?BM25OperatorOptions` | `null` (server default: OR) | `search_operator` | §4.7 |
| common | `limit`, `offset`, `autoLimit`, `filters`, `groupBy`, `rerank`, `boost`, `includeVector`, `return*` | | | | No `targetVector`, `diversitySelection` |

---

## 4. Supporting types (`Weaviate\Client\Query\…`)

All of these are `final readonly` value objects or backed enums, and all are immutable.

### 4.1 `MetadataQuery`

| Python field | PHP field | Type | Default | Wire (`MetadataRequest`) |
|---|---|---|---|---|
| `creation_time` | `creationTime` | `bool` | `false` | `creation_time_unix` |
| `last_update_time` | `lastUpdateTime` | `bool` | `false` | `last_update_time_unix` |
| `distance` | `distance` | `bool` | `false` | `distance` |
| `certainty` | `certainty` | `bool` | `false` | `certainty` |
| `score` | `score` | `bool` | `false` | `score` |
| `explain_score` | `explainScore` | `bool` | `false` | `explain_score` |
| `is_consistent` | `isConsistent` | `bool` | `false` | `is_consistent` |
| `query_profile` | `queryProfile` | `bool` | `false` | `query_profile`. Needs server **1.36.9** (client-side gate) |
| (always) | — | — | — | `uuid = true` |
| (from `includeVector`) | — | — | — | `vector = true` when `includeVector === true`; `vectors = [...]` when it is a list |

- `MetadataQuery::full()` sets everything except `queryProfile`.
- `MetadataQuery::fullWithProfile()` sets everything, including `queryProfile`.
- **List form:** `returnMetadata: ['distance', 'creationTime']` is accepted. The Python snake_case spellings (`'creation_time'`, `'last_update_time'`, `'explain_score'`, `'is_consistent'`, `'query_profile'`) are accepted too, for copy-paste parity. Unknown names throw `InvalidInputException`.

### 4.2 `QueryNested` and properties

`new QueryNested(name: 'address', properties: ['city', new QueryNested('geo', ['lat', 'lon'])])`

| Python | PHP | Type | Wire |
|---|---|---|---|
| `name` | `name` | `string` | `ObjectPropertiesRequest.prop_name` |
| `properties` | `properties` | `string\|QueryNested\|list<string\|QueryNested>` | Strings go to `primitive_properties`. `QueryNested` goes to `object_properties` (recursive) |

### 4.3 `TargetVectors` (multi-target combination)

| Python | PHP | Wire `Targets` |
|---|---|---|
| `target_vector="a"` | `targetVector: 'a'` | `target_vectors=["a"]`, combination unspecified |
| `target_vector=["a","b"]` | `targetVector: ['a','b']` | `target_vectors=["a","b"]`, combination unspecified (the server default is minimum) |
| `TargetVectors.sum(["a","b"])` | `TargetVectors::sum(['a','b'])` | `COMBINATION_METHOD_TYPE_SUM` |
| `TargetVectors.average([...])` | `TargetVectors::average([...])` | `COMBINATION_METHOD_TYPE_AVERAGE` |
| `TargetVectors.minimum([...])` | `TargetVectors::minimum([...])` | `COMBINATION_METHOD_TYPE_MIN` |
| `TargetVectors.manual_weights({"a": 0.7, "b": [0.2, 0.1]})` | `TargetVectors::manualWeights(['a' => 0.7, 'b' => [0.2, 0.1]])` | `COMBINATION_METHOD_TYPE_MANUAL` |
| `TargetVectors.relative_score({...})` | `TargetVectors::relativeScore([...])` | `COMBINATION_METHOD_TYPE_RELATIVE_SCORE` |

Weights wire rule (from `_MultiTargetVectorJoin.to_grpc_target_vector`):
- For each `target => weight`, append one `WeightsForTarget{target, weight}` and one `target` to `target_vectors`.
- A **list** of weights (one per vector when a target gets several query vectors, §7.2) appends one entry per weight, so the target name repeats in `target_vectors`.

**Reordering rule:** when `nearVector`/`hybrid` receives a vector **map**, the target list is rebuilt in the **order of the map keys**, and `weights` is re-keyed in that order (`_recompute_target_vector_to_grpc`). The set of map keys must equal the set of target names. Otherwise throw `InvalidInputException("The near_vector keys [..] must match the target vectors [..]")`.

### 4.4 `NearVector::listOfVectors()` → `ListOfVectorsQuery`

This supplies several query vectors for a single target (many-vectors-over-one-space).

- `NearVector::listOfVectors([0.1, 0.2], [0.3, 0.4])` is 1-D.
- `NearVector::listOfVectors([[…],[…]], [[…]])` is 2-D, a list of multi-vectors.
- `dimensionality` is detected from the first element.
- Zero vectors, or an empty first vector, throws `InvalidInputException("At least one vector must be given")`.
- It can only appear as a **value in a vector map** (§7.2). Top-level use throws.

### 4.5 `Move`

`new Move(force: 0.5, objects: $uuid | [$uuids], concepts: 'x' | ['x','y'])`

- At least one of `objects` or `concepts` must be non-empty. Otherwise throw `InvalidInputException("Either objects or concepts need to be given")`.
- Scalars are wrapped into lists, and uuids are stringified.
- Wire: `NearTextSearch.Move{force (float), concepts, uuids}`.
- `force` isn't range-checked in Python. PHP validates `0 <= force <= 1` (PHP addition, †: check against the server).

### 4.6 `HybridVector` (hybrid sub-searches)

| Python | PHP | Wire |
|---|---|---|
| `HybridVector.near_text(query, *, certainty, distance, move_to, move_away)` | `HybridVector::nearText(query: string\|array, certainty:, distance:, moveTo:, moveAway:)` → `HybridNearText` | `Hybrid.near_text = NearTextSearch{query, certainty, distance, move_to, move_away}` (targets are **not** set inside; use `hybrid(targetVector:)`) |
| `HybridVector.near_vector(vector, *, certainty, distance)` | `HybridVector::nearVector(vector:, certainty:, distance:)` → `HybridNearVector` | `Hybrid.near_vector = NearVector{vector_bytes \| vector_for_targets, certainty, distance}` (§7.3) |

`HybridFusion` is a backed enum: `Ranked = 'FUSION_TYPE_RANKED'` and `RelativeScore = 'FUSION_TYPE_RELATIVE_SCORE'`.

### 4.7 `BM25Operator` (keyword token matching)

| Python | PHP | Wire `SearchOperatorOptions` | Min server |
|---|---|---|---|
| `BM25Operator.or_(minimum_match)` | `BM25Operator::or(minimumMatch: int)` | `operator = OPERATOR_OR`, `minimum_or_tokens_match = N` | 1.31.0 |
| `BM25Operator.and_()` | `BM25Operator::and()` | `operator = OPERATOR_AND` | 1.31.0 |
| `BM25Operator.and_cross()` | `BM25Operator::andCross()` | `operator = OPERATOR_AND_CROSS` | **1.37.15, 1.38.8 or 1.39.0 and later** (client gate, same as Python's `is_at_least_any`) |

- `or`/`and` are legal PHP method names (PHP 7.0 and later), so no trailing underscore is needed.
- `minimumMatch` must be >= 1.
- `andCross`: every searched property must share tokenization and analyzer settings, or the server rejects the query.
- The same object is used by `bm25(operator:)` and `hybrid(bm25Operator:)`.

### 4.8 `Rerank`

`new Rerank(prop: 'body', query: 'optional rerank query')` becomes `SearchRequest.rerank = Rerank{property, query?}`. It needs a `reranker-*` module. The result is `$obj->metadata->rerankScore`, or `$group->rerankScore` with group-by.

### 4.9 `GroupBy`

`new GroupBy(prop: 'year', numberOfGroups: 3, objectsPerGroup: 2)` becomes `GroupBy{path: [prop], number_of_groups, objects_per_group}`.
- Both counts must be >= 1 (PHP validation).
- Only one property is supported, and it can't be a reference path (proto comment).
- It's not available on `fetchObjects*`.

### 4.10 `Diversity::mmr()` → `MMR`

`Diversity::mmr(limit: ?int = null, balance: ?float = null)` becomes `<search>.selection = Selection{mmr: MMR{limit?, balance?}}`. It's set on `NearVector`, `NearObject`, `NearTextSearch`, every `Near<Media>Search`, and `Hybrid`.

- `limit` is the number of objects to select. The server **requires** it: at least 1, and no more than the query `limit` when that is set. PHP keeps it nullable for parity, but validates `>= 1` when set and `<= $limit` when both are set.
- `balance` is λ in `[0, 1]`: 1 means pure relevance, 0 means pure dissimilarity. Validated.
- It's not supported on multi-vector indexes (a server error).

### 4.11 `Boost` (soft re-ranking, server 1.38.0 and later)

A boost re-scores the primary search's candidates and never removes objects. Final score = `(1 − weight)·primary + weight·boost`.

| Python | PHP | Wire `Boost.Condition` |
|---|---|---|
| `Boost.filter(filter, *, weight, depth)` | `Boost::filter(FilterExpression $filter, weight:, depth:)` | `filter = <Filters>` |
| `Boost.time_decay(property, *, origin="now", scale, offset, curve, decay, weight, depth)` | `Boost::timeDecay(property:, scale: string\|\DateInterval, origin: 'now'\|\DateTimeInterface = 'now', offset: string\|\DateInterval\|null, curve: ?BoostCurve, decay: ?float, weight:, depth:)` | `time_decay = TimeDecayFunction{property, origin, scale, offset?, curve, decay_value?}` |
| `Boost.numeric_decay(property, *, origin, scale, offset, curve, decay, weight, depth)` | `Boost::numericDecay(property:, origin: float, scale: float, offset: ?float, curve:, decay:, weight:, depth:)` | `numeric_decay = NumericDecayFunction{…}` (doubles) |
| `Boost.numeric_property(name, *, modifier, weight, depth)` | `Boost::numericProperty(name:, modifier: ?BoostModifier, weight:, depth:)` | `property_value = PropertyValueFunction{property, modifier}` |
| `Boost.blend(boosts, *, weight, depth)` | `Boost::blend(Boost\|list<Boost> $boosts, weight:, depth:)` | several `conditions`; each sub-boost's `weight` becomes that condition's `weight` |

Enums:
- `BoostCurve`: `Exponential = 'exp'`, `Gaussian = 'gauss'`, `Linear = 'linear'`, mapped to `DECAY_CURVE_EXPONENTIAL`, `_GAUSS` and `_LINEAR`. `null` sends `DECAY_CURVE_UNSPECIFIED` (server default: exponential), matching Python, which sets the field explicitly.
- `BoostModifier`: `Log1p = 'log1p'` and `Sqrt = 'sqrt'`, mapped to `PROPERTY_VALUE_MODIFIER_*`. `null` sends `UNSPECIFIED`.

Top-level wire: `Boost{conditions, weight?, depth?}`. The server defaults are weight 0.5 and depth 100.

Conversion rules (ported from `_decay_duration_to_str` / `_decay_origin_to_str`):
- A `DateInterval` scale or offset becomes a total number of seconds `s`, emitted as follows:
  - `"{s/86400}d"` if it's a whole number of days,
  - else `"{s/3600}h"` if it's whole hours,
  - else `"{s/60}m"` if it's whole minutes,
  - else `"{s}s"`.

  An interval with `y` or `m` (months) set throws `InvalidInputException`, because it's ambiguous. Strings pass through unchanged (`"7d"`, `"24h"`, `"30m"`).
- A `DateTimeInterface` origin becomes RFC 3339 (`DATE_RFC3339_EXTENDED`). `'now'` passes through.

Validation (PHP, before I/O):
- `blend()` with no boosts throws.
- A sub-boost that has its own `depth` throws ("set depth on blend()").
- More than 20 conditions throws (a server limit documented in Python).
- The top-level `weight` must be in `[0, 1]`. Per-condition weights may be any float, including negative ones for demotion.
- `decay` must be in `(0, 1]`.
- `depth` must be >= 1.

Filters inside `Boost::filter` only support Equal, NotEqual, the comparison operators, and And/Or/Not. PHP does **not** pre-validate this; the server rejects the rest. That keeps PHP forward-compatible.

### 4.12 `Sort`

| Python | PHP | Wire `SortBy` |
|---|---|---|
| `Sort.by_property(name, ascending=True)` | `Sort::byProperty('name', ascending: true)` | `{ascending, path: [name]}` |
| `Sort.by_id(ascending=True)` | `Sort::byId()` | `path: ["_id"]` |
| `Sort.by_creation_time(ascending=True)` | `Sort::byCreationTime()` | `path: ["_creationTimeUnix"]` |
| `Sort.by_update_time(ascending=True)` | `Sort::byUpdateTime()` | `path: ["_lastUpdateTimeUnix"]` |
| chain: `Sort.by_property("a").by_update_time(False)` | `Sort::byProperty('a')->byUpdateTime(ascending: false)` | multiple `sort_by` in order |

`Sort::*` returns an immutable `Sorting`. Each chained call returns a new instance (Python mutates in place). `new Sort()` is private.

### 4.13 `QueryReference` / `QueryReferenceMultiTarget`

| Python | PHP | Type | Default | Wire `RefPropertiesRequest` |
|---|---|---|---|---|
| `link_on` | `linkOn` | `string` | required | `reference_property` |
| `include_vector` | `includeVector` | `bool\|string\|list<string>` | `false` | `metadata.vector` / `vectors` |
| `return_metadata` | `returnMetadata` | `MetadataQuery\|list<string>\|null` | `null` | `metadata` (**always sent**, with `uuid = true`) |
| `return_properties` | `returnProperties` | as §2 | `null` | `properties` (recursive) |
| `return_references` | `returnReferences` | `QueryReference\|list<…>\|null` | `null` | nested `ref_properties` (recursive) |
| `QueryReference.MultiTarget(..., target_collection)` | `QueryReference::multiTarget(linkOn:, targetCollection:, …)` → `QueryReferenceMultiTarget` | `string` | required | `target_collection` |

### 4.14 Blob inputs (`nearImage`, `nearMedia`, generative `images`)

This mirrors Python `parse_blob`:

| Input | Treatment |
|---|---|
| `string` that `is_file()` | File read and base64-encoded |
| any other `string` | Assumed to already be base64, and sent as is |
| `\SplFileInfo` | File read and base64-encoded |
| `resource` (stream) | `stream_get_contents` and base64-encoded |
| anything else | `InvalidInputException` |

**PHP design note:** the "string is a path if the file exists" rule is ambiguous, and in a web app a user-supplied string could make the client read a server-side file. PHP keeps the rule for parity, but also offers explicit `Blob::fromFile($path)`, `Blob::fromBase64($b64)` and `Blob::fromBytes($raw)`, and the docs recommend them. A PHP-only `AdditionalConfig::strictBlobInput` (default `false`) disables the path heuristic.

### 4.15 `GeoCoordinate`

`new GeoCoordinate(latitude: float, longitude: float)` validates latitude in `[-90, 90]` and longitude in `[-180, 180]`. It's used by `withinGeoRange()` and appears in results.

---

## 5. Filter DSL (`Weaviate\Client\Query\Filter`)

### 5.1 Entry points (static factory, not instantiable)

| Python | PHP | Target on the wire (`FilterTarget`) |
|---|---|---|
| `Filter.by_property(name, length=False)` | `Filter::byProperty('name', length: false)` → `FilterByProperty` | `property = name`, or `"len(name)"` when `length` is true |
| `Filter.by_id()` | `Filter::byId()` → `FilterById` | `property = "_id"` |
| `Filter.by_creation_time()` | `Filter::byCreationTime()` → `FilterByTime` | `property = "_creationTimeUnix"` |
| `Filter.by_update_time()` | `Filter::byUpdateTime()` → `FilterByTime` | `property = "_lastUpdateTimeUnix"` |
| `Filter.by_ref(link_on)` | `Filter::byRef('author')` → `FilterByRef` | `single_target = {on, target: …}` |
| `Filter.by_ref_multi_target(link_on, target_collection)` | `Filter::byRefMultiTarget('author', 'Person')` → `FilterByRef` | `multi_target = {on, target, target_collection}`. The first letter of `target_collection` is capitalised, as in Python |
| `Filter.by_ref_count(link_on)` | `Filter::byRefCount('authors')` → `FilterByCount` | `count = {on}` |
| `Filter.all_of([...])` | `Filter::allOf([...])` | `operator = AND, filters = [...]` |
| `Filter.any_of([...])` | `Filter::anyOf([...])` | `operator = OR, filters = [...]` |
| `Filter.not_(f)` | `Filter::not($f)` | `operator = NOT, filters = [f]` |

`allOf`/`anyOf` with **one** element return that element unchanged, and with **zero** elements they throw `InvalidInputException` ("Filter.all_of must have at least one filter"), as in Python.

### 5.2 Reference chains (`FilterByRef`)

`FilterByRef` methods continue the path. Each returns a **new** builder; Python mutates the chain in place.

| Python | PHP |
|---|---|
| `.by_ref(link_on)` | `->byRef('x')`, which appends `single_target{on: x}` to the end of the chain |
| `.by_ref_multi_target(reference, target_collection)` | `->byRefMultiTarget('x', 'Coll')` |
| `.by_ref_count(link_on)` | `->byRefCount('y')` |
| `.by_id()` / `.by_creation_time()` / `.by_update_time()` | `->byId()` / `->byCreationTime()` / `->byUpdateTime()` |
| `.by_property(name, length=False)` | `->byProperty('name', length: false)` |

Wire: the leaf target (`property` or `count`) is placed in the innermost `target` of the chain. For example `Filter::byRef('ref2')->byRef('ref')->byProperty('text', length: true)->lessThan(6)` becomes:
`target{single_target{on:"ref2", target{single_target{on:"ref", target{property:"len(text)"}}}}}`.

### 5.3 Operators and value rules

**`FilterByProperty`** (also reached through references):

| Python | PHP | Wire operator | Accepted value | Checks |
|---|---|---|---|---|
| `equal(v)` | `equal($v)` | `OPERATOR_EQUAL` | scalar, date, uuid, list (for array props) | An empty list throws ("Filtering on empty lists is not supported … use `Filter::byProperty('prop', length: true)->equal(0)`") |
| `not_equal(v)` | `notEqual($v)` | `OPERATOR_NOT_EQUAL` | same | same |
| `less_than(v)` | `lessThan($v)` | `OPERATOR_LESS_THAN` | same | same |
| `less_or_equal(v)` | `lessOrEqual($v)` | `OPERATOR_LESS_THAN_EQUAL` | same | same |
| `greater_than(v)` | `greaterThan($v)` | `OPERATOR_GREATER_THAN` | same | same |
| `greater_or_equal(v)` | `greaterOrEqual($v)` | `OPERATOR_GREATER_THAN_EQUAL` | same | same |
| `like(str)` | `like(string $pattern)` | `OPERATOR_LIKE` | `string`; `*` and `?` are wildcards | |
| `contains_any(list)` | `containsAny(array $values)` | `OPERATOR_CONTAINS_ANY` | non-empty list | An empty list throws ("must have at least one value") |
| `contains_all(list)` | `containsAll(array $values)` | `OPERATOR_CONTAINS_ALL` | non-empty list | same |
| `contains_none(list)` | `containsNone(array $values)` | `OPERATOR_CONTAINS_NONE` | non-empty list | same. Needs server **1.33.0** (client gate) |
| `is_none(bool)` | `isNone(bool $isNone = true)` | `OPERATOR_IS_NULL`, `value_boolean` | bool | Needs `indexNullState` on the collection (a server error otherwise). The default `true` is a PHP convenience |
| `within_geo_range(coord, distance)` | `withinGeoRange(GeoCoordinate $coordinate, float $distance)` | `OPERATOR_WITHIN_GEO_RANGE`, `value_geo{latitude, longitude, distance}` | distance in metres | `distance >= 0` |

**`FilterById`:** `equal`, `notEqual`, `containsAny`, `containsNone` only. Values go through `Uuid::valid()`, the port of `get_valid_uuid`. It accepts a UUID string, `UuidInterface`, or a Weaviate beacon or href URL (`weaviate://localhost/<uuid>`, `http://…/v1/objects/<uuid>`), from which the uuid is extracted. Anything else throws.

**`FilterByTime`** (`byCreationTime` / `byUpdateTime`): `equal`, `notEqual`, `lessThan`, `lessOrEqual`, `greaterThan`, `greaterOrEqual` (each takes `\DateTimeInterface`), plus `containsAny` and `containsNone` (each takes `list<\DateTimeInterface>`). Needs `indexTimestamps` on the collection.

**`FilterByCount`** (`byRefCount`): `equal`, `notEqual`, `lessThan`, `lessOrEqual`, `greaterThan`, `greaterOrEqual`, each taking an `int`.

`length: true` needs `indexPropertyLength`. `len()` filters take ints.

### 5.4 Value → wire oneof (`Filters.test_value`)

| PHP value | Wire field | Notes |
|---|---|---|
| `bool` | `value_boolean` | Checked **before** int |
| `int` | `value_int` (int64) | See the gotcha below |
| `float` | `value_number` (double) | |
| `string` | `value_text` | Also correct for date and uuid properties given as strings |
| `\DateTimeInterface` | `value_text` = `format('Y-m-d\TH:i:s.uP')` | Python uses `isoformat(timespec="microseconds")` and assumes UTC for naive datetimes. PHP datetimes always carry a timezone |
| `UuidInterface` | `value_text` = `toString()` | |
| `list<bool>` | `value_boolean_array` | |
| `list<int>` | `value_int_array` | |
| `list<int\|float>` with at least one float | `value_number_array` | **PHP deviation:** Python picks the type from the first element only, so `[1, 2.5]` fails there. PHP promotes a mixed int/float list to number |
| `list<string\|UuidInterface\|DateTimeInterface>` | `value_text_array` | Each element is converted as in the scalar rows. Mixing types across these three is allowed, because they all become text |
| `GeoCoordinate` + distance | `value_geo` | Only through `withinGeoRange` |
| other or mixed lists (for example bool with int) | — | `InvalidInputException` |

**Gotcha: int vs float for `number` properties.** Like Python, PHP sends an `int` as `value_int`. The server is believed to reject `valueInt` on a `number` property (the REST/GraphQL behaviour; not verified for gRPC, see §16). The client can't know the schema without I/O, so the docs tell users to cast (`->equal(5.0)`). A PHP-only `Filter::byProperty('price')->equal(5)` does **not** auto-convert.

**64-bit PHP is required** for int64 filter values and results (see ADR 0003).

### 5.5 Combining filters: the PHP chaining design

PHP has no operator overloading, so Python's `&`, `|` and `~` map to methods on the `FilterExpression` base class. Every leaf operator and every combinator returns a `FilterExpression`.

| Python | PHP | Result |
|---|---|---|
| `a & b` | `$a->and($b)` | `FilterAnd([$a, $b])` |
| `a \| b` | `$a->or($b)` | `FilterOr([$a, $b])` |
| `~a` | `$a->not()` or `Filter::not($a)` | `FilterNot($a)` |
| `a & b & c` | `$a->and($b)->and($c)` | `FilterAnd([FilterAnd([$a,$b]), $c])`, **left-nested exactly like Python**, so wire snapshots match. For a flat node use `Filter::allOf([$a, $b, $c])` |
| `Filter.all_of([...])` / `any_of` / `not_` | `Filter::allOf([...])` / `anyOf` / `not` | |

- `and`, `or` and `not` are legal method names in PHP 7.0 and later.
- `Filter::not()` (static on the factory) and `FilterExpression::not()` (instance) live on different classes, so there's no clash.
- `and()` and `or()` also take variadics (`$a->and($b, $c)`), which build a single flat node. This is a PHP convenience.

Wire for combinators: `Filters{operator: AND|OR|NOT, filters: [children…]}`, with no value and no target. `NOT` has exactly one child.

Filters are immutable and have no identity, so the same `FilterExpression` can be reused across queries, `deleteMany` and aggregate.

---

## 6. Return shaping

### 6.1 `returnProperties` semantics (from `__parse_return_properties` / `_translate_properties_from_python_to_grpc`)

| Value | `PropertiesRequest` sent | Object `properties` in the result |
|---|---|---|
| `null` or `true`, with no `returnReferences` | **not sent** (the server default: all non-reference, non-blob properties) | all returned |
| `null` or `true`, with `returnReferences` | `return_all_nonref_properties = true`, plus `ref_properties` | all non-reference properties |
| `false` or `[]` | `non_ref_properties = []`, `return_all_nonref_properties = false` | `[]` (not parsed) |
| `'title'` / `QueryNested` / list | `non_ref_properties = [strings]`, `object_properties = [QueryNested…]`, `return_all_nonref_properties = false` | only those |

- Duplicates are removed while keeping first-seen order. Python uses a `set`, so its order isn't defined; order has no semantic effect.
- Blob properties are only returned when named explicitly.

### 6.2 `returnReferences`

Each `QueryReference` becomes one `RefPropertiesRequest` (§4.13). The rules nest recursively. Reference objects are always parsed with metadata, properties, references and vectors enabled (Python's `_QueryOptions(True, True, True, True, False)`).

### 6.3 Result object types (`Weaviate\Client\Result\…`)

The object type is named `WeaviateObject`, following [03 open question 3](03-api-design.md#open-design-questions) (`Object` is reserved).

```php
final readonly class WeaviateObject {           // Python Object
    public string $uuid;                        // from id_as_bytes (16 bytes, big-endian) → canonical lowercase string
    public array $properties;                   // array<string, mixed> (§6.4)
    public ?array $references;                  // ?array<string, CrossReference>; null when not requested
    public MetadataReturn $metadata;
    public array $vector;                       // array<string, list<float>|list<list<float>>>; [] when not requested
    public string $collection;                  // PropertiesResult.target_collection
}
final readonly class MetadataReturn {
    public ?\DateTimeImmutable $creationTime, $lastUpdateTime;
    public ?float $distance, $certainty, $score, $rerankScore;
    public ?string $explainScore;
    public ?bool $isConsistent;
}
final readonly class CrossReference { /** @var list<WeaviateObject> */ public array $objects; }
final readonly class QueryReturn { public array $objects; public ?QueryProfileReturn $queryProfile; }

final readonly class GroupByObject /* same fields as WeaviateObject */ {
    public GroupByMetadataReturn $metadata;    // only ?float $distance
    public string $belongsToGroup;
}
final readonly class Group {
    public string $name; public float $minDistance, $maxDistance; public int $numberOfObjects;
    public array $objects;                      // list<GroupByObject>
    public ?float $rerankScore;
}
final readonly class GroupByReturn {
    public array $objects;                      // all GroupByObjects, flattened in group order
    public array $groups;                       // array<string, Group> keyed by group name
    public ?QueryProfileReturn $queryProfile;
}
final readonly class ObjectSingleReturn /* WeaviateObject fields, metadata = MetadataSingleObjectReturn */ {}

final readonly class QueryProfileReturn { /** @var list<ShardProfileReturn> */ public array $shards; }
final readonly class ShardProfileReturn { public string $name, $node; /** @var array<string, SearchProfileReturn> */ public array $searches; }
final readonly class SearchProfileReturn { /** @var array<string,string> */ public array $details; } // e.g. 'total_took' => '1.2ms'
```

Metadata extraction (from `MetadataResult`): each field is set **only when** its `*_present` flag is true (`distance_present`, `certainty_present`, `score_present`, `explain_score_present`, `is_consistent_present`, `rerank_score_present`, `creation_time_unix_present`, `last_update_time_unix_present`). Otherwise it's `null`. Metadata is parsed only when `returnMetadata` or `rerank` was given; otherwise it's an empty `MetadataReturn`.

- **Timestamps:** if the integer has 13 digits or fewer it's milliseconds; otherwise it's nanoseconds (Python issue #958). The result is `DateTimeImmutable` in UTC.
- **Group-by rerank:** use `hasRerank()`. Python reads `res.rerank.score` unconditionally, which yields `0.0` rather than `None`; PHP returns `null` when absent.
- **Group names:** PHP arrays turn numeric-string keys (`"2020"`) into int keys. `$groups['2020']` still works, but `array_keys()` returns ints. `Group::$name` is always the original string, and the docs say so.
- **`queryProfile`** is populated when `SearchReply.query_profile` is present. Search keys seen in tests are `object`, `vector` and `keyword`.

### 6.4 Property value decoding (`properties.proto` `Value` → PHP)

| Wire `Value.kind` | PHP | Notes |
|---|---|---|
| `text_value` | `string` | |
| `int_value` | `int` | |
| `number_value` | `float` | |
| `bool_value` | `bool` | |
| `date_value` | `\DateTimeImmutable` | RFC 3339 with up to 9 fractional digits, truncated to 6. An empty string logs a warning and gives `null`. Year 0 logs a warning and is returned as-is, because PHP supports it; Python returns `datetime.min` |
| `uuid_value` | `string` | Python returns `uuid.UUID`. PHP returns a string, or `UuidInterface` when `AdditionalConfig::uuidObjects` is on and ramsey/uuid is installed |
| `geo_value` | `GeoCoordinate` | |
| `phone_value` | `PhoneNumber` (output) | `number` (from `input`), `countryCode`, `defaultCountry`, `internationalFormatted`, `national`, `nationalFormatted`, `valid` |
| `blob_value` | `string` (base64) | |
| `object_value` | `array<string, mixed>` (recursive) | |
| `list_value.text_values` / `bool_values` | `list<string>` / `list<bool>` | |
| `list_value.int_values` | `list<int>` | Packed **int64 little-endian** bytes: `unpack('q*')` on an LE host, or `P*` plus sign fix-up. Use a portable decoder |
| `list_value.number_values` | `list<float>` | Packed **float64 little-endian**: `unpack('e*')` |
| `list_value.date_values` / `uuid_values` | `list<DateTimeImmutable>` / `list<string>` | |
| `list_value.object_values` | `list<array>` | |
| `null_value` | `null` | |
| unknown | `null` + a logged warning | Like Python `_Warnings.unknown_type_encountered` |

### 6.5 Vector decoding (`MetadataResult` → `$obj->vector`)

1. If `vector_bytes` is non-empty (legacy unnamed vector), the result is `['default' => unpack('g*')]` (float32 little-endian).
2. Otherwise, for each `Vectors` entry in `vectors`:
   - `VECTOR_TYPE_MULTI_FP32` decodes as multi (step 3).
   - `VECTOR_TYPE_SINGLE_FP32` or `UNSPECIFIED` decodes as single float32.

   The result is keyed by `name`.
3. **Multi-vector byte format:** the first 2 bytes are a `uint16` LE dimension `d` (`unpack('v')`), followed by `n·d` float32 LE values. Split them into `n` rows of `d` values.
4. If nothing is present, the result is `[]`.

Encoding (queries) is the reverse: single is `pack('g*', ...$v)`, and multi is `pack('v', count($v[0])) . pack('g*', ...array_merge(...$v))`. **Always use explicit little-endian codes (`g`, `e`, `v`).** Python's `struct.pack("f")` uses native order, which is LE on every supported platform.

---

## 7. Vector inputs (`nearVector`, `hybrid(vector:)`)

### 7.1 Shape detection

PHP uses `array_is_list()` (PHP 8.1 and later) in place of Python's duck-typed `_is_1d_vector`/`_is_2d_vector`:
- **1-D:** a non-empty list whose first element is `int|float`.
- **2-D:** a non-empty list whose first element is a non-empty list of numbers.
- **Map:** a non-list array with string keys.

Anything else, including an empty list, throws `InvalidInputException`. Python accepts numpy, pandas, polars and TensorFlow arrays; PHP accepts any `iterable` of numbers, `\SplFixedArray` included, and converts it to a list.

### 7.2 `nearVector` wire (`_parse_near_vector`)

With the 1.29 floor ([ADR 0004](decisions/0004-server-version-floor.md)), PHP **only** implements the left column. The right column records Python's legacy behaviour for reference and is not ported.

| Input | Server 1.29 and later (PHP) | Server 1.27–1.28 (Python only) |
|---|---|---|
| 1-D | `NearVector.vectors = [Vectors{vector_bytes=single, type=SINGLE_FP32}]` | `NearVector.vector_bytes = single` |
| 2-D (multi-vector) | `NearVector.vectors = [Vectors{vector_bytes=multi, type=MULTI_FP32}]` | Throws ("appears to be a nested list of embeddings…"). Multi-vector needs 1.29 |
| map `name => 1-D` | `vector_for_targets += VectorForTarget{name, vectors=[Vectors{name, single, SINGLE}]}` | `VectorForTarget{name, vector_bytes=single}` |
| map `name => 2-D` | `VectorForTarget{name, vectors=[Vectors{name, multi, MULTI}]}` | each row is added as a separate 1-D `VectorForTarget` with the same name |
| map `name => ListOfVectorsQuery(1-D)` | `VectorForTarget{name, vectors=[Vectors{name, multi-packed rows, MULTI}]}` | each vector is a separate `VectorForTarget` (name repeated) |
| map `name => ListOfVectorsQuery(2-D)` | `VectorForTarget{name, vectors=[one MULTI Vectors per multi-vector]}` | Throws ("Invalid list of vectors") |

For maps, `targets`/`target_vectors` are then recomputed from the emitted names (§4.3 reordering). A map without `targetVector` throws. `certainty`, `distance`, `targets` and `selection` are set alongside.

### 7.3 `hybrid(vector:)` wire (`_parse_hybrid`)

| Input | Wire |
|---|---|
| `null` | nothing (the query is vectorized) |
| 1-D list of **floats** | `Hybrid.vector_bytes = single` (the deprecated field, still used by Python on every version; mirrored exactly) |
| 1-D list containing ints | the general path, which yields the same `vector_bytes`. PHP casts to float and takes the fast path |
| 2-D, server 1.29 and later | `Hybrid.vectors = [Vectors{multi, MULTI_FP32}]` |
| map | `Hybrid.near_vector = NearVector{vector_for_targets…}`, with targets recomputed (§7.2 map rows) |
| `HybridVector::nearText(...)` | `Hybrid.near_text = NearTextSearch{…}` |
| `HybridVector::nearVector($v, …)` | `Hybrid.near_vector = NearVector{vector_bytes (1-D) \| vector_for_targets (map), certainty, distance}`. A 2-D input here throws, as in Python. Pass a map to use multi-vector sub-searches |

---

## 8. Generate (RAG): `$col->generate->*`

### 8.1 Extra arguments (on every `generate` method, right after the primary input)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `single_prompt` | `singlePrompt` | `string\|SinglePrompt\|null` | `null` | Per-object prompt. `{prop}` placeholders are interpolated by the server |
| `grouped_task` | `groupedTask` | `string\|GroupedTask\|null` | `null` | One generation over all results (or per group with `groupBy`) |
| `grouped_properties` | `groupedProperties` | `?list<string>` | `null` | Properties fed to the grouped task. Only used when `groupedTask` is a string |
| `generative_provider` | `generativeProvider` | `?GenerativeConfigRuntime` | `null` | A per-query provider or model override (§8.3). `null` uses the collection's `generativeConfig` |

Validation (PHP, before I/O):
- **Both `singlePrompt` and `groupedTask` null** throws `InvalidInputException("generate requires singlePrompt and/or groupedTask")`. Python sends an empty `GenerativeSearch`. This is a PHP addition.
- `groupedTask` as a `GroupedTask` object together with `groupedProperties` throws. Python silently ignores `grouped_properties`; use `GroupedTask(nonBlobProperties:)`.
- `SinglePrompt`/`GroupedTask` with `images`, `imageProperties` or `metadata: true` but **no `generativeProvider`** throws. These options live inside the provider message on the wire, so Python silently drops them.
- A provider that doesn't support images, given `images`/`imageProperties`, throws (§8.3, the "Images" column). This mirrors `_validate_multi_modal`.

### 8.2 `GenerativeParameters`

| Python | PHP | Fields |
|---|---|---|
| `GenerativeParameters.single_prompt(prompt, *, image_properties=None, images=None, metadata=False, debug=False)` | `GenerativeParameters::singlePrompt(string $prompt, ?array $imageProperties = null, mixed $images = null, bool $metadata = false, bool $debug = false)` → `SinglePrompt` | `images`: one blob or an iterable of blobs (§4.14), each encoded to base64. `metadata`: return provider usage metadata. `debug`: return the full rendered prompt |
| `GenerativeParameters.grouped_task(prompt, *, non_blob_properties=None, image_properties=None, images=None, metadata=False)` | `GenerativeParameters::groupedTask(string $prompt, ?array $nonBlobProperties = null, ?array $imageProperties = null, mixed $images = null, bool $metadata = false)` → `GroupedTask` | `nonBlobProperties` becomes `Grouped.properties` |

- `imageProperties` names **blob properties** of the retrieved objects to send as images.
- `images` are **external** images supplied with the query.
- The proto also has `Grouped.debug`, which Python doesn't expose. PHP leaves it out until Python adds it, as a parity rule. Tracked in §16.

### 8.3 `GenerativeConfig` providers (a per-query `generativeProvider`)

All parameters are optional unless marked, and default to `null`, which means the server or module default. URL parameters are validated as `http`/`https` URLs (Python uses pydantic `AnyHttpUrl`) and have trailing `/` stripped before sending.

| Python factory | PHP factory | Parameters (PHP name: type → proto field) | Images | Proto oneof |
|---|---|---|---|---|
| `anthropic` | `GenerativeConfig::anthropic()` | `baseUrl`: url → `base_url`; `model`: string; `maxTokens`: int; `stopSequences`: list<string> → `stop_sequences`; `temperature`: float; `topK`: int → `top_k`; `topP`: float → `top_p` | ✓ | `anthropic` |
| `anyscale` | `anyscale()` | `baseUrl`, `model`, `temperature` | ✗ | `anyscale` |
| `aws_bedrock` | `awsBedrock()` | `endpoint`: url; `maxTokens`; `model`; `region`; `temperature`; `stopSequences`; (`service = "bedrock"` fixed) | ✓ | `aws` |
| `aws_sagemaker` | `awsSagemaker()` | `endpoint`: url; `maxTokens`; `region`; `targetModel` → `target_model`; `targetVariant` → `target_variant`; `temperature`; `stopSequences`; (`service = "sagemaker"`, no `model`) | ✓ | `aws` |
| `aws` (**deprecated**, "removed after Q3 '26") | **not ported** | Use `awsBedrock`/`awsSagemaker` | | |
| `cohere` | `cohere()` | `baseUrl`; `frequencyPenalty`: float; `k`: int; `maxTokens`; `model`; `p`: float; `presencePenalty`: float; `stopSequences`; `temperature` | ✓ | `cohere` |
| `contextualai` | `contextualAI()` | `model`; `maxNewTokens`: int → `max_new_tokens`; `temperature`; `topP`; `systemPrompt`: string; `avoidCommentary`: bool (null is sent as `false`, like Python); `knowledge`: list<string> | ✗ | `contextualai` |
| `databricks` | `databricks()` | **`endpoint`: url (required)**; `frequencyPenalty`; `logProbs`: bool (null is sent as `false`, like Python); `maxTokens`; `model`; `n`: int; `presencePenalty`; `stop`: list<string>; `temperature`; `topLogProbs`: int; `topP` | ✗ | `databricks` |
| `deepseek` | `deepseek()` | `baseUrl`; `model`; `temperature`; `maxTokens`; `frequencyPenalty`; `presencePenalty`; `topP`; `stop` | ✗ | `deepseek` |
| `digitalocean` | `digitalOcean()` | `baseUrl`; `model`; `temperature`; `topP`; `maxTokens`; `frequencyPenalty`; `presencePenalty`; `stop` | ✗ | `digitalocean` |
| `dummy` | `dummy()` | (none, for tests) | ✗ | `dummy` |
| `friendliai` | `friendliAI()` | `baseUrl`; `maxTokens`; `model`; `n`; `temperature`; `topP` | ✗ | `friendliai` |
| `google_vertex` | `googleVertex()` | `apiEndpoint`: url (the scheme is **stripped** before sending); `projectId`; `endpointId`; `region`; `frequencyPenalty`; `maxTokens`; `model`; `presencePenalty`; `temperature`; `topK`; `topP`; `stopSequences`; `location` (the `locations/<x>` path segment; `"global"` selects the region-less host for gemini) | ✓ | `google` |
| `google_gemini` | `googleGemini()` | `frequencyPenalty`; `maxTokens`; `model`; `presencePenalty`; `temperature`; `topK`; `topP`; `stopSequences`; (`apiEndpoint = generativelanguage.googleapis.com` fixed) | ✓ | `google` |
| `google` (**deprecated**, "removed after Q3 '26") | **not ported** | Use `googleVertex`/`googleGemini` | | |
| `meta` | `meta()` | `baseUrl`; `model`; `temperature`; `topP`; `maxTokens`; `frequencyPenalty`; `presencePenalty`; `reasoningEffort`: `MetaReasoningEffort\|string` (`none`, `minimal`, `low`, `medium`, `high`, `xhigh` → `REASONING_EFFORT_*`) | ✓ | `meta` |
| `mistral` | `mistral()` | `baseUrl`; `maxTokens`; `model`; `temperature`; `topP` | ✗ | `mistral` |
| `nvidia` | `nvidia()` | `baseUrl`; `maxTokens`; `model`; `temperature`; `topP` | ✗ | `nvidia` |
| `ollama` | `ollama()` | `apiEndpoint`: url → `api_endpoint` (in Docker you may need `http://host.docker.internal:11434`); `model`; `temperature` | ✓ | `ollama` |
| `openai` | `openAI()` | `apiVersion`; `baseUrl`; `deploymentId`; `frequencyPenalty`; `maxTokens`; `model`; `n`; `presencePenalty`; `reasoningEffort`: `OpenAIReasoningEffort\|string` (`minimal`, `low`, `medium`, `high`); `resourceName`; `stop`; `temperature`; `topP`; `verbosity`: `OpenAIVerbosity\|string` (`low`, `medium`, `high`); (`is_azure = false`) | ✓ | `openai` |
| `azure_openai` | `azureOpenAI()` | `apiVersion`; `baseUrl`; `deploymentId`; `frequencyPenalty`; `maxTokens`; `model`; `n`; `presencePenalty`; `resourceName`; `stop`; `temperature`; `topP`; (`is_azure = true`, no reasoning or verbosity) | ✓ | `openai` |
| `xai` | `xAI()` | `baseUrl`; `maxTokens`; `model`; `temperature`; `topP` | ✓ | `xai` |

Notes:
- Python's `aws_bedrock`/`aws_sagemaker` accept `top_k`/`top_p` but **never send them**, because `GenerativeAWS` in the proto has no such fields (there's a TODO in the source). PHP **leaves these parameters out** until the proto gains them, so they don't silently do nothing.
- String enum values are validated client-side. An invalid `reasoningEffort` or `verbosity` throws `InvalidInputException`, like Python.
- Wire: `GenerativeProvider{return_metadata: <SinglePrompt|GroupedTask>.metadata, <oneof>: {…, images: TextArray?, image_properties: TextArray?}}`. `images`/`image_properties` are only set for providers marked ✓. Python sends at most one provider per `Single`/`Grouped` (`queries` is repeated in the proto, "only allow one at the beginning").
- The module must be enabled on the server. Unknown or disabled providers are a server error.

### 8.4 `GenerativeSearch` wire (`_Generative.to_grpc`)

**Wire on every supported server (1.29 and later):**

| Input | Wire |
|---|---|
| `singlePrompt: 'text'` | `single = Single{prompt, queries = provider ? [provider(opts: defaults)] : []}` |
| `singlePrompt: SinglePrompt` | `single = Single{prompt, debug, queries = provider ? [provider(metadata, images, imageProperties)] : []}` |
| `groupedTask: 'text'` | `grouped = Grouped{task, properties = TextArray(groupedProperties)?, queries = provider ? [provider()] : []}` |
| `groupedTask: GroupedTask` | `grouped = Grouped{task, properties = TextArray(nonBlobProperties)?, queries = provider ? [provider(metadata, images, imageProperties)] : []}` |

**Servers 1.27.0–1.27.13 (legacy, Python only; not ported because of the 1.29 floor):**
- `generativeProvider` set throws `UnsupportedFeatureException("Dynamic RAG", version, "1.27.14")`.
- Otherwise the client sends the deprecated `single_response_prompt`, `grouped_response_task` and `grouped_properties` (`nonBlobProperties` for a `GroupedTask`).
- **PHP deviation:** `SinglePrompt`/`GroupedTask` with `debug`, `metadata` or images throws `UnsupportedFeatureException` on these servers. Python drops them silently.

### 8.5 Generative results

```php
final readonly class GenerativeObject /* WeaviateObject fields */ { public ?GenerativeSingle $generative; }
final readonly class GenerativeSingle  { public ?string $text; public ?GenerativeDebug $debug; public ?GenerativeMetadata $metadata; }
final readonly class GenerativeGrouped { public ?string $text; public ?GenerativeMetadata $metadata; }
final readonly class GenerativeDebug   { public ?string $fullPrompt; }
final readonly class GenerativeReturn  { public array $objects; public ?GenerativeGrouped $generative; public ?QueryProfileReturn $queryProfile; }

final readonly class GenerativeGroup /* Group fields */ { public ?GenerativeGrouped $generative; }
final readonly class GenerativeGroupByObject /* GroupByObject fields */ { public ?GenerativeSingle $generative; }
final readonly class GenerativeGroupByReturn { public array $objects; public array $groups; public ?GenerativeGrouped $generative; public ?QueryProfileReturn $queryProfile; }
```

Mapping:

| PHP | Source (read the new field first, then fall back to the deprecated one) |
|---|---|
| `$obj->generative` | `SearchResult.generative.values[0]` → `{text: result, debug: debug.full_prompt !== '' ? … : null, metadata}`. `null` if `values` is empty |
| `$res->generative` (grouped) | `SearchReply.generative_grouped_results.values[0]` → `{text, metadata}`. Falls back to the deprecated `generative_grouped_result` string (`metadata = null`) |
| `$group->generative` | `GroupByResult.generative_result.values[0]`. Falls back to the deprecated `GroupByResult.generative.result` |
| `$groupByRes->generative` | as `$res->generative` |
| `$groupObj->generative` | `SearchResult.generative` inside the group. **PHP addition**: Python's group-by parser drops this; server support is unverified (§16) |

- The deprecated Python `generated` string attributes (`GenerativeObject.generated`, `GenerativeReturn.generated`) are **not ported**. Only `generative` exists.
- Python's group-by `generated` (not deprecated there) maps to `generative->text` in PHP.

**`GenerativeMetadata`** is a sealed family, one final class per provider shape, with `provider: string`:

| Class | Providers | Fields |
|---|---|---|
| `AnthropicMetadata` | anthropic | `usage: {inputTokens, outputTokens}` |
| `UsageMetadata` | openai, mistral, databricks, friendliai, nvidia, xai, deepseek, digitalocean, meta | `usage: ?{promptTokens, completionTokens, totalTokens}` (each `?int`) |
| `CohereMetadata` | cohere | `apiVersion: ?{version, isDeprecated, isExperimental}`, `billedUnits: ?{inputTokens, outputTokens, searchUnits, classifications}` (floats), `tokens: ?{inputTokens, outputTokens}`, `warnings: ?list<string>` |
| `GoogleMetadata` | google | `metadata: ?{tokenMetadata: {inputTokenCount, outputTokenCount: {totalBillableCharacters, totalTokens}}}`, `usageMetadata: ?{promptTokenCount, candidatesTokenCount, totalTokenCount}` |
| `EmptyMetadata` | anyscale, aws, dummy, ollama | (none) |

Python's `__extract_generative_metadata` has no branch for **xai** or **deepseek**, so it returns `None` for them. PHP handles every oneof case in `GenerativeMetadata`. The proto has no contextualai metadata message.

---

## 9. Iterator (`$col->iterator()`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `include_vector` | `includeVector` | `bool` | `false` | Python types this as `bool` only. PHP also accepts `string\|list<string>`, as in §2 |
| `return_metadata` | `returnMetadata` | `MetadataQuery\|list<string>\|null` | `null` | |
| `return_properties` | `returnProperties` | as §2 | `null` | |
| `return_references` | `returnReferences` | as §2 | `null` | |
| `after` | `after` | `string\|UuidInterface\|null` | `null` | Starting cursor |
| `cache_size` | `cacheSize` | `?int` | `null` (100) | Page size. `null` or `0` means 100 (Python `cache_size or 100`). Negative throws |

Behaviour (a port of `_ObjectIterator`):
- It returns `ObjectIterator implements \IteratorAggregate`. `getIterator()` is a `Generator` that:
  - calls `query->fetchObjects(limit: cacheSize, after: lastUuid, …)` whenever the buffer is empty,
  - yields `uuid => WeaviateObject`,
  - sets `lastUuid` to each yielded uuid,
  - stops when a page is empty.
- **It can be iterated again.** Each `foreach` restarts from the original `after`, as Python's `__iter__` resets.
- There's **no `filters` or `sort`**: the server's cursor (`after`) API doesn't support them.
- Tenant and consistency level come from the handle.
- Errors propagate as `QueryException` mid-iteration.
- Yielding `uuid` keys means `iterator_to_array($it)` builds a uuid-keyed map. Pass `preserve_keys: false` for a list.
- The async package yields the same items from an async generator.

---

## 10. `SearchRequest` wire summary

| Field | Set from |
|---|---|
| `collection` | handle name |
| `tenant` | `withTenant()`; `""` when unset |
| `consistency_level` | `withConsistencyLevel()`: `One` → `CONSISTENCY_LEVEL_ONE`, `Quorum` → `_QUORUM`, `All` → `_ALL`; unset otherwise |
| `properties` | §6.1 / §6.2 |
| `metadata` | §4.1. **Always sent** (every executor passes `_MetadataQuery`, so `uuid = true` at minimum) |
| `group_by` | §4.9 |
| `limit`, `offset`, `autocut`, `after` | §2 / §3.1 (`after` sent as `""` when null) |
| `sort_by` | §4.12 |
| `filters` | §5 |
| `hybrid_search` / `bm25_search` / `near_vector` / `near_object` / `near_text` / `near_image` / `near_audio` / `near_video` / `near_depth` / `near_thermal` / `near_imu` | §3 (at most one) |
| `generative` | §8.4 (generate namespace only) |
| `rerank` | §4.8 |
| `boost` | §4.11 |
| `uses_123_api` | `true` (deprecated, still sent by Python) |
| `uses_125_api` | `true` (always, given the 1.29 floor) |
| `uses_127_api` | `true` (always, given the 1.29 floor) |

The call is gRPC `Search` with the `query` timeout ([09 §4.1](09-connection.md#41-timeout-seconds-all--0)). A non-OK status becomes `QueryException` (`grpcStatus`, `message`), matching Python's `WeaviateQueryError`.

---

## 11. Validation rules (consolidated; all throw `InvalidInputException` before I/O unless noted)

| Rule | Source |
|---|---|
| `certainty` and `distance` both set | **PHP addition** ([03](03-api-design.md) rule). Python sends both and the server rejects them |
| `limit`, `offset`, `autoLimit` outside uint32 (negative) | PHP addition. Python only type-checks |
| `alpha` outside `[0, 1]` | PHP addition |
| `Move` with neither objects nor concepts | Python |
| `Move::force` outside `[0, 1]` | PHP addition † |
| Filter `equal`/`notEqual`/comparisons with an empty list | Python |
| `containsAny`/`containsAll`/`containsNone` with an empty list | Python |
| `allOf`/`anyOf` with an empty list | Python |
| Invalid uuid in `byId()`, `nearObject`, `after`, `fetchObjectById` | Python (`get_valid_uuid`) |
| `GeoCoordinate` out of range; negative geo distance | Python (pydantic); distance is a PHP addition |
| A filter list with incompatible element types | PHP (Python fails later, or sends the wrong type) |
| Vector input empty or of the wrong shape; map keys ≠ targets; a map without `targetVector` | Python |
| `ListOfVectorsQuery` with no vectors | Python |
| `GroupBy` counts < 1 | PHP addition |
| `MMR` limit < 1 or > query limit; balance outside `[0, 1]` | PHP addition (the server enforces the limit) |
| `Boost` rules (§4.11) | Python (blend empty, sub-depth) plus PHP (ranges, 20 conditions) |
| `BM25Operator::or(minimumMatch < 1)` | PHP addition |
| Unknown `returnMetadata` name | Python (pydantic) |
| `returnProperties`/`returnReferences` of the wrong type | Python. PHP enforces it through native types |
| Generate: neither prompt; `GroupedTask` + `groupedProperties`; images/metadata without a provider; images for a provider without image support | Python (images/provider) plus PHP (the rest) |
| Provider URL not http(s); invalid reasoning or verbosity value | Python |
| `cacheSize < 0` | PHP addition |
| Blob input of an unsupported type | Python |

Python's `skip_argument_validation` handle option turns off only **type** checks there. PHP relies on native types for those, so every rule above always runs.

---

## 12. Version gates

The PHP floor is **1.29.0** ([ADR 0004](decisions/0004-server-version-floor.md)). Gates throw `UnsupportedFeatureException(feature, serverVersion, minVersion)` before I/O, using the version cached at `connect()`.

| Feature | Min server | Gate in Python? | PHP |
|---|---|---|---|
| `BM25Operator::or`/`and` | 1.31.0 | no (tests skip below 1.31) | **gate** (old servers would silently ignore the field) |
| `Filter …->containsNone()` | 1.33.0 | no (tests) | **gate** |
| `Filter::not()` / `->not()` | † unverified (probably 1.33) | no | no gate until confirmed |
| Hybrid `alpha_param` encoding | 1.36.6 (Python TODO: "change to 1.36.7") | behavioural | same |
| `MetadataQuery(queryProfile: true)` | 1.36.9 | yes | gate |
| `diversitySelection` on near-* | 1.37.0 | no (docs and tests) | **gate** |
| `diversitySelection` on hybrid | 1.38.6 | no (docs and tests) | **gate** |
| `boost` | 1.38.0 | no (docs and tests) | **gate** |
| `BM25Operator::andCross()` | 1.37.15, 1.38.8 or 1.39.0 | yes (`is_at_least_any`) | gate, same rule |
| `generativeProvider` (1.27.14), multi-vector inputs and `Vectors` encoding (1.29.0), hybrid `maxVectorDistance` (1.26.3), multi-target (1.26), group-by (1.25), rerank (1.23.1) | at or below the floor | — | none |

The "**gate**" rows are PHP deviations. The rationale: an old server ignores unknown proto fields, so the user would get results that silently ignore what they asked for.

---

## 13. PHP design notes and Python quirks we don't copy

1. **Operators become methods** (§5.5). Left-nested `and()`/`or()` match Python's wire shape; variadic or `allOf` forms are flat.
2. **Immutable builders.** `Filter::byRef()` chains, `Sort` chains and `Boost::blend()` never mutate their inputs. Python's `_FilterByRef` and `_Sorting` mutate, which makes reusing a partial builder surprising.
3. **Q1:** Python's `query.near_vector()` parses its reply with `_result_to_generative_return`, so it returns `GenerativeReturn`/`GenerativeGroupByReturn` objects from the **query** namespace. PHP returns `QueryReturn`/`GroupByReturn`.
4. **Q2:** Python's group-by rerank score is `0.0` rather than `None` when absent. PHP returns `null` (§6.3).
5. **Q3:** Python's `include_vector="name"` (a string, allowed by `INCLUDE_VECTOR`) requests **no** vectors but still decodes them. PHP normalises it to `['name']`.
6. **Q4:** Python's generative metadata extractor misses xai and deepseek. PHP covers every oneof case.
7. **Q5:** Python's group-by generate ignores the new `generative_result` and `generative_grouped_results` fields and per-object generative results. PHP reads new fields first, then deprecated ones.
8. **Q6:** For the `aws_*` providers, Python silently drops `top_k`/`top_p`. PHP leaves them out.
9. **Q7:** Python drops `images`/`metadata` without a provider, and drops them on 1.27.0–1.27.13. PHP throws.
10. **Deprecated APIs not ported:** `GenerativeConfig.aws`, `GenerativeConfig.google`, `GenerativeObject.generated`, `GenerativeReturn.generated`, and the `Generate` pydantic class (unused by the v4 methods).
11. **Result naming.** Result classes keep Python's names (`QueryReturn`, `GroupByReturn`, `GenerativeReturn`, `MetadataReturn`, `ObjectSingleReturn`, …) so docs map 1:1. The one exception is `Object` → `WeaviateObject`.
12. **Big signatures.** The near-* methods take about 17 named arguments. They're kept flat, not bundled into an options object, so Python snippets translate mechanically ([03](03-api-design.md) "parity beats taste").
13. **Binary encoding** is centralised in `Transport\Mapper\VectorCodec` (`packSingle`, `packMulti`, `unpackSingle`, `unpackMulti`, `unpackInt64s`, `unpackFloat64s`). It's `@internal` and fuzz-tested against Python-generated fixtures.

---

## 14. Examples

```php
use Weaviate\Client\Query\{Filter, Sort, GroupBy, MetadataQuery, QueryReference, QueryNested, TargetVectors,
    HybridFusion, HybridVector, BM25Operator, Move, Rerank, NearVector, NearMediaType, Diversity, Boost, BoostCurve, GeoCoordinate};
use Weaviate\Client\Query\{GenerativeConfig, GenerativeParameters, Blob}; // Query/ namespace per 01 layout

$articles = $client->collections->use('Article');

// Filters: nested boolean logic, references, time and geo
$filter = Filter::byProperty('year')->greaterOrEqual(2020)
    ->and(Filter::byProperty('tags')->containsAny(['db', 'search']))
    ->and(Filter::anyOf([
        Filter::byRef('author')->byProperty('name')->like('Ana*'),
        Filter::byRefCount('author')->greaterThan(2),
    ]))
    ->and(Filter::not(Filter::byProperty('draft')->equal(true)));

$recent = Filter::byCreationTime()->greaterThan(new DateTimeImmutable('-7 days'));
$near   = Filter::byProperty('location')->withinGeoRange(new GeoCoordinate(52.37, 4.89), distance: 5000.0);
$multi  = Filter::byRefMultiTarget('writtenBy', 'Person')->byProperty('age', length: false)->lessThan(40);

// fetch + sort + references + nested props
$res = $articles->query->fetchObjects(
    limit: 20,
    filters: $filter,
    sort: Sort::byProperty('year', ascending: false)->byCreationTime(),
    returnProperties: ['title', new QueryNested('meta', ['source', 'lang'])],
    returnReferences: QueryReference::multiTarget(linkOn: 'writtenBy', targetCollection: 'Person', returnProperties: ['name']),
    returnMetadata: ['creationTime'],
);
foreach ($res->objects as $o) {
    echo $o->properties['title'], ' by ', $o->references['writtenBy']->objects[0]->properties['name'] ?? '?', PHP_EOL;
}

// near vector: multi-target with weights, one target gets two query vectors
$res = $articles->query->nearVector(
    nearVector: ['title_vec' => $v1, 'body_vec' => NearVector::listOfVectors($v2, $v3)],
    targetVector: TargetVectors::manualWeights(['title_vec' => 0.5, 'body_vec' => [0.3, 0.2]]),
    distance: 0.4,
    includeVector: ['title_vec'],
    returnMetadata: new MetadataQuery(distance: true),
);

// near text with moves, group-by, rerank, MMR diversity
$grouped = $articles->query->nearText(
    query: 'vector search',
    moveTo: new Move(force: 0.5, concepts: ['performance']),
    moveAway: new Move(force: 0.25, objects: $uuidToAvoid),
    groupBy: new GroupBy(prop: 'year', numberOfGroups: 3, objectsPerGroup: 2),
    rerank: new Rerank(prop: 'body', query: 'benchmarks'),
    diversitySelection: Diversity::mmr(limit: 5, balance: 0.7),
    limit: 20,
);
foreach ($grouped->groups as $group) { echo $group->name, ': ', $group->numberOfObjects, PHP_EOL; }

// hybrid: near-text sub-search, AND operator, threshold, query profile
$res = $articles->query->hybrid(
    query: 'fast vector database',
    alpha: 0.6,
    vector: HybridVector::nearText('fast vector database', distance: 0.5),
    queryProperties: ['title^2', 'body'],
    fusionType: HybridFusion::RelativeScore,
    bm25Operator: BM25Operator::or(minimumMatch: 2),
    maxVectorDistance: 0.6,
    targetVector: 'body_vec',
    returnMetadata: MetadataQuery::fullWithProfile(),
);
$res->queryProfile?->shards[0]->searches['keyword']->details['total_took'];

// bm25 with boost (recency blended with popularity)
$res = $articles->query->bm25(
    query: 'weaviate',
    operator: BM25Operator::and(),
    boost: Boost::blend([
        Boost::timeDecay('published', scale: new DateInterval('P7D'), curve: BoostCurve::Gaussian, weight: 2.0),
        Boost::numericProperty('views', modifier: \Weaviate\Client\Query\BoostModifier::Log1p),
    ], weight: 0.3, depth: 200),
);

// near media
$res = $articles->query->nearMedia(media: Blob::fromFile('/tmp/clip.wav'), mediaType: NearMediaType::Audio);

// iterator
foreach ($articles->iterator(returnProperties: ['title'], cacheSize: 500) as $uuid => $obj) { /* … */ }

// generate: per-object + grouped, provider override, images, metadata, debug
$res = $articles->generate->nearText(
    query: 'history of databases',
    singlePrompt: GenerativeParameters::singlePrompt('Summarise {title} in one line', debug: true, metadata: true),
    groupedTask: GenerativeParameters::groupedTask('Write a tweet', nonBlobProperties: ['title'], imageProperties: ['cover']),
    generativeProvider: GenerativeConfig::openAI(model: 'gpt-4o', temperature: 0.2, reasoningEffort: 'low'),
    limit: 3,
);
echo $res->generative?->text;
echo $res->objects[0]->generative?->text, $res->objects[0]->generative?->debug?->fullPrompt;
$usage = $res->objects[0]->generative?->metadata?->usage?->totalTokens;
```

---

## 15. Test checklist

**Unit tests** (golden protobuf bytes, compared against fixtures generated by the Python client for the same inputs, so wire parity is proven mechanically):
- Every method with defaults and with every argument set: assert the `SearchRequest`.
- `returnProperties` in all its forms (null, true, false, [], string, QueryNested, mixed list, duplicates), with and without references; nested references; multi-target references.
- `MetadataQuery` in all its forms, the list form in snake_case and camelCase, and the unknown-name error; `includeVector` as bool, string and list.
- Filters:
  - every operator × every value type (bool before int, float, int/float promotion, string, DateTimeImmutable in several timezones, UuidInterface, each list type);
  - empty-list errors; beacon URL ids; `length: true`;
  - by-ref chains 1–3 deep including multi-target and count; the time filters;
  - `and`/`or` nesting shape matches Python byte-for-byte; `allOf` with one element; `not` combos.
- Sort chains; GroupBy; Rerank; Move validation.
- Vectors:
  - 1-D, 2-D, maps with 1-D, 2-D and ListOfVectorsQuery (1-D and 2-D);
  - target reordering with weights (including list weights);
  - key-mismatch errors.
- Hybrid:
  - the alpha encoding either side of 1.36.6 (default 0.7 below it);
  - query null (alpha 1); query and vector both null (no hybrid_search);
  - every `vector` form; nearText and nearVector sub-searches; the threshold.
- BM25 operators and the andCross gate for 1.37.14, 1.37.15, 1.38.7, 1.38.8 and 1.39.0.
- Boost: every factory; duration and origin conversion; blend weight propagation; validation errors.
- Diversity: wire position for each search type; validation; gates.
- Generate:
  - every provider × every parameter; URL normalisation (trailing slash; the Google scheme strip);
  - the images and multimodal errors; string vs object prompts;
  - every validation error.
- Decoding:
  - every `Value` kind; packed int64 and float64 lists, negative ints included;
  - dates with 0, 3, 6 and 9 fractional digits and with `+02:00` offsets;
  - ms vs ns timestamps; the uuid from `id_as_bytes`;
  - single, multi and legacy `vector_bytes`; `*_present` flags;
  - ref_props_requested with no refs (`[]`) vs not requested (`null`).
- Generative decoding: new and deprecated fields, the fallback order, every metadata oneof (xai and deepseek included), debug present or absent.
- Iterator: paging across `cacheSize` boundaries, the `after` start, re-iteration restarts, an empty collection, and `cacheSize: 0` → 100.

**Integration tests** (compose matrix: 1.29.x, 1.31.x, 1.33.x, 1.36.9, 1.37.15, 1.38.8, latest):
- Each query method against `text2vec-model2vec` (or a self-provided vector), including named vectors and a multi-vector (ColBERT-style self-provided) collection.
- Filters on every data type, including `isNone` (indexNullState), `length` (indexPropertyLength), timestamps (indexTimestamps), geo, refs, ref count and multi-target refs.
- Group-by, including numeric group names → array keys; rerank with `reranker-dummy` †; autocut.
- Hybrid fusion types and `bm25Operator` (1.31 and later); andCross (1.37.15 and later); maxVectorDistance.
- Boost (1.38 and later), diversity (1.37 and later; hybrid 1.38.6 and later), query profile (1.36.9 and later).
- Gates: each gated feature against a server one version below throws `UnsupportedFeatureException` with no request sent (assert with a spy transport).
- Generate with `generative-dummy` (single, grouped, both, group-by), `generativeProvider: GenerativeConfig::dummy()`, debug, and metadata. Real providers run in a nightly job with secrets.
- Tenants and consistency level propagation; the iterator over 1,050 objects with cacheSize 100; `fetchObjectsByIds` with 0, 1 and 25 ids (checks the default-limit question in §16).

---

## 16. Not verified / open questions

1. **`Not` filter minimum server.** No gate or test was found in the Python source. It's probably 1.33.0, alongside `ContainsNone`. Confirm from the server changelog before adding a gate.
2. **`fetchObjectsByIds` default limit.** Python doesn't set `limit`. Check whether the server's default `QUERY_DEFAULTS_LIMIT` (believed to be 10) truncates results for more ids. If it does, adopt the §3.3 proposal (`limit = count(ids)`).
3. **int filter on a `number` property over gRPC.** Believed to be rejected, as in REST. Verify; if gRPC is lenient, drop the doc warning.
4. **Per-object generative results inside group-by.** Check whether the server fills `SearchResult.generative` for grouped objects (§8.5 PHP addition).
5. **Hybrid `alpha_param` switch version:** 1.36.6 in code, 1.36.7 in a Python TODO. Confirm with the server team.
6. **Server availability per generative provider** (for example xai, contextualai, deepseek, digitalocean and meta are recent). Not listed in the Python source; the server returns an error if the module is missing. Consider a per-provider gate table once confirmed.
7. **`Move.force` range** `[0, 1]` and the `MMR.limit`-is-required rule were taken from docs and docstrings, not server code.
8. **`Boost` curve `UNSPECIFIED` vs unset.** Python sets the optional enum to 0 explicitly. PHP mirrors that; check whether the server treats "unset" differently (it shouldn't).
9. **`Grouped.debug`** exists in the proto but not in Python. Add it to `GroupedTask` if Python does.
10. **Iterator + `after` + `filters`/`sort`.** The assumption is that the server rejects cursors combined with filters or sort. PHP doesn't validate this on `fetchObjects(after:, filters:)`; the server errors. Confirm and consider a client-side check.
11. `reranker-dummy` availability in the test images (†).
