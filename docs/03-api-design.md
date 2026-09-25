# 03: API design

## Conventions

| Python v4 | PHP | Why |
|-----------|-----|-----|
| Keyword arguments | **Named arguments** (PHP 8.0 and later) | Snippets map almost 1:1 from Python and optional arguments stay readable |
| `snake_case` methods and arguments | `camelCase` | PER-CS / PSR-12 idiom |
| `Enum` classes | Backed `enum`s (`DataType::Text`, `ConsistencyLevel::Quorum`) | Type-safe, and they serialize to the wire value |
| Dataclass / pydantic results | `final readonly class` value objects | Immutable and IDE-friendly |
| `Configure.Vectors.text2vec_openai(...)` | `Configure::vectors()->text2vecOpenAI(...)` | Static factory namespaces, discoverable with autocomplete |
| `Filter.by_property("a").equal(1) & Filter.by_property("b").like("x*")` | `Filter::byProperty('a')->equal(1)->and(Filter::byProperty('b')->like('x*'))`, or `Filter::allOf([...])` | PHP has no operator overloading |
| `with client:` context manager | `$client->close()`, or `Weaviate::withLocal(fn (WeaviateClient $c) => …)` | Scoped cleanup |
| `with collection.batch.dynamic() as batch:` | `$col->batch->dynamic(function (CollectionBatcher $b) { … })` | The closure runs the body and the batch flushes when it returns, even if it throws |
| Generators / iterators | PHP `Generator` (`foreach ($col->iterator() as $obj)`) | Native |
| `None` | `null` | — |
| `uuid.UUID` | `string` (or `UuidInterface` if ramsey/uuid is installed) | No required dependency |
| Generic `Collection[Props, Refs]` | PHPStan `@template TProperties of array` on `Collection` | Optional static typing |

Other rules:

- **Handles are immutable.** `withTenant()` and `withConsistencyLevel()` return clones.
- **No I/O in constructors or in `collections->use()`.** I/O happens only in methods that clearly perform it.
- **Validate before I/O.** Bad input (for example `limit: -1`, or giving both `certainty` and `distance`) throws `InvalidInputException` before any request is sent.
- **Follow Python's argument names exactly** (camelCased), even where a different PHP name looks nicer. Parity beats taste here.
- **Mark `Transport\*` and `Proto\*` `@internal`.** Everything else is public API and falls under SemVer once 1.0 is released.

## Worked examples

### Connect

```php
use Weaviate\Client\Weaviate;
use Weaviate\Client\Connect\Auth;

$client = Weaviate::connectToWeaviateCloud(
    clusterUrl: getenv('WEAVIATE_URL'),
    auth: Auth::apiKey(getenv('WEAVIATE_API_KEY')),
    headers: ['X-OpenAI-Api-Key' => getenv('OPENAI_API_KEY')],
);

$client->isReady(); // true
$client->getMeta()->version; // "1.36.2"

$local = Weaviate::connectToLocal(); // localhost:8080 / grpc 50051
```

### Create a collection with named vectors

```php
use Weaviate\Client\Config\{Configure, Property, ReferenceProperty, DataType};

$client->collections->create(
    name: 'Article',
    properties: [
        new Property(name: 'title', dataType: DataType::Text),
        new Property(name: 'body', dataType: DataType::Text),
        new Property(name: 'year', dataType: DataType::Int),
    ],
    references: [new ReferenceProperty(name: 'author', targetCollection: 'Author')],
    vectorConfig: [
        Configure::vectors()->text2vecOpenAI(name: 'title_vec', sourceProperties: ['title']),
        Configure::vectors()->text2vecOpenAI(
            name: 'body_vec',
            sourceProperties: ['body'],
            vectorIndexConfig: Configure::vectorIndex()->hnsw(quantizer: Configure::quantizer()->rq()),
        ),
    ],
    generativeConfig: Configure::generative()->openAI(model: 'gpt-4o'),
    multiTenancyConfig: Configure::multiTenancy(enabled: false),
);
```

### Insert

```php
$articles = $client->collections->use('Article');

$uuid = $articles->data->insert(['title' => 'Hello', 'body' => '…', 'year' => 2024]);

$result = $articles->data->insertMany([
    ['title' => 'A', 'year' => 2021],
    new DataObject(properties: ['title' => 'B'], uuid: $id, vector: ['body_vec' => $vec]),
]);

if ($result->hasErrors) {
    foreach ($result->errors as $index => $error) { /* ErrorObject */ }
}
```

### Query

```php
use Weaviate\Client\Query\{Filter, MetadataQuery, Sort, GroupBy, TargetVectors, HybridFusion};

$res = $articles->query->nearText(
    query: 'vector databases',
    targetVector: 'body_vec',
    limit: 5,
    filters: Filter::allOf([
        Filter::byProperty('year')->greaterOrEqual(2020),
        Filter::byProperty('title')->like('*search*'),
    ]),
    returnMetadata: new MetadataQuery(distance: true, creationTime: true),
    returnProperties: ['title', 'year'],
);

foreach ($res->objects as $obj) {
    echo $obj->uuid, ' ', $obj->properties['title'], ' ', $obj->metadata->distance, PHP_EOL;
}

$hybrid = $articles->query->hybrid(
    query: 'fast search',
    alpha: 0.75,
    fusionType: HybridFusion::RelativeScore,
    targetVector: TargetVectors::average(['title_vec', 'body_vec']),
    limit: 10,
);

$grouped = $articles->query->bm25(
    query: 'weaviate',
    groupBy: new GroupBy(prop: 'year', numberOfGroups: 3, objectsPerGroup: 2),
);
foreach ($grouped->groups as $name => $group) { /* … */ }

foreach ($articles->iterator(returnProperties: ['title']) as $obj) { /* cursor-paged */ }
```

### Generate (RAG)

```php
$res = $articles->generate->nearText(
    query: 'history of databases',
    limit: 3,
    singlePrompt: 'Summarize in one sentence: {body}',
    groupedTask: 'Write a tweet about these articles',
    generativeProvider: GenerativeConfig::anthropic(model: 'claude-sonnet-5'),
);

echo $res->generative->text;             // grouped result
echo $res->objects[0]->generative->text; // per-object result
```

### Aggregate

```php
use Weaviate\Client\Query\{Metrics, GroupByAggregate};

$agg = $articles->aggregate->overAll(
    totalCount: true,
    returnMetrics: [
        Metrics::of('year')->integer(mean: true, maximum: true),
        Metrics::of('title')->text(topOccurrencesCount: true, limit: 5),
    ],
    groupBy: new GroupByAggregate(prop: 'year'),
);
```

### Batch

```php
$report = $client->batch->dynamic(function (ClientBatcher $batch) use ($rows) {
    foreach ($rows as $row) {
        $batch->addObject(collection: 'Article', properties: $row);
    }
});

if ($report->hasErrors) {
    foreach ($report->failedObjects as $err) { /* ErrorObject: message, originalUuid, object */ }
}

// Uses server-side streaming when the transport supports bidi (ext-grpc or async); falls back to dynamic otherwise
$articles->batch->stream(function (CollectionBatcher $b) use ($rows) {
    foreach ($rows as $row) {
        $b->addObject(properties: $row);
    }
});
```

### Multi-tenancy

```php
use Weaviate\Client\Tenants\{Tenant, TenantActivityStatus};

$docs = $client->collections->use('Doc');
$docs->tenants->create([new Tenant('acme'), new Tenant('globex')]);
$docs->tenants->update(new Tenant('globex', activityStatus: TenantActivityStatus::Inactive));

$acme = $docs->withTenant('acme');
$acme->data->insert(['text' => 'hi']);
$acme->query->fetchObjects(limit: 10);
```

### RBAC

```php
use Weaviate\Client\Rbac\Permissions;

$client->roles->create(
    roleName: 'reader',
    permissions: [
        Permissions::collections(collection: 'Article', readConfig: true),
        Permissions::data(collection: 'Article', read: true),
    ],
);
$key = $client->users->db->create(userId: 'svc-reader');
$client->users->db->assignRoles(userId: 'svc-reader', roleNames: ['reader']);
```

### Errors

```php
try {
    $articles->query->nearText(query: 'x');
} catch (\Weaviate\Client\Exceptions\QueryException $e) {
    $e->grpcStatus; // e.g. 3 (INVALID_ARGUMENT)
} catch (\Weaviate\Client\Exceptions\WeaviateException $e) {
    // anything else from the client
}
```

## Open design questions

1. **Result property typing.** The default is plain `array<string, mixed>`. We could also offer an optional hydrator, `->query->returning(ArticleDto::class)`, that maps results to user DTOs. Proposal: ship it after 1.0.
2. **Date handling.** The proposal is to return `\DateTimeImmutable` for `date` properties and metadata times. Python returns `datetime`, so this matches.
3. **Result object naming.** `Object` is a reserved word in PHP. **Decided: `WeaviateObject`**, as used in [12](12-query-and-generate.md).
