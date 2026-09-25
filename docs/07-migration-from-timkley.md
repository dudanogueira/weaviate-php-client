# 07: Migrating from `timkley/weaviate-php`

This is a draft of the user-facing guide. It will be published with 1.0.

## Key differences

| | `timkley/weaviate-php` | `dudanogueira/weaviate-php-client` |
|---|---|---|
| Search | Hand-written GraphQL strings | Typed query API over gRPC |
| Terminology | "class", "schema", "data object" | "collection", "collection config", "object" |
| Framework | Needs `illuminate/http` and `illuminate/support` | No framework dependency (Laravel package available) |
| Auth | API key only | API key, bearer, OIDC client credentials and password |
| Batch | One REST request, no chunking | Dynamic, fixed-size, rate-limited or streaming batching over gRPC, with per-object errors |
| Multi-tenancy, named vectors, RBAC, backups, aliases | — | ✅ |
| PHP | ^8.3 | ^8.2 |

## Call mapping

| Old | New |
|-----|-----|
| `new Weaviate($url, $token, $headers)` | `Weaviate::connectToCustom(...)`, `connectToWeaviateCloud(clusterUrl: $url, auth: Auth::apiKey($token), headers: $headers)` or `connectToLocal()` |
| `$w->schema()->get()` | `$client->collections->listAll()` |
| `$w->schema()->get('Article')` | `$client->collections->exportConfig('Article')` or `->use('Article')->config->get()` |
| `$w->schema()->createClass([...])` | `$client->collections->create(name: 'Article', properties: [...])`, or `createFromArray([...])` to keep the old array format |
| `$w->schema()->update('Article', [...])` | `$client->collections->use('Article')->config->update(...)` |
| `$w->schema()->addProperty('Article', [...])` | `->config->addProperty(new Property(...))` |
| `$w->schema()->deleteClass('Article')` | `$client->collections->delete('Article')` |
| `$w->schema()->deleteAll()` | `$client->collections->deleteAll()` |
| `$w->dataObject()->create([...])` | `$col->data->insert(properties: [...], uuid: ..., vector: ...)` |
| `$w->dataObject()->getById('Article', $id)` | `$col->query->fetchObjectById($id)` |
| `$w->dataObject()->update('Article', $id, [...])` | `$col->data->update(uuid: $id, properties: [...])` |
| `$w->dataObject()->replace('Article', $id, [...])` | `$col->data->replace(uuid: $id, properties: [...])` |
| `$w->dataObject()->delete('Article', $id)` | `$col->data->deleteById($id)` |
| `$w->dataObject()->exists('Article', $id)` | `$col->data->exists($id)` |
| `$w->dataObject()->withQueryParameters(['limit' => 10])->get()` | `$col->query->fetchObjects(limit: 10)` |
| `$w->batch()->create([...])` | `$col->data->insertMany([...])` or `$client->batch->dynamic(fn ($b) => …)` |
| `$w->batch()->delete('Article', $where)` | `$col->data->deleteMany(where: Filter::…)` |
| `$w->graphql()->get('{ Get { Article(nearText: …) { title } } }')` | `$col->query->nearText(query: '…', returnProperties: ['title'])` |
| `$w->graphql()->get('{ Aggregate { … } }')` | `$col->aggregate->overAll(...)` |
| Any GraphQL query without a direct equivalent | `$client->graphqlRawQuery('…')` (escape hatch) |
| `$w->meta()->get()` | `$client->getMeta()` |
| `AuthenticationException` / `NotFoundException` / `\Exception` | `Weaviate\Client\Exceptions\AuthenticationException`, typed exceptions ([01](01-architecture.md#errors)); `fetchObjectById` returns `null` when the object isn't found |

## Translating GraphQL `where` filters

| GraphQL `where` | New |
|---|---|
| `{path: ["year"], operator: GreaterThan, valueInt: 2020}` | `Filter::byProperty('year')->greaterThan(2020)` |
| `{operator: And, operands: [...]}` | `Filter::allOf([...])` |
| `{operator: Or, operands: [...]}` | `Filter::anyOf([...])` |
| `{path: ["author", "Author", "name"], operator: Equal, valueText: "x"}` | `Filter::byRef('author')->byProperty('name')->equal('x')` |
| `{path: ["id"], operator: Equal, valueText: $id}` | `Filter::byId()->equal($id)` |
| `{path: ["_creationTimeUnix"], …}` | `Filter::byCreationTime()->…` |

## Running both side by side

This is supported. The new client lives under `Weaviate\Client\` ([ADR 0005](decisions/0005-namespace-and-package-name.md)), and the community package declares its classes directly under `Weaviate\` (`Weaviate\Weaviate`, `Weaviate\Api\…`), so the two don't clash. You can install both, move one feature at a time, then `composer remove timkley/weaviate-php`.

```php
use Weaviate\Weaviate as LegacyWeaviate;   // timkley/weaviate-php
use Weaviate\Client\Weaviate;              // dudanogueira/weaviate-php-client
```

## Outreach

- Contact Tim Kley before 1.0:
  - share the plan;
  - ask whether they'd add a "see the official client" note to their README;
  - invite them to review the API or collaborate.
- Credit the community client in our README.
