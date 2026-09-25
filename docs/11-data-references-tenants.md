# 11: Data, references, multi-tenancy and the Collection handle

This maps **every** public method and value type in the Python v4 data, reference, tenant and consistency-level APIs to PHP, plus the `Collection` handle that carries them. The sources are the Python client's `main` branch, read on 2026-09-25:

- `weaviate/collections/collection/{base,sync}.py` (the `Collection` class)
- `weaviate/collections/data/executor.py` and `sync.pyi` (`collection.data`)
- `weaviate/collections/batch/{grpc_batch,grpc_batch_delete,rest}.py` (the wire format of `insert_many`, `delete_many` and `reference_add_many`)
- `weaviate/collections/classes/{data,types,internal,batch,tenants}.py`, `weaviate/types.py`, `weaviate/util.py`
- `weaviate/collections/tenants/executor.py`, `weaviate/collections/grpc/{tenants,shared}.py`
- `weaviate/connect/v4.py` (gRPC error mapping), `weaviate/retry.py`, `weaviate/exceptions.py`
- the protos `batch.proto`, `batch_delete.proto`, `base.proto`, `properties.proto` and `tenants.proto` from `weaviate/weaviate` `grpc/proto/v1` on `main`

Rows in [02 §3](02-feature-parity-matrix.md#3-data-collectiondata) and [02 §8](02-feature-parity-matrix.md#8-multi-tenancy-collectiontenants) link here. Batching (`collection.batch`, `client.batch`) has its own spec; this document only covers the parts of the batch wire format that `insertMany`, `deleteMany` and `referenceAddMany` share with it.

## 1. Namespaces

| Python import | PHP class | PHP namespace |
|---|---|---|
| `weaviate.collections.Collection` | `Collection` | `Weaviate\Client\Collections` |
| `wvc.data.DataObject` | `DataObject` | `Weaviate\Client\Data` |
| `wvc.data.DataReference`, `DataReference.MultiTarget` | `DataReference`, `DataReferenceMulti` | `Weaviate\Client\Data` |
| `wvc.data.ReferenceToMulti` | `ReferenceToMulti` | `Weaviate\Client\Data` |
| `wvc.data.GeoCoordinate`, `wvc.data.PhoneNumber` | `GeoCoordinate`, `PhoneNumber` | `Weaviate\Client\Data` |
| `_PhoneNumber` (output type, exported as `PhoneNumberType`) | `PhoneNumberOutput` | `Weaviate\Client\Data` |
| `wvc.config.ConsistencyLevel` | `enum ConsistencyLevel` | `Weaviate\Client\Config` |
| `wvc.tenants.Tenant`, `TenantCreate`, `TenantUpdate`, `TenantActivityStatus` | same names | `Weaviate\Client\Tenants` |
| `BatchObjectReturn`, `BatchReferenceReturn`, `ErrorObject`, `ErrorReference`, `BatchObject`, `BatchReference`, `DeleteManyReturn`, `DeleteManyObject` | same names | `Weaviate\Client\Result` |
| `weaviate.util.generate_uuid5` | `generateUuid5()` function and `Uuid::generate5()` | `Weaviate\Client\Util` |

## 2. The `Collection` handle

### 2.1 Construction

`$client->collections->use('Article')` and `->get('Article')` return a `Collection`. They do **no I/O** ([03](03-api-design.md#conventions)). Like Python's `_CollectionBase`, the constructor **capitalizes the first letter** of the name (`article` → `Article`, only the first character is touched). PHP uses the capitalized name everywhere (REST paths, gRPC `collection` fields, beacons). Python passes the raw name to `data` and the capitalized one elsewhere; the server normalizes, so this doesn't change behaviour.

```php
final class Collection implements \Countable
{
    public readonly string $name;
    public readonly Data $data;
    public readonly Query $query;
    public readonly Generate $generate;
    public readonly Aggregate $aggregate;
    public readonly CollectionBatch $batch;
    public readonly Tenants $tenants;
    public readonly Config $config;
    public readonly CollectionBackup $backup;

    public function tenant(): ?string;
    public function consistencyLevel(): ?ConsistencyLevel;

    public function withTenant(string|Tenant|null $tenant): static;
    public function withConsistencyLevel(?ConsistencyLevel $consistencyLevel): static;

    public function length(): int;          // I/O: aggregate total count
    public function count(): int;           // \Countable, same as length()
    public function exists(): bool;         // I/O: GET /v1/schema/{name}
    public function shards(): array;        // I/O: GET /v1/nodes, list<Shard>
    public function iterator(...): \Generator; // I/O: gRPC Search pages
}
```

The namespace objects (`data`, `query`, …) are built lazily on first access and cached per handle, so a handle that's only used for `data` never builds a `Generate`.

### 2.2 Members

| Python | PHP | Notes |
|---|---|---|
| `collection.name` | `$col->name` | Capitalized first letter |
| `collection.tenant` (property) | `$col->tenant(): ?string` | A method, not a property, so it can't be confused with `->tenants` |
| `collection.consistency_level` (property) | `$col->consistencyLevel(): ?ConsistencyLevel` | |
| `collection.data` / `.query` / `.generate` / `.aggregate` / `.batch` / `.tenants` / `.config` / `.backup` | same, as readonly properties | `tenants`, `backup` and `config` ignore the handle's tenant for their own calls (see §3) |
| `with_tenant(tenant: str \| Tenant)` | `withTenant(string\|Tenant\|null $tenant): static` | No I/O. Returns a clone. `null` clears the tenant (PHP addition; Python has no way to clear it) |
| `with_consistency_level(cl)` | `withConsistencyLevel(?ConsistencyLevel $consistencyLevel): static` | No I/O. Returns a clone. `null` clears it (PHP addition) |
| `len(collection)` / `__len__` | `$col->length(): int`, and `count($col)` | `aggregate->overAll(totalCount: true)->totalCount`. Honours the tenant. See §13 for servers below 1.29 |
| `collection.exists()` | `$col->exists(): bool` | `config->get(simple: true)`. A 404 returns `false`; any other error is re-thrown |
| `collection.shards()` | `$col->shards(): list<Shard>` | `cluster->nodes(collection: $name, output: Verbose)`, flattened to the shards of every node. `Shard` fields: `collection`, `name`, `node`, `objectCount`, `vectorIndexingStatus` (`READONLY`\|`INDEXING`\|`READY`\|`LAZY_LOADING`), `vectorQueueLength`, `compressed`, `loaded` (`?bool`) |
| `collection.iterator(include_vector=False, return_metadata=None, *, return_properties=None, return_references=None, after=None, cache_size=None)` | `$col->iterator(includeVector: false, returnMetadata: null, returnProperties: null, returnReferences: null, after: null, cacheSize: null): \Generator<int, WeaviateObject>` | `cacheSize` defaults to **100** (Python `ITERATOR_CACHE_SIZE`). Paging uses `fetchObjects(limit: cacheSize, after: lastUuid)`. Full behaviour is in the query spec |
| `__str__` (runs `config.get()` and dumps JSON) | **Not ported** | A string cast that does network I/O breaks the "no hidden I/O" rule. Use `json_encode($col->config->get())` |
| `Collection[Props, Refs]` generics, `data_model` | PHPStan `@template TProperties of array` | Typing only |

### 2.3 Immutability

`withTenant()` and `withConsistencyLevel()` use `clone` and reset the cached namespace objects on the clone, so the tenant and consistency level can't leak between handles. Chaining in either order gives the same result:

```php
$h = $col->withTenant('acme')->withConsistencyLevel(ConsistencyLevel::Quorum);
$col->tenant();  // null (the original is untouched)
$h->tenant();    // 'acme'
```

## 3. How tenant and consistency level reach the wire

Python's `__apply_context` and `__apply_context_to_params_and_object` in `data/executor.py`, `_BaseGRPC._get_consistency_level`, and the batch builders were ported one-to-one:

| Operation | Tenant is sent as | Consistency level is sent as |
|---|---|---|
| `insert` | JSON body `"tenant"` | query `?consistency_level=` |
| `replace`, `update` | JSON body `"tenant"` | query `?consistency_level=` |
| `deleteById`, `exists` | query `?tenant=` | query `?consistency_level=` |
| `referenceAdd`, `referenceDelete`, `referenceReplace` | query `?tenant=` | query `?consistency_level=` |
| `referenceAddMany` (REST batch) | `"tenant"` on each array item | query `?consistency_level=` |
| `insertMany` (gRPC `BatchObjects`) | `BatchObject.tenant` on each object | `BatchObjectsRequest.consistency_level` |
| `deleteMany` (gRPC `BatchDelete`) | `BatchDeleteRequest.tenant` | `BatchDeleteRequest.consistency_level` |
| `query`, `generate`, `aggregate`, `iterator` | the `tenant` field of the request | the `consistency_level` field (query spec) |
| `tenants->*`, `config->*`, `backup->*` | **not sent** | **not sent** |

- REST uses the string value (`ONE`, `QUORUM`, `ALL`). gRPC uses `weaviate.v1.ConsistencyLevel`: `CONSISTENCY_LEVEL_ONE = 1`, `QUORUM = 2`, `ALL = 3`. When the handle has no level, the field is **left unset** (it's `optional` in the proto), and the server uses its default.
- Python sends the tenant in the body for insert, replace and update and as a query parameter elsewhere. PHP does the same; the server rejects a body tenant on DELETE.
- Query parameters are URL-encoded by the PSR-7 URI builder. Path segments (collection name, property name, tenant name) go through `rawurlencode()`. Python doesn't encode path segments, but valid Weaviate names never need it, so this is only a safety net.

## 4. Input value objects

All of these are `final readonly class`es. Constructors validate and throw `InvalidInputException`.

### 4.1 `DataObject` (Python `DataObject(properties, uuid, vector, references)`)

| Python field | PHP param | Type | Default |
|---|---|---|---|
| `properties` | `properties` | `array<string, mixed>` | `[]` |
| `uuid` | `uuid` | `string\|UuidInterface\|null` | `null` (a v4 id is generated at send time) |
| `vector` | `vector` | `list<float\|int>\|array<string, list<float\|int>\|list<list<float\|int>>>\|null` | `null` (§6) |
| `references` | `references` | `array<string, ReferenceInput>\|null` | `null` (§4.3) |

Python's field order is `properties, uuid, vector, references`. PHP keeps it, so positional calls port directly.

### 4.2 `ReferenceToMulti` (Python `ReferenceToMulti(target_collection, uuids)`)

| Python | PHP | Type |
|---|---|---|
| `target_collection` | `targetCollection` | `string` (not empty) |
| `uuids` | `uuids` | `string\|UuidInterface\|list<string\|UuidInterface>` |

Used for properties that point to more than one collection. It produces beacons of the form `weaviate://localhost/{targetCollection}/{uuid}`.

### 4.3 Reference input forms (`ReferenceInput`)

`ReferenceInput = UUID | Sequence[UUID] | ReferenceToMulti`. A `references` map is `array<string, ReferenceInput>`, keyed by reference property name.

| Input | REST (merged into `"properties"`) | gRPC (`BatchObject.Properties`) |
|---|---|---|
| `'uuid'` or `UuidInterface` | `[{"beacon": "weaviate://localhost/{uuid}"}]` | `single_target_ref_props { prop_name, uuids: [uuid] }` |
| `['u1', 'u2']` | `[{"beacon": "weaviate://localhost/u1"}, {"beacon": "…/u2"}]` | `single_target_ref_props { prop_name, uuids: [u1, u2] }` |
| `new ReferenceToMulti('Author', ['u1'])` | `[{"beacon": "weaviate://localhost/Author/u1"}]` | `multi_target_ref_props { prop_name, uuids, target_collection }` |

Single-target beacons deliberately **omit** the collection, exactly as Python does; the server resolves it from the schema. On REST, the references are merged into `"properties"` **after** the plain properties (`{**props, **refs}`), so a reference wins if a key collides.

### 4.4 `DataReference` and `DataReferenceMulti` (Python `DataReference`, `DataReference.MultiTarget`)

Input for `referenceAddMany()`.

| Python | PHP | Type |
|---|---|---|
| `from_property` | `fromProperty` | `string` |
| `from_uuid` | `fromUuid` | `string\|UuidInterface` |
| `to_uuid` | `toUuid` | `string\|UuidInterface\|list<string\|UuidInterface>` |
| `DataReferenceMulti.target_collection` | `DataReferenceMulti::$targetCollection` | `string` |

`DataReference::multiTarget(fromProperty:, fromUuid:, toUuid:, targetCollection:)` is a static alias that returns a `DataReferenceMulti`, mirroring `DataReference.MultiTarget(...)`.

### 4.5 `GeoCoordinate` and `PhoneNumber`

| Python | PHP | Validation | Serialized as |
|---|---|---|---|
| `GeoCoordinate(latitude, longitude)` | `new GeoCoordinate(latitude: float, longitude: float)` | latitude in [-90, 90], longitude in [-180, 180] | `{"latitude": …, "longitude": …}` |
| `PhoneNumber(number, default_country=None)` | `new PhoneNumber(number: string, defaultCountry: ?string = null)` | `defaultCountry` is ISO 3166-1 alpha-2 (not checked client-side, like Python) | `{"input": number, "defaultCountry": …}` (the key is omitted when `null`) |
| `_PhoneNumber` (output) | `PhoneNumberOutput` | — | Passing it as **input** throws `InvalidInputException` ("Cannot use PhoneNumberOutput when inserting a phone number. Use PhoneNumber instead."), like Python |

## 5. Value-type mapping

### 5.1 PHP ↔ Weaviate data types

| Weaviate `DataType` | PHP in (insert, replace, update, insertMany) | REST JSON | gRPC in (`BatchObject.Properties`) | PHP out (queries, `properties.proto` `Value`) |
|---|---|---|---|---|
| `text` | `string` | string | `non_ref_properties` Struct string | `string` (`text_value`) |
| `text[]` | `list<string>` | array | `text_array_properties` | `list<string>` (`text_values`) |
| `int` | `int` | number | Struct **number (float64)**, see §5.3 | `int` (`int_value`) |
| `int[]` | `list<int>` | array | `int_array_properties.values` (repeated int64) | `list<int>` (`int_values`: packed int64 LE) |
| `number` | `float` (or `int`) | number | Struct number | `float` (`number_value`) |
| `number[]` | `list<float>` (at least one float, §5.2) | array | `number_array_properties.values_bytes`: packed float64 **little-endian** (`pack('e*', …)`) | `list<float>` (`number_values`: packed float64 LE) |
| `boolean` | `bool` | bool | Struct bool | `bool` |
| `boolean[]` | `list<bool>` | array | `boolean_array_properties` | `list<bool>` |
| `date` | `\DateTimeInterface`, or a `string` passed through unchanged | RFC 3339 string | Struct string, same format | `\DateTimeImmutable` (`date_value`) |
| `date[]` | `list<\DateTimeInterface>` | array of strings | `text_array_properties` with formatted strings | `list<\DateTimeImmutable>` (`date_values`) |
| `uuid` | `string` or `UuidInterface` | string | Struct string | `string`, lowercase canonical (`uuid_value`). Python returns `uuid.UUID`; PHP uses `string` per [03](03-api-design.md#conventions) |
| `uuid[]` | `list<string\|UuidInterface>` | array | `text_array_properties` (a `UuidInterface` list) or text array (a string list) | `list<string>` |
| `geoCoordinates` | `GeoCoordinate` | object | Struct `{latitude, longitude}` | `GeoCoordinate` (`geo_value`; float32 on the wire, so expect about 7 significant digits) |
| `phoneNumber` | `PhoneNumber` | object | Struct `{input, defaultCountry}` | `PhoneNumberOutput` (`phone_value`) |
| `blob` | `string`, **already base64-encoded** | string | Struct string | `string`, base64 (`blob_value`) |
| `blobHash` (1.37+) | `string`, base64 | string | Struct string | Not returned in queries (unverified) |
| `object` | `array<string, mixed>` (not a list) or `\stdClass` | object | `object_properties { prop_name, value: ObjectPropertiesValue }`, recursively | `array<string, mixed>` (`object_value`) |
| `object[]` | `list<array<string, mixed>>` | array of objects | `object_array_properties { prop_name, values: [ObjectPropertiesValue] }` | `list<array<string, mixed>>` (`object_values`) |
| any, set to null | `null` | `null` | Struct `null_value` | `null` (`null_value`) |
| any array, empty | `[]` | `[]` | `empty_list_props: [prop_name]` (the server takes the type from the schema) | `[]` |

`PhoneNumberOutput` fields (from `_PhoneNumber` / `properties.PhoneNumber`): `number` (the `input`), `countryCode: int`, `defaultCountry: string`, `internationalFormatted: string`, `national: int`, `nationalFormatted: string`, `valid: bool`.

Anything else (resources, closures, arbitrary objects that aren't listed above, `NAN`, `INF`) throws `InvalidInputException("Cannot serialize value of type X to Weaviate.")` before I/O, like Python's `__serialize_primitive`.

### 5.2 How PHP arrays are classified (the gRPC path)

PHP has one `array` type for lists and maps, so the gRPC mapper ports Python's `isinstance` chain (`__translate_properties_from_python_to_grpc`) with these rules, applied per property in order:

1. `GeoCoordinate` or `PhoneNumber` → a Struct object.
2. A non-list array (`!array_is_list($v)`) or a `\stdClass` → `object_properties`, recursing with `nested: true`.
3. An empty array → `empty_list_props`.
4. A list whose first element is a non-list array or `\stdClass` → `object_array_properties`.
5. A list whose first element is `bool` → `boolean_array_properties`. This check comes before the int check, as in Python (where `bool` is a subclass of `int`).
6. A list whose first element is `string` → `text_array_properties`.
7. A list whose first element is `\DateTimeInterface` → `text_array_properties` of formatted dates.
8. A list whose first element is `UuidInterface` → `text_array_properties` of strings.
9. A list of `int|float`: **if any element is a `float`**, it's `number_array_properties` (ints are cast to float); otherwise it's `int_array_properties`. **This deviates from Python**, which only looks at the first element; `[1, 2.5]` makes Python try to put 2.5 into an int64 array and fail. See §17 for the `number[]`-sent-as-ints question.
10. Anything else → a scalar in the `non_ref_properties` Struct (`DateTimeInterface` formatted, `UuidInterface` cast to string).

An empty **object** can't be written as `[]`, because PHP reads that as an empty list. Use `new \stdClass()` or `(object) []`. The same applies on REST, where `json_encode([])` gives `[]` but `(object) []` gives `{}`.

Mixed-type lists (for example `['a', 1]`) aren't validated client-side, because Python doesn't validate them either. The protobuf setter throws, and the mapper rethrows that as `InvalidInputException` naming the property.

### 5.3 Serialization details

- **Dates:** `DateTimeInterface::format('Y-m-d\TH:i:s.uP')`, for example `2024-01-02T03:04:05.000000+00:00`. This is byte-identical to Python's `isoformat(sep="T", timespec="microseconds")`. Python warns and assumes UTC for naive datetimes. PHP datetimes always carry a zone (by default `date.default_timezone_get()`), so there's nothing to warn about. The docs recommend `new \DateTimeImmutable('…', new \DateTimeZone('UTC'))`.
- **Date output:** Weaviate can return up to 9 fractional digits; PHP keeps 6 and truncates the rest, as Python does. A trailing `Z` and `±hh:mm` offsets are both accepted. An empty string logs a warning and returns `null`, as Python does. Year 0 is a Python special case (it returns `datetime.min` with a warning). PHP can represent `0000-01-01`, so it returns the real value and doesn't warn.
- **JSON (REST):** `json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`. `JSON_PRESERVE_ZERO_FRACTION` is **required**, so that `1.0` stays `1.0` for `number` properties and vectors. A `JsonException` (for example from invalid UTF-8) becomes `InvalidInputException`.
- **Integers on gRPC:** a scalar `int` travels in a `google.protobuf.Struct`, which only has float64 numbers. Integers above 2^53 lose precision in `insertMany` and batch. Python has exactly the same limitation. `int[]` uses real int64s and is exact. Document this, and point users who need exact large ints to `insert()` (REST).
- **Packed little-endian values:** `pack('e*', …)` for float64, `pack('g*', …)` for float32 and `pack('v', …)` for uint16. These are explicitly little-endian. Python uses native byte order (`struct.pack("d")`), which only works because the hosts are little-endian. For int64 output, `unpack('P*', …)` gives the signed value on 64-bit PHP (verify this with a unit test for negative ints).
- **Reserved names (gRPC path only):** a top-level property named `id`, or a property named `vector` at any depth, throws `InvalidPropertyException` (Python `WeaviateInsertInvalidPropertyError`, whose message is ported verbatim). As in Python, REST `insert`, `replace` and `update` don't run this check; the server validates them.

## 6. Vectors

The accepted shapes are the same as Python `VECTORS`, minus the numpy/torch/pandas adapters:

| Shape | PHP value | Meaning |
|---|---|---|
| `list<float\|int>` | `[0.1, 0.2, …]` | A single, unnamed (default) vector |
| `array<string, list<float\|int>>` | `['title_vec' => [...]]` | Named single vectors |
| `array<string, list<list<float\|int>>>` | `['colbert' => [[...], [...]]]` | A named multi-vector (ColBERT style) |
| Mixed named | `['title_vec' => [...], 'colbert' => [[...]]]` | Allowed |

The shape is detected with `array_is_list()`. A list is a vector; a string-keyed map is a set of named vectors. Vector names are never purely numeric in Weaviate, so a PHP integer-key coercion like `"0"` → `0` can't be ambiguous.

Validation (before I/O, `InvalidInputException`):
- The vector is empty, or contains a non-numeric value (NaN or INF included).
- An **unnamed multi-vector** (`list<list<float>>` at the top level). Python silently sends `"vector": [[…]]` on REST and crashes with `AttributeError` on gRPC. PHP rejects it: "multi-vectors must be named: ['name' => [[…]]]".
- The rows of a multi-vector have different lengths, or a row is longer than 65535 (the uint16 dimension header).

Wire format:

| Transport | Single unnamed | Named (single or multi) |
|---|---|---|
| REST | `"vector": [floats]` | `"vectors": {"name": [floats] or [[floats]]}` |
| gRPC `BatchObject` | `vector_bytes = pack('g*', ...$v)` (float32 LE). The deprecated `repeated float vector = 2` is **never** used | `repeated Vectors vectors = 23`, one per name: `Vectors{ name, vector_bytes, type }` |
| gRPC single packing | — | `type = VECTOR_TYPE_SINGLE_FP32 (1)`, `vector_bytes = pack('g*', …)` |
| gRPC multi packing | — | `type = VECTOR_TYPE_MULTI_FP32 (2)`, `vector_bytes = pack('v', $dim) . pack('g*', ...flatten($rows))` (a uint16 LE row length, then the rows in order). The deprecated `Vectors.index` is left unset |

Multi-vectors need server 1.29 or later (for the collection config, not a client check). Precision: gRPC sends float32, so a float64 PHP value is rounded. That's the same as Python.

## 7. `collection.data` operations

Every method validates first, then does I/O. REST calls use the `insert` timeout for writes and the `query` timeout for `HEAD`/`GET` ([09 §4.1](09-connection.md#41-timeout-seconds-all--0)).

### 7.1 `insert()` (Python `insert(properties, references=None, uuid=None, vector=None)`)

| Python param | PHP param | Type | Default |
|---|---|---|---|
| `properties` | `properties` | `array<string, mixed>` | **required** |
| `references` | `references` | `?array<string, ReferenceInput>` | `null` |
| `uuid` | `uuid` | `string\|UuidInterface\|null` | `null` → `Uuid::v4()` generated client-side |
| `vector` | `vector` | vector shape (§6) or `null` | `null` |

Returns `string`, the object UUID. It's the id the client sent, not one parsed from the response; this is Python's behaviour.

Transport: `POST /v1/objects?consistency_level=…` with this body:

```json
{"class": "Article", "properties": {…props, …refs}, "id": "…", "vector": […] | "vectors": {…}, "tenant": "…"}
```

- The expected status is `200`. Anything else throws `UnexpectedStatusCodeException("Object was not added", …)`. That includes `422` when the UUID already exists.
- Validation: `uuid` must be a well-formed UUID string (PHP check; Python only checks the type and lets the server reject it). `properties` must be an array; a `list` with no string keys is rejected, because properties are a map.
- Not retried (POST isn't idempotent, [01](01-architecture.md#rest)).

### 7.2 `insertMany()` (Python `insert_many(objects)`)

| Python param | PHP param | Type |
|---|---|---|
| `objects` | `objects` | `iterable<array<string, mixed>\|DataObject>` |

Returns a `BatchObjectReturn` (§8.1).

Transport: gRPC `weaviate.v1.Weaviate/BatchObjects`, one unary call, `insert` timeout.

`BatchObjectsRequest { objects: [BatchObject…], consistency_level? }`, and each `BatchObject` is:

| Field | Value |
|---|---|
| `uuid` (1) | `DataObject::$uuid`, or a new v4 id. A plain array element always gets a new v4 id |
| `properties` (3) | §5.2 plus references (§4.3). Left unset when `properties` is `null` |
| `collection` (4) | `$col->name` |
| `tenant` (5) | the handle's tenant, or empty |
| `vector_bytes` (6) | only for a single unnamed vector |
| `vectors` (23) | only for named vectors |

Behaviour, ported from `_BatchGRPC.objects` and `ConnectionSync.grpc_batch_objects`:
1. Index `i` is the position in the input iterable. `BatchObjectsReply.errors[] { index, error }` is mapped back to that position.
2. Successes go into `uuids[i]` and failures into `errors[i] = new ErrorObject(message, object: BatchObject, originalUuid)`.
3. **If every object failed**, throw `InsertManyAllFailedException`. Its message is `"Here is the set of all errors: "` followed by the **distinct** error strings, joined with `\n`.
4. If some objects failed, log a PSR-3 `error` ("Failed to send N objects in a batch of M. Please inspect the errors variable of the returned object for more information.") and **return** normally. Per-object failures never throw.
5. **Retries:** only on gRPC `UNAVAILABLE`, with exponential backoff (1 s, 2 s, 4 s). Python passes `max_retries=2` to `_Retry`, and its off-by-one gives **4 attempts in total** plus a useless final 8 s sleep before raising. PHP makes exactly 4 attempts (sleeping 1, 2 and 4 s), drops the final sleep, and then throws `InsertManyException` wrapping `RetryException`. Other gRPC codes aren't retried.
6. `PERMISSION_DENIED` → `ForbiddenException`. Any other gRPC error → `InsertManyException` with the status details (Python `WeaviateBatchError`).
7. `elapsedSeconds` is wall-clock time measured around the call, as in Python. The proto `took` field is ignored.

PHP differences:
- **An empty input returns an empty `BatchObjectReturn` without any I/O.** Python sends the request and then raises `WeaviateInsertManyAllFailedError` with an empty message, because 0 == 0.
- **A message size pre-check.** The serialized request (`$request->serializeToString()`) is compared with the effective `grpcMaxMessageSize` ([09 §4.4](09-connection.md#44-grpcconfig)). If it's too big, throw `InvalidInputException` ("insertMany payload is X bytes, above the server limit of Y; use \$col->batch->dynamic() or split the input") before sending. Python stores the limit but doesn't check it here, so an oversized request fails with `RESOURCE_EXHAUSTED`.

### 7.3 `replace()` (Python `replace(uuid, properties, references=None, vector=None)`)

| Python param | PHP param | Type | Default |
|---|---|---|---|
| `uuid` | `uuid` | `string\|UuidInterface` | **required** |
| `properties` | `properties` | `array<string, mixed>` | **required** |
| `references` | `references` | `?array` | `null` |
| `vector` | `vector` | vector or `null` | `null` |

Returns `void`. Transport: `PUT /v1/objects/{collection}/{uuid}?consistency_level=…` with body `{"class", "properties", "id", "vector"|"vectors", "tenant"}`. The `id` must be in the body. The expected status is `200`; anything else throws `UnexpectedStatusCodeException("Object was not replaced.")`, including `404` for an unknown id. A PUT **replaces the whole object**: omitted properties are removed, and a vector that isn't sent is recomputed by the vectorizer, if there is one.

### 7.4 `update()` (Python `update(uuid, properties=None, references=None, vector=None)`)

The params are the same as `replace()`, except that `properties` is optional (`?array`, default `null`, sent as `{}`). Returns `void`. Transport: `PATCH /v1/objects/{collection}/{uuid}?consistency_level=…` with body `{"class", "properties", "vector"|"vectors", "tenant"}` (no `id`). The expected status is `200` or `204`; anything else throws `UnexpectedStatusCodeException("Object was not updated.")`. A PATCH **merges** the given properties into the object.

### 7.5 `deleteById()` (Python `delete_by_id(uuid)`)

`deleteById(string|UuidInterface $uuid): bool`. Transport: `DELETE /v1/objects/{collection}/{uuid}?tenant=…&consistency_level=…`. `204` → `true`, `404` → `false`, and anything else throws `UnexpectedStatusCodeException("Object could not be deleted.")`. PHP validates the UUID format; Python doesn't validate it at all here.

### 7.6 `deleteMany()` (Python `delete_many(where, *, verbose=False, dry_run=False)`)

| Python param | PHP param | Type | Default |
|---|---|---|---|
| `where` | `where` | `Filter` (the query spec's filter types) | **required** |
| `verbose` | `verbose` | `bool` | `false` |
| `dry_run` | `dryRun` | `bool` | `false` |

Returns a `DeleteManyReturn` (§8.4). Transport: gRPC `weaviate.v1.Weaviate/BatchDelete`, `insert` timeout, **no retry**.

`BatchDeleteRequest { collection, filters: Filters (the same converter as Search), verbose, dry_run, consistency_level?, tenant? }`.

Response mapping, from `BatchDeleteReply`:
- `failed`, `matches` and `successful` are int64 values mapped to `int`.
- When `verbose`, `objects` is a list of `DeleteManyObject { uuid, successful, error }`. `uuid` is 16 raw bytes, **big-endian**, turned into a canonical string with `vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4))`. An `error` of `""` becomes `null`. When not verbose, `objects` is `null`.
- **PHP addition:** `limit: ?int` comes from the new optional `BatchDeleteReply.limit` (the server's `QUERY_MAXIMUM_RESULTS` cap). `null` means the server predates the field. When `matches > limit`, the delete was capped: log a warning suggesting the user repeat the call. Python doesn't read this field yet.

Errors: `PERMISSION_DENIED` → `ForbiddenException`. Anything else → `DeleteManyException("[CODE_NAME] details")`, which is Python's `WeaviateDeleteManyError` format.

Python's `where` validation is a no-op (a `_ValidateArgument` that's never passed to `_validate_input`). PHP's `Filter` type declaration enforces it.

### 7.7 `exists()` (Python `exists(uuid)`)

`exists(string|UuidInterface $uuid): bool`. Transport: `HEAD /v1/objects/{collection}/{uuid}?tenant=…&consistency_level=…`. `204` → `true`, `404` → `false`, and anything else throws `UnexpectedStatusCodeException("object existence")`. The UUID is always validated, even when validation is turned off (Python validates it unconditionally too).

### 7.8 `referenceAdd()` (Python `reference_add(from_uuid, from_property, to)`)

| Python param | PHP param | Type |
|---|---|---|
| `from_uuid` | `fromUuid` | `string\|UuidInterface` |
| `from_property` | `fromProperty` | `string` |
| `to` | `to` | `string\|UuidInterface\|ReferenceToMulti` (`SingleReferenceInput`) |

Returns `void`. Transport: `POST /v1/objects/{collection}/{fromUuid}/references/{fromProperty}?tenant=…&consistency_level=…`, with body `{"beacon": "weaviate://localhost/[Target/]uuid"}`. The expected status is `200`.
- A `ReferenceToMulti` with **more than one** uuid throws `InvalidInputException` ("referenceAdd does not support adding multiple objects to a reference at once. Use referenceAddMany or referenceReplace instead."). A one-element list is fine.
- A plain list isn't accepted for `to` (Python's validator rejects it too).

### 7.9 `referenceAddMany()` (Python `reference_add_many(refs: List[DataReferences])`)

`referenceAddMany(list<DataReference|DataReferenceMulti> $refs): BatchReferenceReturn`.

Transport: **REST `POST /v1/batch/references?consistency_level=…`**. This is not gRPC: Python's `_BatchREST.references` is also what `collection.batch` uses for references.

The body is a flat array with one item per target uuid:

```json
[{"from": "weaviate://localhost/{Collection}/{fromUuid}/{fromProperty}", "to": "weaviate://localhost/[Target/]{toUuid}", "tenant": "…"}]
```

The `tenant` key is only present when the handle has a tenant.

Response: a JSON array aligned with the request items. Each item whose `result.status == "FAILED"` becomes `errors[k] = new ErrorReference(message: result.errors.error[0].message, reference: BatchReference)`. **`k` is the position in the flattened item list**, not the position in `$refs`, which is what Python does. `BatchReference::$index` carries the original `$refs` index, so callers can map back. The expected HTTP status is `200`; anything else throws `UnexpectedStatusCodeException("Send ref batch")`. An empty `$refs` returns an empty result without I/O (PHP).

### 7.10 `referenceReplace()` (Python `reference_replace(from_uuid, from_property, to)`)

`to: string|UuidInterface|list<string|UuidInterface>|ReferenceToMulti`. Transport: `PUT /v1/objects/{collection}/{fromUuid}/references/{fromProperty}?tenant=…&consistency_level=…`, with the body as a **list** of beacons, `[{"beacon": …}, …]`. The expected status is `200`. An empty list clears the reference property.

### 7.11 `referenceDelete()` (Python `reference_delete(from_uuid, from_property, to)`)

`to: string|UuidInterface|ReferenceToMulti`. Transport: `DELETE` on the same path, with the body `{"beacon": …}` (a DELETE with a JSON body, which PSR-7 supports). The expected status is `204`. A multi-uuid `ReferenceToMulti` throws `InvalidInputException` ("… Use referenceReplace instead.").

### 7.12 `ingest()` (Python `ingest(objs)`)

`ingest(iterable<array|DataObject> $objs): BatchObjectReturn`. This is server-side batching (gRPC `BatchStream`) that sizes batches automatically, without holding the whole input in one request. It takes an `iterable`, so a PHP `Generator` streams large inputs. It's built on the collection batcher from the batch spec: `$col->batch->stream(fn ($b) => foreach … $b->addObject(...))`, returning `results()->objs`.
- It needs server **1.36** (the same gate as `batch.stream()`; Python enforces it on the stream path).
- On transports without bidirectional streaming (the default curl transport), it falls back to the client-side dynamic batcher with a one-time notice, as described in [01](01-architecture.md#grpc).

## 8. Result objects

All of these are `final readonly class` in `Weaviate\Client\Result`.

### 8.1 `BatchObjectReturn`

| Python attribute | PHP property | Type |
|---|---|---|
| `uuids` | `uuids` | `array<int, string>` (input index → uuid) |
| `errors` | `errors` | `array<int, ErrorObject>` (input index → error) |
| `has_errors` | `hasErrors` | `bool` |
| `elapsed_seconds` | `elapsedSeconds` | `float` |
| `all_responses` (deprecated) | **not ported** | Python deprecates it with a warning |

The `+` operator, `add_uuids` and `add_errors` are mutation helpers for the batcher. PHP keeps them `@internal` on a separate `BatchObjectReturnBuilder`, including the `MAX_STORED_RESULTS = 100000` trimming of old `uuids` entries.

### 8.2 `ErrorObject` and `BatchObject`

`ErrorObject { message: string, object: BatchObject, originalUuid: ?string }`. Python calls the field `object_`; `object` is a legal property name in PHP.

`BatchObject { collection: string, properties: ?array, references: ?array, uuid: string, vector: ?array, tenant: ?string, index: int, retryCount: int }`. It carries everything needed to retry the object, so a caller can do `$col->data->insertMany(array_map(fn ($e) => $e->object->toDataObject(), $res->errors))`. `toDataObject()` is a PHP convenience.

### 8.3 `BatchReferenceReturn`, `ErrorReference` and `BatchReference`

- `BatchReferenceReturn { elapsedSeconds: float, errors: array<int, ErrorReference>, hasErrors: bool }`. `elapsedSeconds` is the HTTP round-trip time.
- `ErrorReference { message: string, reference: BatchReference }`.
- `BatchReference { fromObjectCollection, fromObjectUuid, fromPropertyName, toObjectUuid, toObjectCollection: ?string, tenant: ?string, index: int }`. It's parsed back from the beacons, as in Python's `BatchReference._from_internal`.

### 8.4 `DeleteManyReturn` and `DeleteManyObject`

```php
/** @template T of list<DeleteManyObject>|null */
final readonly class DeleteManyReturn {
    public function __construct(
        public int $failed,
        public int $matches,
        public int $successful,
        /** @var T */ public ?array $objects,
        public ?int $limit = null, // PHP addition (§7.6)
    ) {}
}
final readonly class DeleteManyObject {
    public function __construct(public string $uuid, public bool $successful, public ?string $error = null) {}
}
```

The PHPStan conditional return type is `@return ($verbose is true ? DeleteManyReturn<list<DeleteManyObject>> : DeleteManyReturn<null>)`, which mirrors Python's `@overload`s.

### 8.5 Tenants

`Tenant { name: string, activityStatus: TenantActivityStatus }` is used for both input and output (Python `Tenant` and `TenantOutput`). `tenants->get()` and `getByNames()` return `array<string, Tenant>`, keyed by tenant name.

## 9. Error handling

| Situation | Python | PHP |
|---|---|---|
| Client-side validation (bad UUID, bad vector shape, multi-uuid `referenceAdd`, bad tenant status, unserializable value, output phone number as input) | `WeaviateInvalidInputError` | `InvalidInputException` |
| `id`/`vector` inside properties (gRPC path) | `WeaviateInsertInvalidPropertyError` | `InvalidPropertyException extends InvalidInputException` |
| REST HTTP 403 (any data or tenant call) | `InsufficientPermissionsError` (subclass of `UnexpectedStatusCodeError`) | `ForbiddenException`. **Proposal:** make it extend `UnexpectedStatusCodeException` so that `catch (UnexpectedStatusCodeException)` also catches RBAC denials, as in Python |
| REST status not in the expected list | `UnexpectedStatusCodeError(msg, response)` | `UnexpectedStatusCodeException`, with `statusCode`, the decoded body and the Python message text |
| gRPC `PERMISSION_DENIED` | `InsufficientPermissionsError` | `ForbiddenException` (it also carries `grpcStatus`) |
| `insertMany`: whole-request gRPC failure | `WeaviateBatchError` | `InsertManyException` (it carries `grpcStatus`) |
| `insertMany`: every object rejected | `WeaviateInsertManyAllFailedError` | `InsertManyAllFailedException extends InsertManyException` |
| `insertMany`: `UNAVAILABLE` retries exhausted | `WeaviateRetryError` | `InsertManyException`, whose previous exception is `RetryException` |
| `deleteMany` gRPC failure | `WeaviateDeleteManyError("[CODE] details")` | `DeleteManyException` (**new**, `extends GrpcException`) |
| `tenants->get*` gRPC failure | `WeaviateTenantGetError` | `TenantsGetException` (**new**, `extends GrpcException`) |
| Server too old for a gated method | `WeaviateUnsupportedFeatureError` | `UnsupportedFeatureException` |
| Network, TLS or timeout | `WeaviateConnectionError` / `WeaviateTimeoutError` | `ConnectionException` / `TimeoutException` |
| Partial `insertMany` / `referenceAddMany` failure | returned in `.errors`, plus a log line | returned in `->errors`, plus PSR-3 `error` |

Rules:
- **Per-item failures never throw**, except when *all* objects in `insertMany` fail.
- **Not found is not an error** for `deleteById`, `exists`, `tenants->exists` and `tenants->getByName`. They return `false` or `null`.
- Operating on a multi-tenant collection without a tenant (or with one on a collection that isn't multi-tenant) is a **server** error: `UnexpectedStatusCodeException` (422) on REST, and a per-object error or `InsertManyAllFailedException` on gRPC. The client doesn't pre-check this, because that would need a schema round trip.

New exception classes to add to [01 §Errors](01-architecture.md#errors): `InsertManyAllFailedException`, `InvalidPropertyException`, `DeleteManyException`, `TenantsGetException`, `RetryException` and `TimeoutException`.

## 10. UUIDs and beacons

### 10.1 `generateUuid5()` (Python `generate_uuid5(identifier, namespace="")`)

```php
namespace Weaviate\Client\Util;
function generateUuid5(string|int|float|bool|\Stringable|null $identifier, string|int|float|bool|\Stringable|null $namespace = ''): string;
// also Uuid::generate5(...), same signature
```

The algorithm is Python's exactly: `uuid5(NAMESPACE_DNS, str(namespace) + str(identifier))`, with `NAMESPACE_DNS = 6ba7b810-9dad-11d1-80b4-00c04fd430c8`. It's implemented natively (`sha1(nsBytes . name, true)`, then setting the version nibble to `5` and the RFC 4122 variant bits), with **no ramsey dependency**. Returns a lowercase string.

For the ids to match the ones Python generates, the PHP → string conversion follows Python's `str()`:

| PHP value | String used | Python equivalent |
|---|---|---|
| `string` | as is | `str` |
| `int` | decimal | `str(42)` → `42` |
| `float` | Python `repr`: shortest round-trip form, always with a `.0` or exponent (`1.0`, `0.1`, `1e+20`, `1.5e-07`, `inf`, `nan`) | `str(1.0)` → `1.0` |
| `bool` | `True` / `False` | `str(True)` |
| `null` | `None` | `str(None)` |
| `\Stringable` (including `UuidInterface`) | `(string) $v` | `str(uuid.UUID)` is canonical lowercase |
| `array` | **rejected** (`InvalidInputException`: "pass json_encode($data) or a string; Python's dict repr can't be reproduced") | `str(dict)` is Python repr |

Test vectors, computed with CPython:

| Call | Result |
|---|---|
| `generateUuid5('hello')` | `9342d47a-1bab-5709-9869-c840b2eac501` |
| `generateUuid5('hello', 'Article')` | `de0b17b9-9ce1-5e63-9a7e-cf3581cb6156` |
| `generateUuid5(42)` | `7c411b5e-9d3f-50b5-9c28-62096e41c4ed` |
| `generateUuid5(1.0)` | `9550abfc-ee46-5d4a-8ee1-ccb0241ffe38` |
| `generateUuid5(1e20)` | `63dd56b8-3045-5b70-b87f-05a820b53f0d` |
| `generateUuid5(true)` | `eafa9d86-1a66-5dda-8697-5ccceb4fcf60` |
| `generateUuid5(null)` | `2155195b-5979-5a02-940e-44a63bc4927c` |
| `generateUuid5('')` | `4ebd0208-8328-5d69-8c44-ec50939c0967` |

### 10.2 Other UUID helpers

- `Uuid::v4(): string` uses `random_bytes(16)`, then sets the version and variant bits. It generates every id that's created client-side.
- `Uuid::isValid(string): bool` accepts the canonical 8-4-4-4-12 hex form, case-insensitive. Braces and URNs are rejected.
- `getValidUuid(string|UuidInterface $v): string` (Python `get_valid_uuid`) also accepts a beacon (`weaviate://localhost/[Class/]uuid`) or an object URL (`…/v1/objects/[Class/]uuid`) and extracts the uuid. Otherwise it throws `InvalidInputException`.
- UUIDs are passed through **as given**. PHP doesn't lowercase them, because Python's `str()` on a string keeps its case.
- `BEACON = 'weaviate://localhost/'` is a class constant on `Weaviate\Client\Data\Beacon`, with `Beacon::to(string $uuid, ?string $collection = null)` and `Beacon::from(string $collection, string $uuid, string $property)`.

## 11. Multi-tenancy (`collection.tenants`)

### 11.1 `TenantActivityStatus`

```php
enum TenantActivityStatus: string {
    case Active = 'ACTIVE';         // was HOT
    case Inactive = 'INACTIVE';     // was COLD
    case Offloaded = 'OFFLOADED';   // was FROZEN
    case Offloading = 'OFFLOADING'; // read-only (FREEZING)
    case Onloading = 'ONLOADING';   // read-only (UNFREEZING)
}
```

- The deprecated Python members `HOT`, `COLD` and `FROZEN` are **not ported**, because the client is new and has nothing to stay compatible with. They are still **accepted when decoding** server responses. `TenantActivityStatus::fromServer(string)` maps HOT → Active, COLD → Inactive, FROZEN → Offloaded, FREEZING → Offloading and UNFREEZING → Onloading.
- gRPC `weaviate.v1.TenantActivityStatus` decoding, ported from `_TenantsGRPC.map_activity_status`:

| Proto value | PHP |
|---|---|
| `HOT (1)`, `ACTIVE (7)` | `Active` |
| `COLD (2)`, `INACTIVE (8)` | `Inactive` |
| `FROZEN (4)`, `OFFLOADED (9)` | `Offloaded` |
| `FREEZING (6)`, `OFFLOADING (10)` | `Offloading` |
| `UNFREEZING (5)`, `ONLOADING (11)` | `Onloading` |
| `UNSPECIFIED (0)`, anything else | throws `UnexpectedValueException` (Python raises `ValueError`) |

- **Wire value on writes.** Python sends the **legacy** server strings on create and update, for backward compatibility: ACTIVE → `"HOT"`, INACTIVE → `"COLD"` and OFFLOADED → `"FROZEN"` (see `_TenantActivistatusServerValues`). PHP does the same, so it behaves identically on every server in the supported range.

### 11.2 Tenant input classes

| Python | PHP | Allowed statuses | Default |
|---|---|---|---|
| `Tenant(name, activity_status=ACTIVE)` | `new Tenant(string $name, TenantActivityStatus $activityStatus = Active)` | any, but create and update validate | `Active` |
| `TenantCreate(name, activity_status)` | `new TenantCreate(string $name, TenantActivityStatus $activityStatus = Active)` | `Active`, `Inactive` (checked in the constructor) | `Active` |
| `TenantUpdate(name, activity_status)` | `new TenantUpdate(string $name, TenantActivityStatus $activityStatus = Active)` | `Active`, `Inactive`, `Offloaded` (checked in the constructor) | `Active` |

Python's separate `TenantCreateActivityStatus` and `TenantUpdateActivityStatus` enums are folded into `TenantActivityStatus` plus the constructor checks. The value sets are identical.

### 11.3 Methods

| Python | PHP | Transport | Behaviour |
|---|---|---|---|
| `create(tenants: str \| Tenant \| TenantCreate \| Sequence[…])` | `create(string\|Tenant\|TenantCreate\|array $tenants): void` | `POST /v1/schema/{c}/tenants`, body `[{"name", "activityStatus": "HOT"\|"COLD"}]`, expects `200` | A string gets status `Active`. A `Tenant` whose status isn't Active or Inactive throws `InvalidInputException` ("Tenant activity status must be either 'ACTIVE' or 'INACTIVE'. Other statuses are read-only and cannot be set. …"). One request for all tenants |
| `remove(tenants: str \| Tenant \| Sequence[…])` | `remove(string\|Tenant\|array $tenants): void` | `DELETE /v1/schema/{c}/tenants`, body `["name", …]`, expects `200` | Names only |
| `update(tenants: Tenant \| TenantUpdate \| Sequence[…])` | `update(Tenant\|TenantUpdate\|array $tenants): void` | `PUT /v1/schema/{c}/tenants`, body `[{"name", "activityStatus"}]`, expects `200` | Sent in **chunks of 100** (`UPDATE_TENANT_BATCH_SIZE`), **one after another, not atomically**. If chunk k fails, chunks 0…k-1 are already applied. The exception says which chunk failed (PHP addition). Strings aren't accepted, because a status is required. A `Tenant` whose status is `Offloading` or `Onloading` throws `InvalidInputException` |
| `get()` | `get(): array<string, Tenant>` | gRPC `TenantsGet` `{collection}` (with no `names`), `query` timeout, retried on `UNAVAILABLE` (the `_Retry` default: n=4) | Python uses REST `GET /v1/schema/{c}/tenants` below 1.25; that's under the floor, so PHP is **gRPC only** |
| `get_by_names(tenants: Sequence[str \| Tenant])` | `getByNames(array $tenants): array<string, Tenant>` | gRPC `TenantsGet` `{collection, names: TenantNames{values}}` | Missing tenants are left out of the result |
| `get_by_name(tenant: str \| Tenant)` | `getByName(string\|Tenant $tenant): ?Tenant` | **Server 1.28+:** REST `GET /v1/schema/{c}/tenants/{name}` (`200` → Tenant, `404` → `null`). **1.27:** gRPC `TenantsGet` with `[name]` | The version switch is ported from Python |
| `exists(tenant: str \| Tenant)` | `exists(string\|Tenant $tenant): bool` | REST `HEAD /v1/schema/{c}/tenants/{name}`; `200` → true, `404` → false | |
| `activate(tenant \| names)` | `activate(string\|Tenant\|array $tenant): void` | `update()` with `Active` | |
| `deactivate(...)` | `deactivate(...)` | `update()` with `Inactive` | |
| `offload(...)` | `offload(...)` | `update()` with `Offloaded` | Needs an offload module configured on the server (a server error otherwise) |

`TenantsGet` errors: `PERMISSION_DENIED` → `ForbiddenException`, and anything else → `TenantsGetException`.

`tenants` is **not** tenant-scoped. `$col->withTenant('a')->tenants->get()` returns all tenants, as in Python.

### 11.4 Auto-tenant behaviour

These settings live in the collection config spec (`Configure::multiTenancy(enabled:, autoTenantCreation:, autoTenantActivation:)` and `Reconfigure::multiTenancy(...)`). Their effect on the data API is server-side only:
- **`autoTenantCreation`:** `insert`, `insertMany` and batch calls with an unknown tenant create it (with status Active) instead of failing.
- **`autoTenantActivation`:** a data or query call against an `Inactive` tenant activates it.

The client does nothing special. The integration tests cover both settings (§16).

## 12. `ConsistencyLevel`

```php
enum ConsistencyLevel: string {
    case One = 'ONE';
    case Quorum = 'QUORUM';
    case All = 'ALL';

    /** @internal */ public function toGrpc(): int; // 1, 2, 3 (Weaviate\Client\Proto\V1\ConsistencyLevel)
}
```

A consistency level only matters on replicated collections. On a collection with replication factor 1, the server accepts every level. Python's docstring ("If replication is not configured for this collection then Weaviate will throw an error") wasn't checked against the server; see §17.

## 13. Version gates

The floor is **1.29** ([ADR 0004](decisions/0004-server-version-floor.md)).

| Feature | Min server | Enforced by |
|---|---|---|
| `tenants->getByNames`, `getByName`, `exists` | 1.25 (Python `check_is_at_least_1_25_0`) | Below the floor, so no check |
| `tenants->getByName` over REST | 1.28 | Below the floor: always REST, with no gRPC path |
| `tenants->get` over gRPC | 1.25 | Below the floor, so no check |
| Named multi-vectors in `insert`/`insertMany` | 1.29 | At the floor, so no check |
| `data->ingest` (server-side batching) | 1.36 | The client: `requireServer('1.36', 'data.ingest')`, falling back to dynamic batching without bidi |
| `DeleteManyReturn::$limit` | a recent server (the field is new in `batch_delete.proto`; the exact version is unverified) | `null` when absent |
| `blobHash` values | 1.37 | The server |
| `$col->length()` | 1.29 (gRPC Aggregate) | At the floor, so no check |

## 14. PHP design notes

- **Named arguments mirror Python**, so `->insert(properties: [...], uuid: $id, vector: $v)` reads like `insert(properties=..., uuid=..., vector=...)`. PHP keeps Python's parameter order everywhere, even where it's odd (for example, `references` comes before `uuid` in `insert`).
- **A `DataObject` or an array in `insertMany`:** the array form is the fast path for the common case with no vectors or ids, as in Python.
- **`Countable`:** `count($col)` is explicit and documented as doing I/O. `__toString` is deliberately missing (§2.2).
- **Iterables:** `insertMany` accepts any `iterable` but materializes it, because it's one request. `ingest` streams.
- **No numpy equivalent.** Vectors are plain PHP arrays. An `ArrayAccess`/`Traversable` of floats is **not** accepted, to avoid silent copies; convert with `iterator_to_array()`.
- **Logging:** partial batch failures are logged at `error` level, exactly as in Python. Users who inspect `->errors` themselves can drop them with a PSR-3 level filter.
- **Proto classes stay internal.** `Transport\Mapper\DataMapper` is the only code that builds `BatchObject` or `BatchDeleteRequest`, and `TenantsMapper` is the only code that builds `TenantsGetRequest`.
- **Async package:** the same signatures, returning `Future<T>`. Python's async `reference_add` sends one POST per beacon concurrently. The PHP async client does the same with `Amp\Future\await()`.

## 15. Examples

```php
use Weaviate\Client\Data\{DataObject, DataReference, ReferenceToMulti, GeoCoordinate, PhoneNumber};
use Weaviate\Client\Config\ConsistencyLevel;
use Weaviate\Client\Query\Filter;
use Weaviate\Client\Tenants\{Tenant, TenantActivityStatus};
use function Weaviate\Client\Util\generateUuid5;

$articles = $client->collections->use('Article');

// Single insert with every value type
$id = $articles->data->insert(
    properties: [
        'title'     => 'Hello',
        'year'      => 2024,
        'score'     => 4.0,                               // stays 4.0 on the wire
        'published' => new DateTimeImmutable('2024-05-01T10:00:00Z'),
        'tags'      => ['db', 'search'],
        'location'  => new GeoCoordinate(latitude: 52.37, longitude: 4.89),
        'phone'     => new PhoneNumber('020 555 1234', defaultCountry: 'NL'),
        'cover'     => base64_encode(file_get_contents('cover.jpg')),   // blob
        'meta'      => ['source' => 'rss', 'rank' => 3],                // object
        'sections'  => [['h' => 'Intro'], ['h' => 'Body']],             // object[]
        'extra'     => new stdClass(),                                  // empty object
    ],
    references: ['author' => $authorId],
    uuid: generateUuid5('https://example.com/hello'),
    vector: ['title_vec' => $titleVec, 'colbert' => $tokenVecs],        // named + multi
);

// Bulk insert; partial failures are returned, not thrown
$res = $articles->data->insertMany([
    ['title' => 'A', 'year' => 2021],
    new DataObject(properties: ['title' => 'B'], uuid: generateUuid5('B'), vector: ['title_vec' => $v]),
    new DataObject(properties: ['title' => 'C'], references: ['author' => new ReferenceToMulti('Author', [$a1])]),
]);
if ($res->hasErrors) {
    foreach ($res->errors as $i => $err) {
        $logger->warning("row $i: {$err->message}", ['uuid' => $err->object->uuid]);
    }
}
$newIds = $res->uuids; // [0 => '…', 1 => '…', …]

// Replace (PUT) vs update (PATCH)
$articles->data->replace(uuid: $id, properties: ['title' => 'Hello v2', 'year' => 2025]);
$articles->data->update(uuid: $id, properties: ['year' => 2026]);

// Existence and delete
$articles->data->exists($id);      // true
$articles->data->deleteById($id);  // true; a second call returns false

// Delete many: dry run first, verbose
$preview = $articles->data->deleteMany(
    where: Filter::byProperty('year')->lessThan(2000),
    verbose: true,
    dryRun: true,
);
printf("%d would be deleted\n", $preview->matches);
foreach ($preview->objects as $o) { echo $o->uuid, PHP_EOL; }

// References
$articles->data->referenceAdd(fromUuid: $id, fromProperty: 'author', to: $authorId);
$articles->data->referenceReplace(fromUuid: $id, fromProperty: 'author', to: [$a1, $a2]);
$articles->data->referenceDelete(fromUuid: $id, fromProperty: 'author', to: $a2);
$refRes = $articles->data->referenceAddMany([
    new DataReference(fromProperty: 'author', fromUuid: $id, toUuid: [$a1, $a3]),
    DataReference::multiTarget(fromProperty: 'related', fromUuid: $id, toUuid: $otherId, targetCollection: 'Video'),
]);

// Consistency level
$strict = $articles->withConsistencyLevel(ConsistencyLevel::All);
$strict->data->insert(['title' => 'durable']);

// Multi-tenancy
$docs = $client->collections->use('Doc');
$docs->tenants->create(['acme', new Tenant('globex', TenantActivityStatus::Inactive)]);
$docs->tenants->activate('globex');
$docs->tenants->offload(['old-tenant-1', 'old-tenant-2']);
$docs->tenants->exists('acme');               // true
$docs->tenants->getByName('nope');            // null
foreach ($docs->tenants->get() as $name => $t) {
    echo $name, ' ', $t->activityStatus->value, PHP_EOL;
}

$acme = $docs->withTenant('acme');
$acme->data->insert(['text' => 'hi']);
count($acme);                                 // object count in tenant "acme" (I/O)
$docs->tenants->remove('acme');

// Streaming ingest from a generator (server 1.36+)
$rows = (function () { foreach (readCsv('big.csv') as $r) { yield $r; } })();
$result = $articles->data->ingest($rows);
```

## 16. Test checklist

Unit (no server):
- The value mapping in §5.1, both REST JSON and gRPC protobuf, for every row. That includes `1.0` staying `1.0`, date formatting with non-UTC offsets, `\stdClass` versus `[]`, and nested `object[]` inside `object`.
- The array classification rules in §5.2, including `[true, false]` before int, `[1, 2.5]` becoming a number array, `[1, 2]` becoming an int array, and a mixed `['a', 1]` throwing `InvalidInputException`.
- Reserved properties: a top-level `id` and a nested `vector` throw on `insertMany`, while a nested `id` is allowed.
- Vector packing: single (`pack('g*')` gives the right bytes for `[1.5, -2.0]`), multi (uint16 header plus rows, ragged rows rejected, dim > 65535 rejected), named and mixed, an unnamed 2-D vector rejected, and NaN rejected. Compare the bytes with Python's `_Pack` output fixtures.
- `number[]` `values_bytes` against Python `struct.pack('<Nd')`, and int64 LE decoding including negative values.
- `DeleteManyObject` uuid decoding from 16 big-endian bytes.
- The `generateUuid5` vectors in §10.1, plus Python-repr floats (`0.1`, `1e-7` → `1e-07`, `123456789012345678.0` → `1.2345678901234568e+17`, `-0.0`, `inf`), and arrays rejected.
- `Uuid::v4()` version and variant bits; `getValidUuid()` with a beacon, an object URL and garbage.
- The beacon formats for single-target, `ReferenceToMulti` and `referenceAddMany` `from`/`to`.
- Context propagation (§3): for each operation, assert whether the tenant lands in the body, the query or the proto, and that an unset consistency level leaves the proto field unset.
- `withTenant()` and `withConsistencyLevel()` return clones and leave the original untouched; `withTenant(new Tenant('x'))` works; `null` clears.
- Tenant status mapping: every proto enum value; the legacy wire strings on create and update; `TenantCreate(…, Offloaded)` throws.
- `tenants->update()` with 250 tenants sends 3 PUTs of 100, 100 and 50.
- `insertMany([])` does no I/O; an oversized payload throws before I/O.
- Retry: `UNAVAILABLE` ×3 then success gives 4 calls with sleeps of 1, 2 and 4 s (use an injected clock or sleeper); `UNAVAILABLE` ×4 throws; `INVALID_ARGUMENT` isn't retried.

Integration (a docker-compose matrix across the supported server versions):
- Insert with every data type, fetched back through gRPC `fetchObjectById`, to check the round-trip types (§5.1 "PHP out"). That includes `phoneNumber` output fields, geo precision, and dates with 9 fractional digits.
- A duplicate UUID insert gives `UnexpectedStatusCodeException` with status 422.
- Replace removes omitted properties; update merges.
- `deleteById` and `exists` return true/false for existing and missing ids.
- `insertMany` with a mix of valid and invalid objects returns `errors` keyed by input index; an all-invalid input throws `InsertManyAllFailedException`.
- `deleteMany`: `dryRun` deletes nothing; `verbose` returns per-object results; `limit` is populated on servers that send it.
- References: add, replace (including an empty list), delete, `referenceAddMany` with one invalid target (error at the flattened index), and multi-target references to two collections.
- Multi-tenancy: create, get, getByNames, getByName (REST), exists, update, activate, deactivate, and offload (with the offload-s3 module plus MinIO); a data call without a tenant on an MT collection fails; auto-tenant creation and auto-activation.
- Consistency levels ONE, QUORUM and ALL on a 3-node cluster with replication factor 3, for REST and gRPC writes.
- `length()` with and without a tenant; `exists()` on a missing collection; `shards()` on a multi-shard collection.
- RBAC: a data write with a read-only role gives `ForbiddenException` on REST and on gRPC.
- `ingest()` on 1.36+ with ext-grpc (server-side), and with curl (the dynamic fallback plus a notice).

## 17. Not verified, and open questions

1. **`number[]` sent as ints.** Python sends `[1, 2, 3]` for a `number[]` property as `int_array_properties`. It wasn't checked whether the server coerces this or rejects it. If it rejects it, PHP needs a schema-aware mapper or an explicit `(float)` cast in the docs. Add an integration test before P3.
2. **Accepted tenant status strings.** It wasn't checked whether servers ≥ 1.27 accept `ACTIVE`/`INACTIVE`/`OFFLOADED` on writes. PHP sends the legacy `HOT`/`COLD`/`FROZEN`, as Python does, which is safe.
3. **The server version that added `BatchDeleteReply.limit`.**
4. **The consistency level on non-replicated collections.** Python's docstring says the server errors; the expectation is that it's accepted. Confirm with a test.
5. **Unnamed multi-vector over REST.** PHP rejects it (§6). Confirm that the server has no valid use for it.
6. **`blobHash` input and output format** (1.37). Assumed to be the same as `blob`, with base64 in and not returned by queries.
7. **Whether Python's `__len__` works on 1.27–1.28.** It depends on the aggregate executor's GraphQL fallback, which wasn't traced here.
8. **Auto-tenant minimum versions.** They're below the floor as far as is known, but that wasn't verified.
9. **`unpack('P')` sign behaviour** for int64 values ≥ 2^63 on 64-bit PHP. PHP isn't available in this environment, so this is flagged for a unit test.
10. **PHP behaviour claims** (`json_encode` float handling, the `pack` codes) come from the PHP manual and weren't executed.

## 18. Corrections for [02](02-feature-parity-matrix.md)

§3 Data:
- `reference_add_many` is **REST `POST /v1/batch/references`**, not gRPC `BatchReferences`. The batch references path is the same (§4 of 02).
- `insert(properties, references, uuid, vector)` is Python's real parameter order. `insert_many` raises `InsertManyAllFailedException` when every object fails.
- `delete_by_id` returns a bool (204/404), and so does `exists`, which is REST **HEAD** `/v1/objects/{c}/{id}`.
- Missing rows: `data.ingest(objs)` (server-side batching, 1.36), `DataReference` / `DataReference.MultiTarget` (input for `reference_add_many`), `GeoCoordinate` and `PhoneNumber` input types, `BatchReferenceReturn`, `DeleteManyReturn.limit` (a PHP addition), `collection.__len__` → `length()`/`count()` (needs 1.29 via Aggregate), `collection.exists()`, `collection.shards()`, the `collection.tenant` and `collection.consistency_level` getters, `ConsistencyLevel` (ONE/QUORUM/ALL), and `generate_uuid5`.

§8 Multi-tenancy:
- `tenants.exists` is verified as REST `HEAD /v1/schema/{c}/tenants/{name}`; drop the †.
- `get_by_name` is REST `GET /v1/schema/{c}/tenants/{name}` on 1.28+ and gRPC `TenantsGet` on 1.27. `get` and `get_by_names` are gRPC.
- `TenantActivityStatus` also has the deprecated HOT/COLD/FROZEN; they aren't ported but are decoded.
- Missing rows: `tenants.activate`, `deactivate` and `offload`; the `TenantCreate` and `TenantUpdate` input types; the rule that `create` accepts only Active/Inactive and `update` only Active/Inactive/Offloaded; and that `update` is chunked into sequential PUTs of 100.
