# 10: Collections management and configuration specification

This maps **every** collection-management method and **every** `Configure.*` / `Reconfigure.*` factory in Python v4 to PHP. It covers the parameters, their wire keys, the behaviour rules and the edge cases.

**Sources:** these files on the Python client's `main` branch, commit `eb5546a` (2026-09-25, `docs/changelog.rst` head = 4.24.0), read on 2026-09-25:
- `weaviate/collections/collections/{executor,base,sync.pyi}.py`
- `weaviate/collections/config/{executor.py,sync.pyi}`
- `weaviate/collections/classes/config.py`, `config_base.py`, `config_vectors.py`, `config_named_vectors.py`, `config_vectorizers.py`, `config_vector_index.py`, `config_object_ttl.py`, `config_methods.py`
- `weaviate/classes/config.py`, `weaviate/warnings.py`, `weaviate/connect/integrations.py`
- the version gates in `integration/test_collection_config.py`

Rows in [02 §2](02-feature-parity-matrix.md#2-collections-management) link here. Conventions follow [03](03-api-design.md): named arguments, camelCase, backed enums, `final readonly` value objects and static factory namespaces.

---

## 1. The model

There are three kinds of object. They are kept strictly apart, as in Python.

| Kind | Python | PHP | Purpose |
|---|---|---|---|
| **Create** objects | pydantic `*Create` / `_…ConfigCreate` models | `final readonly class …Create` in `Weaviate\Client\Config\Create\…` | What you pass to `collections->create()` and `config->addVector()`. They serialize to REST JSON |
| **Update** objects | pydantic `*Update` models with `merge_with_existing()` | `final readonly class …Update` in `Weaviate\Client\Config\Update\…` | What you pass to `config->update()`. They **merge** into the schema JSON that was fetched, and never serialize alone |
| **Read** objects | dataclasses in `config.py` (`_CollectionConfig`, `_Property`, …) | `final readonly class` in `Weaviate\Client\Config\Read\…` (`CollectionConfig`, `PropertyConfig`, …) | Parsed from `GET /v1/schema[/{c}]`. `toArray()` turns them back into create JSON (Python `to_dict()`) |

The factories are the public entry points: `Configure::…` builds create objects and `Reconfigure::…` builds update objects. Users should never need to construct a `…Create` class themselves. The classes stay public, because Python 4.24 made them public (changelog 4.24.0) and users type-hint helpers with them.

```
Configure::vectors()->text2vecOpenAI(...)  ─┐
new Property(...)                           ├─► CollectionConfigCreate ─► toRestArray(ServerVersion) ─► POST /v1/schema
Configure::invertedIndex(...)              ─┘
Reconfigure::…  ─► CollectionConfigUpdate ─► mergeInto(GET /v1/schema/{c}) ─► PUT /v1/schema/{c}
GET /v1/schema/{c} ─► CollectionConfigParser ─► CollectionConfig ─► toArray() ─► createFromArray()
```

---

## 2. `$client->collections` (Python `client.collections`)

All REST. Paths are relative to `/v1`. Every method that takes a `name` **capitalizes its first letter** first (Python `_capitalize_first_letter`: only the first character is changed, and the rest is left as it is). PHP uses `mb_strtoupper(mb_substr($n, 0, 1)) . mb_substr($n, 1)`.

### 2.1 `create()` (Python `create`)

| Python param | PHP param | Type | Default | Wire key / notes |
|---|---|---|---|---|
| `name` | `name` | `string` | required | `class`. Capitalized |
| `description` | `description` | `?string` | `null` | `description` |
| `properties` | `properties` | `list<Property>` | `[]` | `properties[]` (§4) |
| `references` | `references` | `list<ReferenceProperty\|ReferencePropertyMultiTarget>` | `[]` | Appended to `properties[]` |
| `vector_config` | `vectorConfig` | `VectorConfigCreate\|list<VectorConfigCreate>\|null` | `null` | `vectorConfig{}` (§5) |
| `generative_config` | `generativeConfig` | `?GenerativeProvider` | `null` | `moduleConfig.{generative-*}` (§7) |
| `reranker_config` | `rerankerConfig` | `?RerankerProvider` | `null` | `moduleConfig.{reranker-*}` (§7) |
| `inverted_index_config` | `invertedIndexConfig` | `?InvertedIndexConfigCreate` | `null` | `invertedIndexConfig` (§8) |
| `multi_tenancy_config` | `multiTenancyConfig` | `?MultiTenancyConfigCreate` | `null` | `multiTenancyConfig` (§9.3) |
| `object_ttl_config` | `objectTtlConfig` | `?ObjectTtlConfigCreate` | `null` | `objectTtlConfig` (§9.4) |
| `replication_config` | `replicationConfig` | `?ReplicationConfigCreate` | `null` | `replicationConfig` (§9.1) |
| `sharding_config` | `shardingConfig` | `?ShardingConfigCreate` | `null` | `shardingConfig` (§9.2) |
| `vectorizer_config` (**deprecated**, Dep024) | `vectorizerConfig` | `VectorizerConfigCreate\|list<NamedVectorConfigCreate>\|null` | `null` | Legacy `vectorizer` + `moduleConfig`, or legacy named vectors. See §14 |
| `vector_index_config` (**deprecated**, Dep025) | `vectorIndexConfig` | `?VectorIndexConfigCreate` | `null` | Legacy top-level `vectorIndexType` + `vectorIndexConfig`. See §14 |
| `data_model_properties` / `data_model_references` | — | — | — | Not ported. Use PHPStan `@template` on `Collection` instead ([03](03-api-design.md)) |
| `skip_argument_validation` | `skipArgumentValidation` | `bool` | `false` | Passed on to the returned handle |

Returns a `Collection` handle for the name **echoed by the server** (`response.class`). This is not the name that was passed in, because the server may normalize it.

Sequence:
1. Log a deprecation warning when `vectorizerConfig` or `vectorIndexConfig` is used (Dep024/Dep025).
2. **Gate** (`UnsupportedFeatureException`): any property, including a nested one, has a `textAnalyzer` → needs 1.37.0. `invertedIndexConfig.stopwordPresets` is set → needs 1.37.0. Python checks both of these client-side. §13 lists the extra gates PHP adds.
3. Build `CollectionConfigCreate` and validate it (§2.9). This runs before any I/O.
4. Serialize with `emitDefaultVectorIndexType = !server->isAtLeast(1, 37, 5)` (§11.2).
5. Remove dropped vectors (§2.8).
6. `POST /schema`. Expect 200. On any other status, throw `UnexpectedStatusCodeException` ("Create collection").

### 2.2 `createFromArray()` (Python `create_from_dict`)

`createFromArray(array $config): Collection`. The array is sent **as-is** after the dropped-vector strip (§2.8). There is no validation and no capitalization. It is meant for v3 migrations and for server features the client doesn't model yet. The legacy `NamedVectors` dict shape still works here, because it is just JSON.

### 2.3 `createFromConfig()` (Python `create_from_config`)

`createFromConfig(CollectionConfig $config): Collection`.
- If `$config->vectorConfig === null && $config->vectorizer === null`, throw `InvalidInputException`: "no vector config left; its vectors were dropped…". A config exported after all its vectors were dropped would otherwise make the server fall back to its legacy default index.
- Otherwise it calls `createFromArray($config->toArray())` (§12.3).

### 2.4 `get()` / `use()` (Python `get` / `use`)

`use(string $name, bool $skipArgumentValidation = false): Collection`. `get()` is an alias with the same signature. **Neither does any I/O.** Both validate that `name` is a string and capitalize it. Python keeps both names and deprecates neither, so PHP does the same.

### 2.5 `listAll()` (Python `list_all`)

`listAll(bool $simple = true): array<string, CollectionConfigSimple>|array<string, CollectionConfig>`. Calls `GET /schema` and parses `classes[]`. The result is **keyed by collection name and sorted with `ksort`**, which matches Python's `dict(sorted(...))`. PHPStan return type: `($simple is true ? array<string,CollectionConfigSimple> : array<string,CollectionConfig>)`.

### 2.6 `exists()` / `exportConfig()`

| PHP | REST | Behaviour |
|---|---|---|
| `exists(string $name): bool` | `GET /schema/{Name}` | An empty name throws `InvalidInputException`. 200 returns `true`, 404 returns `false`, and anything else throws. Python 4.23 fixed the async version swallowing non-404 errors, so any other status must throw |
| `exportConfig(string $name): CollectionConfig` | `GET /schema/{Name}` | Returns the full parse (§12). This is the same data as `use($name)->config->get()` |

### 2.7 `delete()` / `deleteAll()`

| PHP | REST | Behaviour |
|---|---|---|
| `delete(string\|list<string> $name): void` | `DELETE /schema/{Name}`, once per name, **sequentially** | Each name is capitalized. The async package may run them in parallel (Python async uses `gather`). Python async forgets to capitalize names in the list case; PHP always capitalizes. Deleting a name that doesn't exist returns 200 on current servers (**unverified**) |
| `deleteAll(): void` | `GET /schema`, then `delete(list)` | Handles that were obtained earlier stop working. Document this with a warning in the docblock |

### 2.8 Dropped-vector handling on create (Python `__without_dropped_vectors`)

This applies to `create()`, `createFromArray()` and `createFromConfig()`. If `vectorConfig` is an array, the client removes every entry whose `vectorIndexType === 'none'`. These are vectors whose index was dropped with `config->deleteVectorIndex()`.
- If no entries are left, throw `InvalidInputException` (the Python message explains the fallback to the legacy index).
- If some entries were removed, log a warning (`Col001`) that names them.

### 2.9 Create validation (all client-side, before I/O)

| Rule | Source |
|---|---|
| A top-level property name is not `id` (`InvalidInputException`; Python raises `WeaviateInsertInvalidPropertyError`) | `_check_top_level_property_names` |
| `Property.name !== 'vector'` | `Property._check_name` |
| `ReferenceProperty*.name` is not in `['id', 'vector']` | `ReferencePropertyBase.check_name` |
| Vector names are unique in a list (the message names the duplicates) | `validate_vector_names` |
| In a **list** of `VectorConfigCreate`, every `name` is set ("Vector config name must be set when specifying multiple vectors"). A single vector with no name becomes `"default"` | `_CollectionConfigCreate._to_dict` |
| `sourceProperties`, when given, has at least 1 item | `min_length=1` |
| `TextAnalyzerConfig`: `asciiFoldIgnore` needs `asciiFold === true` | `_validate_ascii_fold_ignore` |
| `invertedIndex()`: `bm25B` and `bm25K1` are given together or not at all | `Configure.inverted_index` |
| `text2vec-aws`: `region !== ''` | `_Text2VecAWSConfig._check_name` |
| URL params (`baseURL`, `endpointURL`) are valid absolute http(s) URLs (Python uses pydantic `AnyHttpUrl`) | `AnyHttpUrl` fields |
| Strict types. Pydantic `strict=True` rejects `1` for a bool and `True` for an int (changelog 4.23). `declare(strict_types=1)` and native types give PHP the same | `ConfigDict(strict=True)` |

---

## 3. `$collection->config` (Python `collection.config`)

The handle carries the collection name and, when set through `withTenant()`, the tenant. The tenant only affects `getShards()`.

| Python | PHP | REST | Notes |
|---|---|---|---|
| `get(simple=False)` | `get(bool $simple = false): CollectionConfig\|CollectionConfigSimple` | `GET /schema/{c}` | Parse per §12 |
| `update(...)` | `update(...)` (§10) | `GET /schema/{c}`, merge, then `PUT /schema/{c}` | Read-modify-write. It is **not atomic**: a concurrent writer between the GET and the PUT is overwritten. Document this. The server has no ETag (**unverified**) |
| `add_property(prop)` | `addProperty(Property $prop): void` | `GET /schema/{c}`, then `POST /schema/{c}/properties` | See §3.1 |
| `add_reference(ref)` | `addReference(ReferenceProperty\|ReferencePropertyMultiTarget $ref): void` | same | Throws `InvalidInputException` when a reference with that name already exists |
| `add_vector(vector_config=…)` | `addVector(VectorConfigCreate\|list<VectorConfigCreate> $vectorConfig): void` | `GET`, set `vectorConfig[name] = toArray()`, then `PUT /schema/{c}` | Every vector needs a name ("The configured vector must have a name…"). An existing vector name is **overwritten in the PUT body**, and the server rejects the change (**unverified**). The legacy `NamedVectors` overload is deprecated (Dep026) and **not ported**. Min 1.31 |
| `get_shards()` | `getShards(): list<ShardStatus>` | `GET /schema/{c}/shards[?tenant=t]` | `ShardStatus(name, status, vectorQueueSize, perNodeStatus: ?array<string,string>)` |
| `update_shards(status, shard_names=None)` | `updateShards(ShardStatusType $status, string\|list<string>\|null $shardNames = null): array<string, ShardStatusType>` | `PUT /schema/{c}/shards/{shard}` body `{"status": …}`, once per shard | `null` means every shard from `getShards()`. Only `Ready` and `ReadOnly` are accepted (else `InvalidInputException`). Returns `name => status` from each response |
| `delete_property_index(property_name, index_name)` | `deletePropertyIndex(string $propertyName, IndexName $indexName): bool` | `DELETE /schema/{c}/properties/{p}/index/{searchable\|filterable\|rangeFilters}` | Returns `true` on 200 and throws otherwise. Destructive. Min 1.36 |
| `delete_vector_index(vector_name)` | `deleteVectorIndex(string $vectorName): void` | `DELETE /schema/{c}/vectors/{v}/index` | Destructive and irreversible. The drop is **asynchronous**: `get()` shows `VectorIndexConfigNone` until cleanup finishes, and then the vector disappears. Repeating the call during the drop succeeds; after the drop it returns 422. Named vectors only. It's experimental and can be disabled server-side. Min 1.39 |

### 3.1 `addProperty` / `addReference` algorithm (port exactly)

1. Validate the type. For a `Property` with a `textAnalyzer` (including nested ones), gate on 1.37.0.
2. `GET /schema/{c}`. If a property (or reference) with the same name exists, throw `InvalidInputException` ("Property with name 'x' already exists in collection 'C'.").
3. Build the body with `Property::toRestArray(vectorizers: null)`. Pull out `skipVectorization` and `vectorizePropertyName` into `modconf = {skip, vectorizePropertyName}`. For a `Property`, both keys are always present because the defaults are `false` and `true`.
4. If the existing `moduleConfig` has a key that contains neither `generative` nor `reranker` (a legacy vectorizer) and `modconf` isn't empty, set `body.moduleConfig = {<firstSuchKey>: modconf}`.
5. If the existing `vectorConfig` isn't empty, set `body.moduleConfig = {<module of each named vector>: modconf}`, **overwriting step 4**. For a reference, `modconf` is `{}`.
6. `POST /schema/{c}/properties`.

---

## 4. Properties and references

### 4.1 `Property` (Python `Property`)

`new Property(...)` is a `final readonly class` with a validating constructor.

| Python field | PHP param | Type | Default | Wire |
|---|---|---|---|---|
| `name` | `name` | `string` | required | `name`. `'vector'` is rejected, and `'id'` is rejected at the top level (§2.9) |
| `data_type` | `dataType` | `DataType` | required | `dataType: ["<value>"]`, always a one-element list |
| `description` | `description` | `?string` | `null` | `description` |
| `index_filterable` | `indexFilterable` | `?bool` | `null` (server default) | `indexFilterable` |
| `index_searchable` | `indexSearchable` | `?bool` | `null` | `indexSearchable` |
| `index_range_filters` | `indexRangeFilters` | `?bool` | `null` | `indexRangeFilters` |
| `nested_properties` | `nestedProperties` | `Property\|list<Property>\|null` | `null` | `nestedProperties[]`, recursive. Valid for `Object`/`ObjectArray` |
| `skip_vectorization` | `skipVectorization` | `bool` | `false` | `moduleConfig.{module}.skip` (§11.3) |
| `vectorize_property_name` | `vectorizePropertyName` | `bool` | `true` | `moduleConfig.{module}.vectorizePropertyName` (§11.3) |
| `tokenization` | `tokenization` | `?Tokenization` | `null` | `tokenization` |
| `text_analyzer` | `textAnalyzer` | `?TextAnalyzerConfigCreate` | `null` | `textAnalyzer` (§8.3). Min 1.37. Immutable after creation |

### 4.2 References

Python has **two classes**. PHP keeps both for parity, and **does not** use the `targetCollection: string|array` shape that [02](02-feature-parity-matrix.md) proposed.

| Python | PHP | Wire |
|---|---|---|
| `ReferenceProperty(name, target_collection, description=None)` | `new ReferenceProperty(name:, targetCollection:, description: null)` | `{"name", "dataType": ["<Target capitalized>"], "description"?}` |
| `ReferenceProperty.MultiTarget(name, target_collections, description=None)` / `ReferencePropertyMultiTarget` | `new ReferencePropertyMultiTarget(name:, targetCollections:, description: null)` plus the static sugar `ReferenceProperty::multiTarget(...)` | `{"name", "dataType": ["A", "B"], …}` |

Both reject the names `id` and `vector`. Target names are capitalized.

### 4.3 Enums

| Python | PHP backed enum | Cases → value |
|---|---|---|
| `DataType` | `DataType: string` | `Text`=`text`, `TextArray`=`text[]`, `Int`=`int`, `IntArray`=`int[]`, `Bool`=`boolean`, `BoolArray`=`boolean[]`, `Number`=`number`, `NumberArray`=`number[]`, `Date`=`date`, `DateArray`=`date[]`, `Uuid`=`uuid`, `UuidArray`=`uuid[]`, `GeoCoordinates`=`geoCoordinates`, `Blob`=`blob`, `BlobHash`=`blobHash` (1.37), `PhoneNumber`=`phoneNumber`, `Object`=`object`, `ObjectArray`=`object[]` |
| `Tokenization` | `Tokenization: string` | `Word`=`word`, `Whitespace`, `Lowercase`, `Field`, `Gse`=`gse`, `Trigram`, `KagomeJa`=`kagome_ja`, `KagomeKr`=`kagome_kr` (≥1.25.8), `GseCh`=`gse_ch` |
| `IndexName` (Literal) | `IndexName: string` | `Searchable`=`searchable`, `Filterable`=`filterable`, `RangeFilters`=`rangeFilters` |
| `ShardTypes` (Literal) | `ShardStatusType: string` | `Ready`=`READY`, `ReadOnly`=`READONLY`, `Indexing`=`INDEXING` (read only) |

PHP's context-sensitive lexer should allow `case Object` in an enum; confirm this in P1. If it doesn't, use `ObjectType`.

---

## 5. Vectors (`Configure::vectors()`, `Configure::multiVectors()`)

### 5.1 `VectorConfigCreate` and the shared parameters

Every factory returns `VectorConfigCreate(name, vectorizer: VectorizerConfig, sourceProperties, vectorIndexConfig)`.

| Python param | PHP param | Type | Default | Applies to |
|---|---|---|---|---|
| `name` | `name` | `?string` | `null`, which becomes `"default"` when used alone | all |
| `quantizer` | `quantizer` | `?QuantizerConfigCreate` | `null` | all |
| `vector_index_config` | `vectorIndexConfig` | `?VectorIndexConfigCreate` | `null` | all |
| `source_properties` | `sourceProperties` | `?list<string>` (min 1) | `null` | `text2vec-*`, `custom`, `text2colbert` → wire `vectorizer.{m}.properties` |
| `vectorize_collection_name` | `vectorizeCollectionName` | `bool` | `true` | `text2vec-*` and `text2colbert` → wire `vectorizeClassName` (always emitted). `multi2vec_cohere` accepts it but **ignores** it, and PHP does the same |

**Merging a quantizer with an index** (Python `_IndexWrappers.single`), which PHP must port exactly:
- `quantizer` with no `vectorIndexConfig`: create a bare HNSW index that holds only the quantizer.
- `quantizer` with a `vectorIndexConfig`: set it on that index, **overriding** the index's own quantizer.
- For a `dynamic` index, set it on **both** `hnsw` and `flat`, and create bare sub-configs when they're missing.

Wire shape of one vector:
```json
{"vectorizer": {"<module>": {…options, "properties": [...]}}, "vectorIndexType": "hnsw", "vectorIndexConfig": {…}}
```
`vectorIndexType` is omitted when there's no index config and the server is 1.37.5 or later (§11.2).

### 5.2 `Configure::vectors()`: `text2vec-*`, `custom`, `self_provided`

Every row also takes the shared parameters in §5.1. "Params" lists the Python name → wire key, with a PHP name that is the camelCase of the Python name. `*` means required. Unmarked parameters are optional and default to `null`, which means they're left out of the JSON and the server default applies.

| Python method → PHP | Module (wire) | Params |
|---|---|---|
| `self_provided` → `selfProvided` | `none` | — (no `sourceProperties`/`vectorizeCollectionName`) |
| `custom` → `custom` | `{module_name}` | `module_name`* (string), `module_config` (array, sent verbatim). Takes `sourceProperties`, not `vectorizeCollectionName` |
| `text2vec_aws_bedrock` → `text2vecAwsBedrock` | `text2vec-aws` | `model`*→`model`, `region`*→`region`, `dimensions`; fixed `service: "bedrock"` |
| `text2vec_aws_sagemaker` → `text2vecAwsSagemaker` | `text2vec-aws` | `endpoint`*, `region`*, `target_model`→`targetModel`, `target_variant`→`targetVariant`, `dimensions`; fixed `service: "sagemaker"` |
| `text2vec_aws` (**deprecated**, removed after Q3'26; **not ported**, §14) | `text2vec-aws` | `model`* (Optional, but has no default), `region`*, `endpoint`, `service`=`'bedrock'`, `dimensions` |
| `text2vec_azure_openai` → `text2vecAzureOpenAI` | `text2vec-openai` | `resource_name`*→`resourceName`, `deployment_id`*→`deploymentId`, `base_url`→`baseURL`, `dimensions`, `model`; always adds `isAzure: true` |
| `text2vec_cohere` → `text2vecCohere` | `text2vec-cohere` | `base_url`→`baseURL`, `model`, `dimensions`, `truncate` (`NONE\|START\|END\|LEFT\|RIGHT`) |
| `text2vec_contextionary` → `text2vecContextionary` (**deprecated**) | `text2vec-contextionary` | — |
| `text2vec_databricks` → `text2vecDatabricks` | `text2vec-databricks` | `endpoint`*, `instruction` |
| `text2vec_digitalocean` → `text2vecDigitalOcean` | `text2vec-digitalocean` | `model`*, `base_url`→`baseURL` |
| `text2vec_gpt4all` (**deprecated**, removed after Q3'26; **not ported**, §14) | `text2vec-gpt4all` | — |
| `text2vec_google_vertex` → `text2vecGoogleVertex` | `text2vec-palm` | `project_id`*→`projectId`, `api_endpoint`→`apiEndpoint`, `dimensions`, `model`→`modelId`, `title_property`→`titleProperty`, `task_type`→`taskType`, `location` |
| `text2vec_google_gemini` → `text2vecGoogleGemini` | `text2vec-palm` | `dimensions`, `model`→`modelId`, `title_property`, `task_type`; fixed `apiEndpoint: "generativelanguage.googleapis.com"` |
| `text2vec_google` (**deprecated**; **not ported**, §14) | `text2vec-palm` | Same as vertex |
| `text2vec_google_aistudio` (**deprecated**; **not ported**, §14) | `text2vec-palm` | Same as gemini |
| `text2vec_huggingface` → `text2vecHuggingFace` | `text2vec-huggingface` | `model`, `passage_model`→`passageModel`, `query_model`→`queryModel`, `endpoint_url`→`endpointURL`, `wait_for_model`/`use_gpu`/`use_cache` → **nested** `options.{waitForModel,useGPU,useCache}` (the `options` key is only added when at least one is set) |
| `text2vec_jinaai` → `text2vecJinaAI` | `text2vec-jinaai` | `base_url`→`baseURL`, `dimensions`, `model` |
| `text2vec_mistral` → `text2vecMistral` | `text2vec-mistral` | `base_url`, `model` |
| `text2vec_model2vec` → `text2vecModel2Vec` | `text2vec-model2vec` | `inference_url`→`inferenceUrl` |
| `text2vec_morph` → `text2vecMorph` | `text2vec-morph` | `base_url`, `model`, `endpoint` |
| `text2vec_nvidia` → `text2vecNvidia` | `text2vec-nvidia` | `base_url`, `model`, `truncate` (bool) |
| `text2vec_ollama` → `text2vecOllama` | `text2vec-ollama` | `api_endpoint`→`apiEndpoint`, `model` |
| `text2vec_openai` → `text2vecOpenAI` | `text2vec-openai` | `base_url`, `dimensions`, `endpoint`, `model`, `model_version`→`modelVersion`, `type_`→`type` (`text\|code`; PHP param `type`); always adds `isAzure: false` |
| `text2vec_transformers` → `text2vecTransformers` | `text2vec-transformers` | `pooling_strategy`=`'masked_mean'` (`masked_mean\|cls`, **always emitted**), `dimensions`, `inference_url`→`inferenceUrl`, `passage_inference_url`→`passageInferenceUrl`, `query_inference_url`→`queryInferenceUrl` |
| `text2vec_voyageai` → `text2vecVoyageAI` | `text2vec-voyageai` | `base_url`, `dimensions`, `model`, `truncate` (bool) |
| `text2vec_weaviate` → `text2vecWeaviate` | `text2vec-weaviate` | `base_url`, `dimensions`, `model` |

Model-name `Literal`s (`CohereModel`, `OpenAIModel`, `JinaModel`, `VoyageModel`, `AWSModel`, `WeaviateModel`, …) are **only hints** in Python, typed `Union[Literal, str]`. PHP types them as `?string` and lists the known values in `@param` docblocks. No enums are used here, because providers add models constantly.

### 5.3 `Configure::vectors()`: `multi2vec-*`, `img2vec-*`, `ref2vec-*`

These take the shared `name`, `quantizer` and `vectorIndexConfig`, but **not** `sourceProperties` or `vectorizeCollectionName`. Each `*_fields` param is `list<string|Multi2VecField>`. `Multi2VecField(name, weight: ?float)` serializes to `"<x>Fields": [names]`. When any weight is given, it also adds `"weights": {"<x>Fields": [w…]}`, which lists **only the fields that have weights**. Python behaves this way too; it's fragile but it's what the wire format expects.

| Python method → PHP | Module | Params |
|---|---|---|
| `img2vec_neural` → `img2vecNeural` | `img2vec-neural` | `image_fields`* (`list<string>`) → `imageFields` |
| `multi2vec_aws_bedrock` → `multi2vecAwsBedrock` | `multi2vec-aws` | `region`, `model`, `dimensions`, `image_fields`, `text_fields` |
| `multi2vec_aws` (**deprecated**; **not ported**, §14) | `multi2vec-aws` | same |
| `multi2vec_bind` → `multi2vecBind` | `multi2vec-bind` | `audio_fields`, `depth_fields`, `image_fields`, `imu_fields`→**`IMUFields`**, `text_fields`, `thermal_fields`, `video_fields` |
| `multi2vec_clip` → `multi2vecClip` | `multi2vec-clip` | `inference_url`→`inferenceUrl`, `image_fields`, `text_fields` |
| `multi2vec_cohere` → `multi2vecCohere` | `multi2vec-cohere` | `base_url`→`baseURL`, `model`, `dimensions`, `truncate`, `image_fields`, `text_fields` (+ ignored `vectorize_collection_name`) |
| `multi2vec_google` → `multi2vecGoogle` | `multi2vec-palm` | `project_id`*→`projectId`, `location`*, `model`→`modelId`, `dimensions`, `video_interval_seconds`→`videoIntervalSeconds`, `audio/image/text/video_fields` |
| `multi2vec_google_gemini` → `multi2vecGoogleGemini` | `multi2vec-palm` | Same without project/location; fixed `apiEndpoint: "generativelanguage.googleapis.com"` |
| `multi2vec_jinaai` → `multi2vecJinaAI` | `multi2vec-jinaai` | `base_url`, `model`, `dimensions`, `image_fields`, `text_fields` |
| `multi2vec_nvidia` → `multi2vecNvidia` | `multi2vec-nvidia` | `base_url`, `model`, `truncation` (bool), `image_fields`, `text_fields` |
| `multi2vec_twelvelabs` → `multi2vecTwelveLabs` | `multi2vec-twelvelabs` | `base_url`, `model`, `image_fields`, `text_fields` |
| `multi2vec_voyageai` → `multi2vecVoyageAI` | `multi2vec-voyageai` | `base_url`, `model`, `dimensions`, `truncation` (bool), `image_fields`, `text_fields`, `video_fields` |
| `ref2vec_centroid` → `ref2vecCentroid` | `ref2vec-centroid` | `reference_properties`*→`referenceProperties`, `method`=`'mean'` (always emitted) |

### 5.4 `Configure::multiVectors()` (ColBERT-style, 1.29 and later)

These take `name`, `quantizer`, `vectorIndexConfig`, `encoding: ?MultiVectorEncodingCreate` (muvera, §6.4) and `multiVectorConfig: ?MultiVectorConfigCreate` (§6.4).

**Behaviour** (`_IndexWrappers.multi`):
1. If `multiVectorConfig` is null, create an empty `{enabled: true}`.
2. If an `encoding` is given, set it on that config.
3. If `vectorIndexConfig` is null, create a bare HNSW index with that `multivector`. Otherwise set the `multivector` on the given index.
4. Then apply the quantizer, as in §5.1.

The wire result always includes `vectorIndexConfig.multivector.enabled = true`.

| Python method → PHP | Module | Params |
|---|---|---|
| `self_provided` → `selfProvided` | `none` | — |
| `text2vec_jinaai` → `text2vecJinaAI` | **`text2colbert-jinaai`** | `model`, `dimensions`, `sourceProperties`, `vectorizeCollectionName` |
| `multi2vec_jinaai` → `multi2vecJinaAI` | **`multi2multivec-jinaai`** | `base_url`, `model`, `image_fields`, `text_fields` |
| `multi2vec_weaviate` → `multi2vecWeaviate` | **`multi2multivec-weaviate`** (1.35) | `image_field`* (**a single string**, sent as `imageFields: [x]`), `base_url`, `model` |

### 5.5 `Vectorizers` enum (wire module names)

`enum Vectorizers: string`, a 1:1 copy of Python: `None`=`none`, `Text2ColbertJinaAI`, `Text2VecAws`, `Text2VecCohere`, `Text2VecContextionary`, `Text2VecDatabricks`, `Text2VecDigitalOcean`, `Text2VecGpt4All`, `Text2VecHuggingFace`, `Text2VecMistral`, `Text2VecMorph`, `Text2VecModel2Vec`, `Text2VecNvidia`, `Text2VecOllama`, `Text2VecOpenAI`, `Text2VecPalm`=`text2vec-palm`, `Text2VecTransformers`, `Text2VecJinaAI`, `Text2VecVoyageAI`, `Text2VecWeaviate`, `Img2VecNeural`, `Multi2VecAws`, `Multi2VecClip`, `Multi2VecCohere`, `Multi2VecJinaAI`, `Multi2MultiVecJinaAI`=`multi2multivec-jinaai`, `Multi2MultiVecWeaviate`, `Multi2VecBind`, `Multi2VecPalm`, `Multi2VecVoyageAI`, `Multi2VecNvidia`, `Multi2VecTwelveLabs`, `Ref2VecCentroid`. That is 33 cases. Read objects type this as `Vectorizers|string`, using `tryFrom`, so modules the client doesn't know about still parse.

Google modules use the historical wire names `text2vec-palm`, `multi2vec-palm` and `generative-palm`. Keep them, because Python does ("rename to google once all versions support it").

---

## 6. Vector index, quantizers, multi-vector

`enum VectorDistances: string`: `Cosine`=`cosine`, `Dot`=`dot`, `L2Squared`=`l2-squared`, `Hamming`=`hamming`, `Manhattan`=`manhattan`.
`enum VectorFilterStrategy: string`: `Sweeping`, `Acorn`, `Pathseer` (1.40).
`enum VectorIndexType: string`: `Hnsw`, `Flat`, `Dynamic`, `Hfresh`, `None`. `None` is reported by the server for a dropped index and can't be used to configure one.

### 6.1 `Configure::vectorIndex()` (Python `Configure.VectorIndex`)

The **Upd** column shows whether `Reconfigure::vectorIndex()` accepts the parameter. Every parameter is `?int` or `?bool` and defaults to `null`, which means the server default.

| Factory | Python param → PHP | Wire key | Upd |
|---|---|---|---|
| `hnsw()` | `cleanup_interval_seconds` → `cleanupIntervalSeconds` | `cleanupIntervalSeconds` | ✗ |
| | `distance_metric` → `distanceMetric: ?VectorDistances` | `distance` | ✗ |
| | `dynamic_ef_factor`, `dynamic_ef_min`, `dynamic_ef_max` | `dynamicEfFactor`/`Min`/`Max` | ✓ |
| | `ef` | `ef` | ✓ |
| | `ef_construction` | `efConstruction` | ✗ |
| | `filter_strategy: ?VectorFilterStrategy` | `filterStrategy` | ✓ |
| | `flat_search_cutoff` | `flatSearchCutoff` | ✓ |
| | `max_connections` | `maxConnections` | ✗ |
| | `vector_cache_max_objects` | `vectorCacheMaxObjects` | ✓ |
| | `quantizer: pq\|bq\|sq\|rq\|none` | `pq`/`bq`/`sq`/`rq`/`skipDefaultQuantization` | ✓ (pq/bq/sq/rq) |
| | `multi_vector` (**deprecated**, Dep027; use `multiVectors()`) | `multivector` | ✗ |
| `flat()` | `distance_metric`, `vector_cache_max_objects`, `quantizer` | as above | cache ✓; quantizer ✓ (**bq, rq only**) |
| `dynamic()` (≥1.25) | `distance_metric`, `threshold`, `hnsw: ?VectorIndexConfigHnswCreate`, `flat: ?VectorIndexConfigFlatCreate` | `distance`, `threshold`, `hnsw{}`, `flat{}` | `threshold` ✓, `hnsw` ✓, `flat` ✓, top-level `quantizer` ✓ (**bq only**) |
| `hfresh()` (≥1.36) | `distance_metric`, `max_posting_size_kb` → `maxPostingSizeKB`, `replicas`, `search_probe`, `quantizer`, `multi_vector` | `distance`, **`maxPostingSizeKB`**, `replicas`, `searchProbe` | `maxPostingSizeKB` ✓, `searchProbe` ✓, quantizer ✓ (**rq only**); `replicas` ✗ |
| `none()` | — | `{"skip": true}` with `vectorIndexType: "hnsw"` | — |

The Python `dynamic()` factory never sets a top-level quantizer on create. Use `quantizer:` on the vector, which then applies to both sub-indexes (§5.1).

### 6.2 Quantizers: `Configure::quantizer()` (Python `Configure.VectorIndex.Quantizer`)

Every enabled quantizer serializes as `"<name>": {"enabled": true, …}`.

| Factory | Python params → wire | Min server | Notes |
|---|---|---|---|
| `pq()` | `centroids`, `segments`, `training_limit`→`trainingLimit`, `encoder_type: ?PQEncoderType` → `encoder.type`, `encoder_distribution: ?PQEncoderDistribution` → `encoder.distribution`, `bit_compression` (**deprecated** Dep019; **not accepted** in PHP, see §14) | — | `encoder` is **always** emitted, possibly as `{}`. **Python bug:** pydantic dumps the field as `encoder.type_` instead of `type`, so the encoder type is silently ignored on create. Python's integration test comments this as a "potential weaviate bug". PHP **must emit `type`** |
| `bq()` | `cache`, `rescore_limit`→`rescoreLimit` | — | |
| `sq()` | `rescore_limit`, `training_limit`; `cache` (**deprecated**; **not accepted** in PHP, see §14) | 1.26 | |
| `rq()` | `bits`, `rescore_limit`, `training_limit`, `centering`, `cache` | hnsw 1.32; flat 1.34; 1-bit 1.34; `centering` 1.39.2 | |
| `none()` | — | 1.32.4 (the changelog says 1.33) | Serializes as `"skipDefaultQuantization": true`. It opts out of a server-default quantizer |

Enums: `PQEncoderType`: `Kmeans`=`kmeans`, `Tile`=`tile`. `PQEncoderDistribution`: `LogNormal`=`log-normal`, `Normal`=`normal`.

### 6.3 `Reconfigure::quantizer()` (Python `Reconfigure.VectorIndex.Quantizer`)

| Factory | Params (all nullable) | Always-set field |
|---|---|---|
| `pq()` | `centroids`, `segments`, `trainingLimit`, `encoderType`, `encoderDistribution` (merged into `encoder.type`/`distribution`), `bitCompression` (deprecated, ignored) | `enabled: bool = true` |
| `bq()` | `rescoreLimit` | `enabled = true` |
| `sq()` | `rescoreLimit`, `trainingLimit` | `enabled = true` |
| `rq()` | `rescoreLimit`, `bits`, `centering`, `trainingLimit` | `enabled = true` |

Merge rules (`_ConfigUpdateModel.merge_with_existing` and `__check_quantizers`):
1. **Switching quantizers is forbidden.** If the update carries quantizer X and the existing index config has any **other** quantizer with `enabled: true`, throw `InvalidInputException` ("…To do this, you must recreate the collection"). Enabling a quantizer on an uncompressed index is allowed.
2. Merge the update's non-null fields into `existing[X]`. PHP uses `existing[X] ?? []`; Python indexes it directly and raised `KeyError` on HFresh before 4.23.1.
3. For each of `pq`, `bq` and `sq` that is **not X and is present**, set `enabled: false`. Python leaves out `rq` in this step, but step 1 already guarantees that it isn't enabled.

### 6.4 Multi-vector config (Python `Configure.VectorIndex.MultiVector`)

| Python | PHP | Wire |
|---|---|---|
| `MultiVector.multi_vector(encoding=None, aggregation=None)` | `Configure::multiVector()->config(aggregation: ?MultiVectorAggregation)` | `multivector: {"enabled": true, "aggregation"?}` |
| `MultiVector.Encoding.muvera(ksim, dprojections, repetitions)` | `Configure::multiVector()->muvera(ksim:, dprojections:, repetitions:)` | `multivector.muvera: {"enabled": true, "ksim"?, "dprojections"?, "repetitions"?}` (1.31) |
| `multi_vector(encoding=…)` (**deprecated**, Dep026) | not ported | — |

`enum MultiVectorAggregation: string`: `MaxSim`=`maxSim`. This is the only case in Python.

---

## 7. Generative and reranker modules

`Configure::generative()` and `Reconfigure::generative()` are **the same factory**, as are `Configure::reranker()` and `Reconfigure::reranker()`. Python aliases them the same way.

Wire: `moduleConfig.{module}: {…params}`. Null params are left out, and base URLs are normalized to strings. On update, the provider **replaces** every existing `generative-*` (or `reranker-*`) key (§10.2).

### 7.1 `Configure::generative()` (Python `Configure.Generative`)

Params use Python name → wire key. The PHP name is the camelCase form. `*` means required.

| Python → PHP | Module | Params |
|---|---|---|
| `anthropic` → `anthropic` | `generative-anthropic` | `model`, `max_tokens`→`maxTokens`, `stop_sequences`→`stopSequences`, `temperature`, `top_k`→`topK`, `top_p`→`topP`, `base_url`→`baseURL` |
| `anyscale` → `anyscale` | `generative-anyscale` | `model`, `temperature`, `base_url` |
| `aws_bedrock` → `awsBedrock` | `generative-aws` | `model`*, `region`*, `temperature`, `max_tokens`, `top_k`, `top_p`, `stop_sequences`; fixed `service: "bedrock"` |
| `aws_sagemaker` → `awsSagemaker` | `generative-aws` | `region`*, `endpoint`*, `max_tokens`, `target_model`→`targetModel`, `target_variant`→`targetVariant`, `temperature`, `top_k`, `top_p`, `stop_sequences`; fixed `service: "sagemaker"` |
| `aws` (**deprecated**; **not ported**, §14) | `generative-aws` | `model`, `region`=`''`, `endpoint`, `service`=`'bedrock'`, `max_tokens` |
| `azure_openai` → `azureOpenAI` | `generative-openai` | `resource_name`*→`resourceName`, `deployment_id`*→`deploymentId`, `api_version`→`apiVersion`, `base_url`, `frequency_penalty`→`frequencyPenalty`, `presence_penalty`→`presencePenalty`, `max_tokens`, `temperature`, `top_p` |
| `cohere` → `cohere` | `generative-cohere` | `model`, `k`, `max_tokens`, `stop_sequences`, `temperature`, `base_url`; `return_likelihoods` is **accepted and ignored** |
| `contextualai` → `contextualAI` | `generative-contextualai` | `model`, `temperature`, `top_p`, `max_new_tokens`→`maxNewTokens`, `system_prompt`→`systemPrompt`, `avoid_commentary`→`avoidCommentary`, `knowledge` (list) |
| `custom` → `custom` | `{module_name}` | `module_name`*, `module_config` (verbatim) |
| `databricks` → `databricks` | `generative-databricks` | `endpoint`*, `max_tokens`, `temperature`, `top_k`, `top_p` |
| `deepseek` → `deepseek` | `generative-deepseek` | `base_url`, `model`, `temperature`, `max_tokens`, `frequency_penalty`, `presence_penalty`, `top_p`, `stop` (list) |
| `digitalocean` → `digitalOcean` | `generative-digitalocean` | Same set as deepseek |
| `friendliai` → `friendliAI` | `generative-friendliai` | `base_url`, `model`, `temperature`, `max_tokens` |
| `google_vertex` → `googleVertex` | `generative-palm` | `project_id`*→`projectId`, `api_endpoint`, `region`, `location`, `max_output_tokens`→`maxOutputTokens`, `model_id`→`modelId`, `endpoint_id`→`endpointId`, `temperature`, `top_k`, `top_p` |
| `google_gemini` → `googleGemini` | `generative-palm` | `max_output_tokens`, `model`→`modelId`, `temperature`, `top_k`, `top_p`; **emits `projectId: ""`** (port as is) |
| `google` / `palm` (**deprecated**; **not ported**, §14) | `generative-palm` | `project_id`*, `api_endpoint`, `max_output_tokens`, `model_id`, `temperature`, `top_k`, `top_p` |
| `meta` → `meta` | `generative-meta` | `base_url`, `model`, `temperature`, `top_p`, `max_tokens`, `frequency_penalty`, `presence_penalty`, `reasoning_effort` (`none\|minimal\|low\|medium\|high\|xhigh`) |
| `mistral` → `mistral` | `generative-mistral` | `model`, `temperature`, `max_tokens`, `base_url` |
| `nvidia` → `nvidia` | `generative-nvidia` | `base_url`, `model`, `temperature`, `max_tokens`, `top_p` |
| `ollama` → `ollama` | `generative-ollama` | `api_endpoint`→`apiEndpoint`, `model` |
| `openai` → `openAI` | `generative-openai` | `model`, `frequency_penalty`, `presence_penalty`, `max_tokens`, `temperature`, `top_p`, `base_url`, `verbosity` (`low\|medium\|high`), `reasoning_effort` (`minimal\|low\|medium\|high`) |
| `xai` → `xai` | `generative-xai` | `base_url`, `model`, `temperature`, `max_tokens`, `top_p` |

`enum GenerativeSearches: string` has `Aws`, `Anthropic`, `Anyscale`, `Cohere`, `ContextualAI`, `Databricks`, `Deepseek`, `DigitalOcean`, `Dummy`, `FriendliAI`, `Meta`, `Mistral`, `Nvidia`, `Ollama`, `OpenAI`, `Palm`=`generative-palm` and `Xai`. The string sets for `reasoningEffort` and `verbosity` are `?string` params with docblock hints; Python types them as `Union[Literal, str]`.

This is the **collection-level** config. The per-query `GenerativeConfig::…` used in `generativeProvider:` is a separate factory with its own parameters, and is covered by the generate spec.

### 7.2 `Configure::reranker()` (Python `Configure.Reranker`)

| Python → PHP | Module | Params |
|---|---|---|
| `cohere` | `reranker-cohere` | `model` (hints: `rerank-english-v2.0`, `rerank-multilingual-v2.0`), `base_url`→`baseURL` |
| `contextualai` → `contextualAI` | `reranker-contextualai` | `model`, `instruction`, `top_n`→`topN` |
| `custom` | `{module_name}` | `module_name`*, `module_config` |
| `jinaai` → `jinaAI` | `reranker-jinaai` | `model` |
| `nvidia` | `reranker-nvidia` | `model`, `base_url` |
| `transformers` | `reranker-transformers` | — (sends `{}`) |
| `voyageai` → `voyageAI` | `reranker-voyageai` | `model` |

`enum Rerankers: string`: `None`=`none`, `Cohere`, `ContextualAI`, `Transformers`, `VoyageAI`, `JinaAI`, `Nvidia`.

---

## 8. Inverted index, BM25, stopwords, text analyzer

### 8.1 `Configure::invertedIndex()` (Python `Configure.inverted_index`)

| Python param | PHP param | Type | Wire | Upd |
|---|---|---|---|---|
| `bm25_b`, `bm25_k1` | `bm25B`, `bm25K1` | `?float` | `bm25: {b, k1}`. Both or neither on create | ✓ (each on its own) |
| `cleanup_interval_seconds` | `cleanupIntervalSeconds` | `?int` | `cleanupIntervalSeconds` | ✓ |
| `index_timestamps` | `indexTimestamps` | `?bool` | `indexTimestamps` | ✗ |
| `index_property_length` | `indexPropertyLength` | `?bool` | `indexPropertyLength` | ✗ |
| `index_null_state` | `indexNullState` | `?bool` | `indexNullState` | ✗ |
| `stopwords_preset` | `stopwordsPreset` | `?StopwordsPreset` | `stopwords.preset` | ✓ |
| `stopwords_additions` | `stopwordsAdditions` | `?list<string>` | `stopwords.additions` | ✓ |
| `stopwords_removals` | `stopwordsRemovals` | `?list<string>` | `stopwords.removals` | ✓ |
| `stopword_presets` | `stopwordPresets` | `?array<string, list<string>>` | `stopwordPresets`. Min 1.37 (gated) | ✓ (**replaces** the whole map; the server rejects removing a preset a property still uses) |

On create, `stopwords` is **always emitted**, as `{}` when empty.

`enum StopwordsPreset: string`: `None`=`none`, `En`=`en`.

### 8.2 `Reconfigure::invertedIndex()`

This takes the same parameters, except `indexTimestamps`, `indexPropertyLength` and `indexNullState`, which are immutable. Each field merges on its own into `invertedIndexConfig.bm25` and `invertedIndexConfig.stopwords`.

### 8.3 `Configure::textAnalyzer()` (Python `Configure.text_analyzer`, 1.37)

| Python | PHP | Wire (`property.textAnalyzer`) |
|---|---|---|
| `ascii_fold` | `asciiFold: ?bool` | `asciiFold` |
| `ascii_fold_ignore` | `asciiFoldIgnore: ?list<string>` (needs `asciiFold: true`) | `asciiFoldIgnore` |
| `stopword_preset` | `stopwordPreset: StopwordsPreset\|string\|null` (a built-in or user preset name; `Word` tokenization only) | `stopwordPreset` |

This is immutable after the property is created.

---

## 9. Replication, sharding, multi-tenancy, object TTL

### 9.1 Replication

| Python | PHP | Type | Wire (`replicationConfig`) | Upd |
|---|---|---|---|---|
| `factor` | `factor` | `?int` | `factor` | ✓ |
| `async_enabled` (**deprecated**, Dep030; dropped by 1.38 and later servers) | `asyncEnabled` | `?bool` | `asyncEnabled` | ✓ |
| `deletion_strategy` | `deletionStrategy` | `?ReplicationDeletionStrategy` | `deletionStrategy` | ✓ |
| `async_config` | `asyncConfig` | `?AsyncReplicationConfigCreate` | `asyncConfig{}` (1.36, backported to 1.34.18) | ✓ (**replaces the whole object**, so omitted fields go back to server defaults) |

`enum ReplicationDeletionStrategy: string`: `DeleteOnConflict`, `NoAutomatedResolution`, `TimeBasedResolution`.

`Configure::replicationAsyncConfig(...)` (Python `Configure.Replication.async_config`) and `Reconfigure::replicationAsyncConfig(...)` take these named `?int` arguments, which map one to one onto camelCase wire keys: `maxWorkers`\*, `hashtreeHeight`, `frequency`, `frequencyWhilePropagating`, `aliveNodesCheckingFrequency`\*, `loggingFrequency`, `diffBatchSize`, `diffPerNodeTimeout`, `prePropagationTimeout`, `propagationTimeout`, `propagationLimit`, `propagationDelay`, `propagationConcurrency`, `propagationBatchSize`.

\* These are deprecated (Dep029). Servers 1.37.3 and later drop them, so passing them logs a warning.

PHP can't have both a `Configure::replication()` method and a `Configure::Replication` namespace, as Python does. That is why the method is named `replicationAsyncConfig`.

### 9.2 Sharding (`Configure::sharding()`, create only)

| Python | PHP | Wire (`shardingConfig`) |
|---|---|---|
| `virtual_per_physical` | `virtualPerPhysical: ?int` | `virtualPerPhysical` |
| `desired_count` | `desiredCount: ?int` | `desiredCount` |
| `desired_virtual_count` | `desiredVirtualCount: ?int` | `desiredVirtualCount` |
| `actual_count`, `actual_virtual_count` (**deprecated**, Dep018, read-only, no effect) | not ported | — |
| (fixed) | — | `key: "_id"`, `strategy: "hash"`, `function: "murmur3"` are **always emitted** |

The Python docstring says sharding and replication can't be combined ("You can only use one of Sharding or Replication"). **This is unverified against current servers.** Don't enforce it; document it.

### 9.3 Multi-tenancy

| Factory | Params | Wire (`multiTenancyConfig`) |
|---|---|---|
| `Configure::multiTenancy(enabled: true, autoTenantCreation: null, autoTenantActivation: null)` | `enabled` **defaults to `true`** | `enabled`, `autoTenantCreation`, `autoTenantActivation` |
| `Reconfigure::multiTenancy(autoTenantCreation: null, autoTenantActivation: null)` | `enabled` is immutable | merged |

### 9.4 Object TTL (1.35)

| Factory | Params | Wire (`objectTtlConfig`) |
|---|---|---|
| `Configure::objectTtl()->deleteByCreationTime(timeToLive:, filterExpiredObjects: null)` | `timeToLive: int\|\DateInterval` (seconds, positive) | `{"enabled": true, "deleteOn": "_creationTimeUnix", "defaultTtl": n, "filterExpiredObjects"?}` |
| `…->deleteByUpdateTime(timeToLive:, filterExpiredObjects: null)` | same | `deleteOn: "_lastUpdateTimeUnix"` |
| `…->deleteByDateProperty(propertyName:, ttlOffset: null, filterExpiredObjects: null)` | `ttlOffset: int\|\DateInterval\|null` (can be **negative**; null becomes `0`, which is always sent) | `deleteOn: "<propertyName>"` |
| `Reconfigure::objectTtl()->disable()` | — | `{"enabled": false}` merged |
| `Reconfigure::objectTtl()->deleteByCreationTime / deleteByUpdateTime (timeToLive: null, …)` / `deleteByDateProperty(propertyName: null, ttlOffset: null, …)` | all optional | `enabled: true` + the non-null fields merged |

For a `\DateInterval`, convert it to whole seconds. Throw `InvalidInputException` when it has year or month parts, because their length is ambiguous. Keep the sign of `invert` for `ttlOffset`.

**Wire-key inconsistency in Python:** create and parse use `objectTtlConfig`, but update merges into **`objectTTLConfig`** (`_CollectionConfigUpdate.merge_with_existing`). Go's `encoding/json` matches keys case-insensitively, so it probably works, but the existing values under `objectTtlConfig` are **not merged**, and both keys end up in the PUT body. **PHP uses `objectTtlConfig` everywhere and merges into the existing object.** Report this upstream.

---

## 10. `config->update()` and mutability

### 10.1 Parameters

| Python param | PHP param | Type | Notes |
|---|---|---|---|
| `description` | `description` | `?string` | |
| `property_descriptions` | `propertyDescriptions` | `?array<string,string>` | Every key must be an existing property, otherwise `InvalidInputException` |
| `inverted_index_config` | `invertedIndexConfig` | `?InvertedIndexConfigUpdate` | `stopwordPresets` is gated at 1.37 |
| `multi_tenancy_config` | `multiTenancyConfig` | `?MultiTenancyConfigUpdate` | |
| `object_ttl_config` | `objectTtlConfig` | `?ObjectTtlConfigUpdate` | |
| `replication_config` | `replicationConfig` | `?ReplicationConfigUpdate` | |
| `vector_config` | `vectorConfig` | `VectorConfigUpdate\|list<VectorConfigUpdate>\|null` | From `Reconfigure::vectors()->update(name: ?string = null → "default", vectorIndexConfig: HnswUpdate\|FlatUpdate\|DynamicUpdate\|HfreshUpdate)` |
| `generative_config` | `generativeConfig` | `?GenerativeProvider` | |
| `reranker_config` | `rerankerConfig` | `?RerankerProvider` | |
| `vector_index_config` (**deprecated**, Dep017) | `vectorIndexConfig` | `?VectorIndexConfigUpdate` | Legacy single-vector collections only |
| `vectorizer_config` (Dep023 when it's a list of named vectors) | `vectorizerConfig` | `VectorIndexConfigUpdate\|null` | PHP accepts only the **non-deprecated** form, a bare index update for legacy collections. The `list<NamedVectorConfigUpdate>` form isn't ported (§14) |

`vectorConfig` together with `vectorizerConfig` or `vectorIndexConfig` throws `InvalidInputException` (Python `mutual_exclusivity`).

### 10.2 Merge algorithm (port `_CollectionConfigUpdate.merge_with_existing` exactly)

The merge runs in this order on the fetched `schema` array:

1. `description`: replace.
2. `propertyDescriptions`: set `properties[i].description`. Unknown names throw.
3. `invertedIndexConfig`, `replicationConfig`, `multiTenancyConfig`, `objectTtlConfig`: recursive non-null merge. Enums become their values. Scalars, lists and maps replace. Nested update objects recurse. `asyncConfig` **replaces** instead of merging.
4. The legacy `vectorIndexConfig`: check the quantizer (§6.3), then merge into `schema.vectorIndexConfig`.
5. `generativeConfig`: remove every `moduleConfig` key that contains `generative`, then set `moduleConfig[module] = params`.
6. `rerankerConfig`: the same, with `reranker`.
7. For each `vectorConfig` entry (a single entry is treated as a list of one):
   - Throw if `schema.vectorConfig[name]` is missing: "Vector config with name X does not exist…". This includes collections whose vectors were all dropped, where `vectorConfig` is missing entirely.
   - Throw if it has no `vectorIndexConfig`, meaning its index was dropped ("…cannot be updated…").
   - Check the quantizer, merge into `vectorConfig[name].vectorIndexConfig`, and set `vectorConfig[name].vectorIndexType = update->indexType()`.

   **PHP addition:** throw `InvalidInputException` when `update->indexType()` differs from the existing `vectorIndexType`, except for a Dynamic index. Python doesn't check this, and the server's error is less clear. Mark this as a deliberate deviation.
8. `PUT /schema/{c}` with the whole merged schema.

### 10.3 Update-only factories

| Python | PHP | Mutable fields |
|---|---|---|
| `Reconfigure.VectorIndex.hnsw(...)` | `Reconfigure::vectorIndex()->hnsw(dynamicEfFactor:, dynamicEfMin:, dynamicEfMax:, ef:, flatSearchCutoff:, filterStrategy:, vectorCacheMaxObjects:, quantizer:)` | see §6.1 |
| `Reconfigure.VectorIndex.flat(vector_cache_max_objects, quantizer)` | `->flat(...)` | the quantizer is BQ or RQ |
| `Reconfigure.VectorIndex.dynamic(threshold, hnsw, flat, quantizer)` | `->dynamic(...)` | the quantizer is BQ |
| `Reconfigure.VectorIndex.hfresh(max_posting_size_kb, search_probe, quantizer)` | `->hfresh(...)` | the quantizer is RQ |
| `Reconfigure.Vectors.update(name, vector_index_config)` | `Reconfigure::vectors()->update(...)` | — |
| `Reconfigure.NamedVectors.update(...)` (deprecated) | not ported | — |

The PHP factory signatures use union types for the quantizer, for example `BqUpdate|RqUpdate|null` for `flat`. Python enforces the same restriction only through pydantic types.

### 10.4 Immutable after creation

These can't be changed. To change them, recreate the collection or use an add, drop or delete method.

- name, `vectorizer`/module config of every vector, `sourceProperties`, `distance`, `efConstruction`, `maxConnections`, HNSW `cleanupIntervalSeconds`, `multivector`, HFresh `replicas`
- index **type** (except dynamic's own switch), quantizer **type** once one is enabled
- property `dataType`, `tokenization`, `index*` flags (use `deletePropertyIndex` to drop one), `textAnalyzer`, nested properties
- `indexTimestamps`, `indexPropertyLength`, `indexNullState`
- sharding, multi-tenancy `enabled`

---

## 11. Serialization to REST JSON (create)

### 11.1 The top-level body (`CollectionConfigCreate::toRestArray()`, Python `_CollectionConfigCreate._to_dict`)

```json
{
  "class": "Article",
  "description": "…",
  "properties": [ …Property…, …Reference… ],
  "vectorConfig": { "<name>": { "vectorizer": {"<module>": {…}}, "vectorIndexType": "hnsw", "vectorIndexConfig": {…} } },
  "moduleConfig": { "generative-openai": {…}, "reranker-cohere": {…} },
  "invertedIndexConfig": {…}, "multiTenancyConfig": {…}, "objectTtlConfig": {…},
  "replicationConfig": {…}, "shardingConfig": {…},
  "vectorizer": "…", "vectorIndexType": "…", "vectorIndexConfig": {…}
}
```

The last three keys are legacy and only appear with the deprecated arguments.

Rules:
- Null values are always left out, **recursively** (pydantic `exclude_none`).
- Enums become their `->value`. URL value objects become strings.
- **No vector config at all:** when `vectorConfig`, `vectorizerConfig` and `vectorIndexConfig` are all null, the client **injects** `vectorConfig: {"default": {"vectorizer": {"none": {}}, "vectorIndexType": "hnsw"}}`. In other words, a collection created with no vector arguments gets a self-provided named vector called `default`, not a legacy one (Python `inject_vector_config_none`).
- A single `VectorConfigCreate` becomes `vectorConfig: {name ?? "default": …}`. A list must name every vector.
- Legacy `vectorizerConfig` with module M: `vectorizer: "M"`, and `moduleConfig.M = options` unless M is `none`. Legacy `vectorIndexConfig`: `vectorIndexType` plus `vectorIndexConfig`.
- When there's no legacy `vectorIndexConfig`, no `vectorConfig`, and `emitDefaultVectorIndexType` is set, add `vectorIndexType: "hnsw"`. In practice this doesn't happen, because of the injection above.
- Properties come first, then references, all in `properties[]`.

### 11.2 `emitDefaultVectorIndexType`

Servers 1.37.5 and later apply `DEFAULT_VECTOR_INDEX_TYPE` to a named vector that has no `vectorIndexType`. Older servers reject an empty value. So `create()` emits `"vectorIndexType": "hnsw"` for vectors with no index config **only when the server is older than 1.37.5**. The same applies to legacy named vectors. `addVector()` always emits it, because Python's `VectorConfigCreate._to_dict()` defaults to `true` there.

### 11.3 Property `moduleConfig` (skip, vectorizePropertyName) on create

| Vector setup | Python behaviour | PHP |
|---|---|---|
| Legacy `vectorizerConfig` (single) | `moduleConfig.{M}: {skip, vectorizePropertyName}` for M ≠ `none`, with the defaults `false`/`true` always sent | Same |
| Legacy `NamedVectors` list | The same, once for each named vector's module | Same (only reachable through `createFromArray`) |
| **`vectorConfig` (modern)** | **Bug:** the vectorizer list is `None`, so the snake_case keys `skip_vectorization`/`vectorize_property_name` leak into the property JSON and are ignored by the server. Per-property skip **doesn't take effect** on create. `addProperty()` does set it | Emit `moduleConfig.{M}: {skip, vectorizePropertyName}` for **each distinct non-`none` module** in `vectorConfig`, but **only when the caller set either argument explicitly**. By default the wire output matches Python. This is a deliberate deviation; confirm the semantics with the core team and report it upstream |

"Set explicitly" is tracked with nullable constructor params, `?bool $skipVectorization = null` and `?bool $vectorizePropertyName = null`, whose effective defaults are `false`/`true`. The read-back always exposes plain bools.

### 11.4 Vectorizer options

`vectorizer.{module}` holds the module params from §5, plus `vectorizeClassName` (text modules), plus `properties` (source properties). Special cases, all listed in §5:
- `isAzure` on `text2vec-openai`
- `options{}` on HuggingFace
- the `weights{}` map on multi2vec modules
- `type` for OpenAI (Python field `type_`)
- the fixed `service` and `apiEndpoint` values

---

## 12. Parsing server JSON (`CollectionConfigParser`, Python `config_methods.py`)

### 12.1 Read objects

| PHP class | Fields (camelCase of Python) |
|---|---|
| `CollectionConfig` | `name`, `description`, `generativeConfig: ?GenerativeConfig`, `invertedIndexConfig`, `multiTenancyConfig`, `objectTtlConfig: ?ObjectTtlConfig`, `properties: list<PropertyConfig>`, `references: list<ReferencePropertyConfig>`, `replicationConfig`, `rerankerConfig: ?RerankerConfig`, `shardingConfig: ?ShardingConfig`, `vectorIndexConfig` (legacy, `Hnsw\|Flat\|Dynamic\|Hfresh\|null`), `vectorIndexType: ?VectorIndexType`, `vectorizerConfig: ?VectorizerConfig` (legacy), `vectorizer: Vectorizers\|string\|null`, `vectorConfig: ?array<string, NamedVectorConfig>`; PHP addition: `raw: array` (the original JSON, as an escape hatch) |
| `CollectionConfigSimple` | `name`, `description`, `generativeConfig`, `properties`, `references`, `rerankerConfig`, `vectorizerConfig`, `vectorizer`, `vectorConfig`, `objectTtlConfig` |
| `PropertyConfig` | `name`, `description`, `dataType`, `indexFilterable`, `indexRangeFilters`, `indexSearchable`, `nestedProperties: ?list<NestedProperty>`, `textAnalyzer: ?TextAnalyzerConfig`, `tokenization`, `vectorizerConfig: ?PropertyVectorizerConfig` (legacy), `vectorizer: ?string` (legacy), `vectorizerConfigs: ?array<string, PropertyVectorizerConfig>` (named) |
| `NestedProperty` | `dataType`, `description`, `indexFilterable`, `indexSearchable`, `name`, `nestedProperties`, `textAnalyzer`, `tokenization` |
| `ReferencePropertyConfig` | `name`, `description`, `targetCollections: list<string>` |
| `PropertyVectorizerConfig` | `skip`, `vectorizePropertyName` |
| `TextAnalyzerConfig` | `asciiFold`, `asciiFoldIgnore`, `stopwordPreset` |
| `InvertedIndexConfig` | `bm25: Bm25Config(b, k1)`, `cleanupIntervalSeconds`, `indexNullState`, `indexPropertyLength`, `indexTimestamps`, `stopwords: StopwordsConfig(preset, additions, removals)`, `stopwordPresets` |
| `MultiTenancyConfig` | `enabled`, `autoTenantCreation`, `autoTenantActivation` |
| `ReplicationConfig` | `factor`, `asyncEnabled`, `deletionStrategy`, `asyncConfig: ?AsyncReplicationConfig` (14 nullable ints) |
| `ShardingConfig` | `virtualPerPhysical`, `desiredCount`, `actualCount`, `desiredVirtualCount`, `actualVirtualCount`, `key`, `strategy`, `function` |
| `VectorIndexConfigHnsw` | `cleanupIntervalSeconds`, `distanceMetric`, `dynamicEfMin/Max/Factor`, `ef`, `efConstruction`, `filterStrategy`, `flatSearchCutoff`, `maxConnections`, `skip`, `vectorCacheMaxObjects`, `quantizer`, `multiVector: ?MultiVectorConfig` |
| `VectorIndexConfigFlat` | `distanceMetric`, `vectorCacheMaxObjects`, `quantizer`, `multiVector` |
| `VectorIndexConfigDynamic` | `distanceMetric`, `hnsw`, `flat`, `threshold` |
| `VectorIndexConfigHfresh` | `distanceMetric`, `maxPostingSizeKb`, `replicas`, `searchProbe`, `quantizer` |
| `VectorIndexConfigNone` | — (the index was dropped) |
| `PqConfig` / `BqConfig` / `SqConfig` / `RqConfig` | `bitCompression`, `segments`, `centroids`, `trainingLimit`, `encoder(type, distribution)` / `cache`, `rescoreLimit` / `rescoreLimit`, `trainingLimit` / `cache`, `bits`, `rescoreLimit`, `centering`, `trainingLimit` |
| `MultiVectorConfig` / `MuveraConfig` | `encoding: ?MuveraConfig`, `aggregation` / `enabled`, `ksim`, `dprojections`, `repetitions` |
| `NamedVectorConfig` | `vectorizer: NamedVectorizerConfig(vectorizer, model: array, sourceProperties)`, `vectorIndexConfig` |
| `VectorizerConfig` (legacy) | `vectorizer`, `model: array`, `vectorizeCollectionName` |
| `GenerativeConfig` / `RerankerConfig` | `generative\|reranker: GenerativeSearches\|Rerankers\|string`, `model: array` |
| `ObjectTtlConfig` | `enabled`, `timeToLive: ?\DateInterval` (Python uses `timedelta`), `filterExpiredObjects`, `deleteOn: 'creationTime'\|'updateTime'\|<propertyName>` |
| `ShardStatus` | `name`, `status: ShardStatusType`, `vectorQueueSize`, `perNodeStatus` |

Python's `PQConfig.bit_compression` getter (deprecated, Dep019) becomes a plain `bitCompression` property in PHP, with no warning.

### 12.2 Parse rules (port exactly)

| Field | Rule |
|---|---|
| Generative / reranker | Found by scanning `moduleConfig` keys for the substring `generative` or `reranker`. Only **exactly one** match is parsed; zero or two or more give `null` |
| Legacy vectorizer | Only when there's no `vectorConfig` and `vectorizer !== 'none'`. It takes `moduleConfig[vectorizer]` out and reads `vectorizeClassName`, default `false` |
| `vectorizer` | `null` when `vectorConfig` exists, or when the `vectorizer` key is missing (all vectors were dropped) |
| Properties vs references | A primitive is identified when the first character of `dataType[0]` is lowercase. Anything else is a reference |
| `indexRangeFilters` | Missing means `false`. `indexNullState`, `indexPropertyLength` and `indexTimestamps` are `=== true` |
| `textAnalyzer` | `null` unless `asciiFold` or `stopwordPreset` is present. The server normalizes an empty analyzer to nil |
| Property `vectorizerConfig` (legacy) | Set from `moduleConfig[schema.vectorizer]` when a legacy vectorizer is present and the property has a `moduleConfig`. `skip` and `vectorizePropertyName` default to `false` |
| Property `vectorizerConfigs` | When `vectorConfig` exists, one entry per property `moduleConfig` key |
| `multiTenancyConfig` | Every field defaults to `false` |
| `shardingConfig` | `null` when multi-tenancy is enabled |
| `deletionStrategy` | Missing means `NoAutomatedResolution` |
| `asyncEnabled` | Missing means `false`. From 1.38.9 the server derives it as `factor > 1` |
| `filterStrategy` | Missing means `Sweeping` |
| Quantizer | The first one enabled wins, in the order **bq, sq, pq, rq**. Enabled PQ needs `encoder.type` and `encoder.distribution` |
| `multivector` | Only when `enabled`. `muvera` only when `enabled` |
| Vector index type | `hnsw`/`flat`/`dynamic`/`hfresh` are parsed. `none` with no `vectorIndexConfig` becomes `VectorIndexConfigNone`. An **unknown type with a config** throws `SchemaValidationException` ("upgrade the client"). A known type **without** a config also throws |
| `objectTtlConfig` | Only when `enabled`. `deleteOn` `_lastUpdateTimeUnix` becomes `updateTime` and `_creationTimeUnix` becomes `creationTime`. `defaultTtl` becomes a `DateInterval` in seconds |
| Module names | `Vectorizers::tryFrom($s) ?? $s`, and the same for `GenerativeSearches` and `Rerankers` |
| Other enums | Python throws `ValueError` on unknown values (`DataType`, `Tokenization`, `VectorDistances`, …). PHP throws `SchemaValidationException` with the field path. The `raw` array keeps the data reachable |

### 12.3 `toArray()`, the round trip for `createFromConfig` (Python `_CollectionConfig.to_dict`)

- snake_case fields become camelCase keys, null values are dropped, enums become values, and `DateInterval` becomes seconds.
- `name` becomes `class`.
- `generativeConfig`, `vectorizerConfig` and `rerankerConfig` become `moduleConfig[module] = model`. The legacy `vectorizeCollectionName` goes to `moduleConfig[module].vectorizeClassName`.
- `vectorConfig[n].vectorizer` becomes `{module: {…model, properties: sourceProperties}}`, and `vectorIndexType` is taken from the index class. `VectorIndexConfigNone` drops `vectorIndexConfig`. When `vectorConfig` exists, the top-level `vectorIndexType` and `vectorIndexConfig` are removed.
- The quantizer becomes `pq`/`bq`/`sq`/`rq` with `enabled: true`. HFresh uses `maxPostingSizeKB`. The PQ encoder uses `type`.
- Properties and references are merged into `properties[]`, with `dataType` as a list and property `moduleConfig` rebuilt from `vectorizer`/`vectorizerConfig` or `vectorizerConfigs`.
- `objectTtlConfig.deleteOn` is mapped back to `_creationTimeUnix`/`_lastUpdateTimeUnix`.

**Python `to_dict` bugs that PHP must not copy.** The generic snake→camel conversion produces **`distanceMetric`** (the server expects `distance`), **`multiVector`** (the server expects `multivector`) and **`internalBitCompression`** (expected `bitCompression`). A config round-tripped through Python loses any non-default distance and multivector settings. The existing tests only use the default cosine, so this goes unnoticed. PHP maps these explicitly. Mark this as **unverified** until a round-trip integration test on a `dot` or `l2-squared` collection confirms it, and report it upstream.

---

## 13. Version gates

Gates marked **Py** are checked client-side by Python and must throw `UnsupportedFeatureException`. Gates marked **PHP** are new, and are proposed only where an older server would **silently ignore** an unknown field (Go ignores unknown JSON keys), which would give the user a wrong collection without an error. Gates marked **—** are documented only, because the server errors anyway.

The versions come from Python's integration-test skips and the changelog.

| Feature | Min | Gate |
|---|---|---|
| `Property.textAnalyzer` (create, addProperty) | 1.37.0 | **Py** |
| `invertedIndex.stopwordPresets` (create, update) | 1.37.0 | **Py** |
| Omit the default `vectorIndexType` | 1.37.5 | **Py** (behaviour switch, not an error) |
| `DataType::BlobHash` | 1.37 | — |
| Multi-vector (`multiVectors()`, `multivector`) | 1.29 | — (at the floor) |
| MUVERA encoding | 1.31 | **PHP** |
| `config->addVector()` | 1.31 | — |
| RQ quantizer: HNSW / flat / dynamic | 1.32 / 1.34 / 1.34 | **PHP** (`rq` is silently ignored on older servers) |
| RQ `centering` | 1.39.2 | **PHP** |
| `quantizer()->none()` (`skipDefaultQuantization`) | 1.32.4 | **PHP** |
| SQ on HNSW | 1.26 | — (below the 1.29 floor) |
| Dynamic index | 1.25 | — (below the floor) |
| `multi2multivec-weaviate` | 1.35 | — |
| Object TTL | 1.35 | **PHP** |
| `replicationAsyncConfig` | 1.36 (1.34.18 backport) | **PHP**, `isAtLeast(1,34,18)` |
| HFresh index | 1.36 | — |
| `deletePropertyIndex` | 1.36 | — |
| `deleteVectorIndex` | 1.39 | — |
| `VectorFilterStrategy::Pathseer` | 1.40 | — |
| `asyncEnabled` ignored by the server | ≥ 1.38 | warn (Dep030) |
| `maxWorkers`, `aliveNodesCheckingFrequency` ignored | ≥ 1.37.3 | warn (Dep029) |

PHP gates run before I/O in `create()`, `update()`, `addProperty()` and `addVector()`, using the version cached from `/v1/meta` ([01](01-architecture.md)). `skipArgumentValidation` doesn't disable version gates.

---

## 14. Deprecated items: port or not?

| Python | Status in Python | PHP decision |
|---|---|---|
| `Configure.NamedVectors.*` (32 factories) and `Reconfigure.NamedVectors.update` | Deprecated; the types are deliberately left private (4.24 changelog) | **Not ported.** Legacy JSON still works through `createFromArray()` and parses into `vectorConfig` |
| `Configure.Vectorizer.*` (legacy single vectorizer, 31 factories) and `create(vectorizer_config=…)` | Deprecated (Dep024) | **Not ported** as factories. Legacy collections are still **read** (`vectorizerConfig`, `vectorIndexConfig`, `vectorizer`). **Decision: `create()` doesn't take `vectorizerConfig`.** A legacy collection can be created with `createFromArray()` |
| `create(vector_index_config=…)` | Deprecated (Dep025) | Not ported |
| `update(vector_index_config=…)` / `update(vectorizer_config=<index update>)` | Dep017 / legacy | **Ported as `vectorizerConfig: ?VectorIndexConfigUpdate`**, because a legacy collection has no other way to tune its index. Logs a deprecation notice |
| `add_vector(NamedVectors…)` | Dep026 | Not ported |
| `VectorIndex.hnsw(multi_vector=…)`, `MultiVector.multi_vector(encoding=…)` | Dep027 / Dep026 | Not ported |
| `Quantizer.pq(bit_compression)`, `sq(cache)` | Dep019 / deprecated | Not ported (not accepted) |
| `Configure.sharding(actual_count, actual_virtual_count)` | Dep018 | Not ported |
| `replication(async_enabled)`, `async_config(max_workers, alive_nodes_checking_frequency)` | Dep030 / Dep029 | **Ported with a deprecation log**, because they still take effect on older servers in the supported range |
| `text2vec_aws`, `multi2vec_aws`, `text2vec_google`, `text2vec_google_aistudio`, `text2vec_gpt4all`, generative `aws`, `google`, `palm` | Removed after Q3'26 | **Not ported** (the service-specific replacements exist) |
| `text2vec_contextionary` | Deprecated ("old") | **Ported** with a `@deprecated` tag, because old self-hosted setups still use it |
| `Configure.VectorIndex.none()` (skip index) | Not deprecated | Ported |

Every ported deprecated item gets a PHP `@deprecated` docblock (PHPStan and IDE strike-through) and a single PSR-3 `notice` for each process, using the Python `DepNNN` code.

---

## 15. PHP design: keeping about 40 modules maintainable

### 15.1 One data table, generated code

39 vectorizer factories (33 ported; 6 deprecated ones aren't), 4 multi-vector factories, 22 generative factories in v4.23.1 plus 🆕 `meta` on main (3 deprecated ones aren't ported), and 7 reranker factories (counts from the 2026-09-25 AST audit) are all "a name, a wire module, a list of `(phpParam, type, default, required, wireKey, transform)`". Hand-writing them means around 3,000 lines of code that drift over time.

- **Source of truth:** `resources/modules/{vectors,multi_vectors,generative,reranker}.php`. These are plain PHP arrays, so no YAML parser is needed at build time. Example:
  ```php
  'text2vecHuggingFace' => [
      'python' => 'text2vec_huggingface', 'module' => 'text2vec-huggingface', 'family' => 'text2vec',
      'params' => [
          ['model', '?string'],
          ['passageModel', '?string'],
          ['queryModel', '?string'],
          ['endpointUrl', '?string', 'wire' => 'endpointURL', 'transform' => 'url'],
          ['waitForModel', '?bool', 'wire' => 'options.waitForModel'],
          ['useGpu', '?bool', 'wire' => 'options.useGPU'],
          ['useCache', '?bool', 'wire' => 'options.useCache'],
      ],
      'fixed' => [], 'deprecated' => null, 'since' => null,
  ],
  ```
  - `family` decides which shared params are added. `text2vec` adds `sourceProperties` and `vectorizeCollectionName`. `multi2vec` adds nothing, and fields go through the `Multi2VecField` serializer. `multivector` adds `encoding` and `multiVectorConfig`.
  - `fixed` holds constants such as `isAzure`, `service` and `apiEndpoint`.
  - Dotted wire keys nest.
  - `transform` values are `url`, `fields`, `enum` and `seconds`.
- **Generator:** `bin/generate-config-factories` writes `src/Config/Factory/{Vectors,MultiVectors,Generative,Reranker}.php`. These are real methods with typed named args, docblocks with model hints and `@deprecated`, and a PHPStan-clean body that calls a shared `ModuleOptions::build($spec, $args)`. The output is **committed**, like the protos, and CI regenerates it and fails on any diff.
- **Value objects:** there's **one generic** `VectorizerConfig(module: string, options: array)`, and likewise one `GenerativeProvider` and one `RerankerProvider`. There isn't a class per module. Python needs about 35 pydantic classes only for validation; PHP gets typing from the generated method signatures.
- **Parity check:** `bin/check-python-parity` fetches the Python files at a pinned tag and runs an AST extractor, the same script used to write this spec. It prints each factory's name, params and constructor keyword mapping, diffs that against the tables, and reports missing, extra or renamed modules and params. It runs as a weekly CI job and opens an issue on drift. This matches the P6 "keep up with the server" work in [04](04-roadmap.md).
- **Escape hatches:** `custom(moduleName:, moduleConfig:)` exists on vectors, generative and reranker. Read objects keep unknown modules as strings with their raw `model` array.

### 15.2 Class layout (`src/Config/`)

```
Configure.php, Reconfigure.php         static entry points returning the factory singletons
Factory/Vectors.php, MultiVectors.php, Generative.php, Reranker.php          (GENERATED)
Factory/VectorIndex.php, Quantizer.php, MultiVector.php, ObjectTtl.php        (hand-written)
Factory/Update/VectorIndexUpdate.php, QuantizerUpdate.php, VectorsUpdate.php, ObjectTtlUpdate.php
Create/  CollectionConfigCreate, VectorConfigCreate, VectorizerConfig, VectorIndexConfig{Hnsw,Flat,Dynamic,Hfresh,Skip}Create,
         {Pq,Bq,Sq,Rq,Uncompressed}Create, MultiVectorConfigCreate, MuveraCreate, InvertedIndexConfigCreate,
         TextAnalyzerConfigCreate, ReplicationConfigCreate, AsyncReplicationConfigCreate, ShardingConfigCreate,
         MultiTenancyConfigCreate, ObjectTtlConfigCreate, GenerativeProvider, RerankerProvider
Update/  CollectionConfigUpdate, VectorConfigUpdate, VectorIndexConfig{Hnsw,Flat,Dynamic,Hfresh}Update, {Pq,Bq,Sq,Rq}Update,
         InvertedIndexConfigUpdate, ReplicationConfigUpdate, AsyncReplicationConfigUpdate, MultiTenancyConfigUpdate, ObjectTtlConfigUpdate
Read/    (§12.1)   Parser/CollectionConfigParser.php
Property.php, ReferenceProperty.php, ReferencePropertyMultiTarget.php, Multi2VecField.php
Enum/    DataType, Tokenization, IndexName, Vectorizers, GenerativeSearches, Rerankers, VectorDistances, VectorIndexType,
         VectorFilterStrategy, PQEncoderType, PQEncoderDistribution, MultiVectorAggregation, StopwordsPreset,
         ReplicationDeletionStrategy, ShardStatusType
Integrations.php                       header helpers (§15.4)
```

**Entry points.** `Configure::vectors()`, `multiVectors()`, `vectorIndex()`, `quantizer()`, `multiVector()`, `generative()`, `reranker()` and `objectTtl()` return stateless singletons. `Configure::invertedIndex()`, `textAnalyzer()`, `multiTenancy()`, `replication()`, `replicationAsyncConfig()` and `sharding()` return value objects directly. `Reconfigure` mirrors this with `vectors()`, `vectorIndex()`, `quantizer()`, `generative()`, `reranker()`, `objectTtl()`, `invertedIndex()`, `multiTenancy()`, `replication()` and `replicationAsyncConfig()`.

Python nests the quantizer as `Configure.VectorIndex.Quantizer`. PHP flattens it to `Configure::quantizer()`, and also offers `Configure::vectorIndex()->quantizer()` for people copying Python snippets. Both return the same object.

**Serialization interface.** Create objects implement the `@internal` `RestSerializable::toRestArray(ServerVersion $v): array`. Update objects implement `SchemaMergeable::mergeInto(array $schema): array`. Only `Transport/Mapper` and `Collections/Config` call them ([01](01-architecture.md) layering).

### 15.3 Other PHP-specific notes

- `\DateInterval|int` is accepted for TTLs; §9.4 has the conversion rules.
- URL params are typed `?string` and validated with `filter_var(FILTER_VALIDATE_URL)` plus an http(s) scheme check. Python normalizes URLs through `AnyHttpUrl`, which adds a trailing `/` to a bare host. **PHP doesn't normalize**, and sends the string as given. **Unverified** whether the server cares.
- Read objects are immutable. `CollectionConfig::toArray()` plus `createFromArray()` is the way to clone a collection under a new name: change `class` in the array.

### 15.4 `Integrations` (Python `weaviate.classes.config.Integrations`)

These are static helpers that return header maps for `headers:` ([09 §5](09-connection.md#5-headers)). They're exported from the same Python module, so they're listed here.

| Python | Headers |
|---|---|
| `cohere(api_key, base_url, requests_per_minute_embeddings)` | `X-Cohere-Api-Key`, `X-Cohere-Baseurl`, `X-Cohere-Ratelimit-RequestPM-Embedding` |
| `huggingface(...)` | `X-Huggingface-Api-Key`, `-Baseurl`, `-Ratelimit-RequestPM-Embedding` |
| `openai(api_key, organization, base_url, requests_per_minute_embeddings, tokens_per_minute_embeddings)` | `X-Openai-Api-Key`, `X-Openai-Organization`, `X-Openai-Baseurl`, `X-Openai-Ratelimit-RequestPM-Embedding`, `X-Openai-Ratelimit-TokenPM-Embedding` |
| `voyageai(...)` | `X-Voyageai-Api-Key`, `-Baseurl`, `-Ratelimit-RequestPM-Embedding`, `-Ratelimit-TokenPM-Embedding` |
| `jinaai(...)` | `X-Jinaai-Api-Key`, `-Baseurl`, `-Ratelimit-RequestPM-Embedding` |
| `mistral(api_key, request_per_minute_embeddings, tokens_per_minute_embeddings)` | `X-Mistral-Api-Key`, `-Ratelimit-RequestPM-Embedding`, `-Ratelimit-TokenPM-Embedding` |
| `aws(access_key, secret_key, …)` class exists, **no factory method** in Python | PHP adds `Integrations::aws()` (`X-Aws-Access-Key`, `X-Aws-Secret-Key`, `X-Aws-Ratelimit-RequestPM-Embedding`, `X-Aws-Ratelimit-TokensPM-Embedding`) |

PHP: `Integrations::openAI(apiKey: …)->toHeaders()`. A plain array still works everywhere.

---

## 16. Examples

```php
use Weaviate\Client\Config\{Configure, Reconfigure, Property, ReferenceProperty, ReferencePropertyMultiTarget, Multi2VecField};
use Weaviate\Client\Config\Enum\{DataType, Tokenization, VectorDistances, VectorFilterStrategy, StopwordsPreset,
    ReplicationDeletionStrategy, MultiVectorAggregation, IndexName, ShardStatusType};

// 1. Full create: two named vectors, RQ, generative + reranker, BM25, TTL, MT
$articles = $client->collections->create(
    name: 'article',                                   // sent as "Article"
    description: 'News articles',
    properties: [
        new Property(name: 'title', dataType: DataType::Text, tokenization: Tokenization::Word,
            textAnalyzer: Configure::textAnalyzer(asciiFold: true, asciiFoldIgnore: ['é'])),   // needs 1.37
        new Property(name: 'body', dataType: DataType::Text, skipVectorization: false),
        new Property(name: 'year', dataType: DataType::Int, indexRangeFilters: true),
        new Property(name: 'meta', dataType: DataType::Object, nestedProperties: [
            new Property(name: 'source', dataType: DataType::Text),
        ]),
    ],
    references: [
        new ReferenceProperty(name: 'author', targetCollection: 'Author'),
        new ReferencePropertyMultiTarget(name: 'mentions', targetCollections: ['Person', 'Org']),
    ],
    vectorConfig: [
        Configure::vectors()->text2vecOpenAI(name: 'title_vec', sourceProperties: ['title'],
            model: 'text-embedding-3-small', dimensions: 512),
        Configure::vectors()->text2vecCohere(
            name: 'body_vec',
            sourceProperties: ['body'],
            quantizer: Configure::quantizer()->rq(bits: 8, rescoreLimit: 200),
            vectorIndexConfig: Configure::vectorIndex()->hnsw(
                distanceMetric: VectorDistances::Dot, efConstruction: 256, maxConnections: 32,
                filterStrategy: VectorFilterStrategy::Acorn,
            ),
        ),
    ],
    generativeConfig: Configure::generative()->anthropic(model: 'claude-sonnet-5', maxTokens: 1024),
    rerankerConfig: Configure::reranker()->cohere(model: 'rerank-english-v3.0'),
    invertedIndexConfig: Configure::invertedIndex(bm25B: 0.7, bm25K1: 1.1, indexNullState: true,
        stopwordsPreset: StopwordsPreset::En, stopwordsAdditions: ['lorem']),
    replicationConfig: Configure::replication(factor: 3, deletionStrategy: ReplicationDeletionStrategy::TimeBasedResolution,
        asyncConfig: Configure::replicationAsyncConfig(propagationBatchSize: 100)),
    objectTtlConfig: Configure::objectTtl()->deleteByCreationTime(timeToLive: new \DateInterval('P30D')),
);

// 2. Self-provided vectors only; no vector args at all → server gets vectorConfig.default = {none, hnsw}
$client->collections->create(name: 'Raw', properties: [new Property(name: 'x', dataType: DataType::Text)]);

// 3. Multi-vector (ColBERT) with MUVERA
$client->collections->create(
    name: 'Passages',
    vectorConfig: Configure::multiVectors()->text2vecJinaAI(
        name: 'colbert',
        encoding: Configure::multiVector()->muvera(ksim: 4, dprojections: 16, repetitions: 10),
        multiVectorConfig: Configure::multiVector()->config(aggregation: MultiVectorAggregation::MaxSim),
    ),
);

// 4. Multimodal with weights, dynamic index with BQ on both halves
Configure::vectors()->multi2vecClip(
    name: 'mm',
    imageFields: [new Multi2VecField(name: 'image', weight: 0.7)],
    textFields: [new Multi2VecField(name: 'caption', weight: 0.3)],
    quantizer: Configure::quantizer()->bq(),
    vectorIndexConfig: Configure::vectorIndex()->dynamic(threshold: 10_000),
);

// 5. Update (read-modify-write)
$articles->config->update(
    description: 'News articles (v2)',
    propertyDescriptions: ['title' => 'Headline'],
    invertedIndexConfig: Reconfigure::invertedIndex(bm25K1: 1.3),
    replicationConfig: Reconfigure::replication(factor: 5),
    multiTenancyConfig: Reconfigure::multiTenancy(autoTenantCreation: true),
    objectTtlConfig: Reconfigure::objectTtl()->disable(),
    vectorConfig: [
        Reconfigure::vectors()->update(name: 'body_vec', vectorIndexConfig:
            Reconfigure::vectorIndex()->hnsw(ef: 128, quantizer: Reconfigure::quantizer()->rq(rescoreLimit: 400))),
        Reconfigure::vectors()->update(name: 'title_vec', vectorIndexConfig:
            Reconfigure::vectorIndex()->hnsw(quantizer: Reconfigure::quantizer()->bq())), // throws if RQ/PQ/SQ already on
    ],
    generativeConfig: Reconfigure::generative()->openAI(model: 'gpt-5', reasoningEffort: 'low'),
);

// 6. Schema evolution
$articles->config->addProperty(new Property(name: 'summary', dataType: DataType::Text));
$articles->config->addReference(new ReferenceProperty(name: 'related', targetCollection: 'Article'));
$articles->config->addVector(Configure::vectors()->text2vecWeaviate(name: 'summary_vec', sourceProperties: ['summary']));
$articles->config->deletePropertyIndex('year', IndexName::RangeFilters);
$articles->config->deleteVectorIndex('title_vec');                     // async drop, 1.39+

// 7. Shards (tenant-scoped via withTenant)
foreach ($articles->config->getShards() as $s) { echo $s->name, ' ', $s->status->value, PHP_EOL; }
$articles->config->updateShards(ShardStatusType::ReadOnly);          // all shards
$articles->config->updateShards(ShardStatusType::Ready, 'shard-a');

// 8. Read back, clone, list, delete
$cfg = $articles->config->get();
$cfg->vectorConfig['body_vec']->vectorIndexConfig->quantizer;       // RqConfig
$cfg->generativeConfig?->generative;                                // GenerativeSearches::OpenAI
$copy = $cfg->toArray(); $copy['class'] = 'ArticleCopy';
$client->collections->createFromArray($copy);
$all = $client->collections->listAll();                             // array<string, CollectionConfigSimple>, sorted
$client->collections->exists('ArticleCopy') && $client->collections->delete(['ArticleCopy', 'Raw']);
```

---

## 17. Test checklist

**Unit tests (no server)**, as golden JSON fixtures. Port Python's `test/collection/test_config.py` (3.5k lines), `test_config_methods.py` and `test_config_update.py` as data providers.
- Every generated factory: the default call and an all-args call, with the exact `vectorizer.{module}` JSON asserted (fixed keys, nested HF `options`, `isAzure`, `weights`, `IMUFields`, `pooling_strategy` default, `method: mean`).
- A **parity test** that the table has an entry for every Python factory at the pinned tag (§15.1).
- The shared-param rules: `name` defaulting to `default`, a list without names throwing, duplicate names throwing, empty `sourceProperties` throwing.
- The quantizer + index merge matrix: none, hnsw, flat, dynamic (both halves filled or created), multi-vector wrapping, and `encoding` placement.
- Each quantizer's create JSON, including `pq.encoder.type` (**not** `type_`) and `skipDefaultQuantization`.
- The injected default `vectorConfig.default`, and `emitDefaultVectorIndexType` on 1.37.4 versus 1.37.5.
- Property JSON: the `dataType` list, nested recursion, `textAnalyzer` validation, reserved names, reference capitalization, and moduleConfig emission (§11.3) for legacy and modern setups with defaults and explicit values.
- `invertedIndex` bm25 pairing, `stopwords` always emitted, sharding fixed keys, TTL `DateInterval` conversion (and rejection of years or months), negative offsets.
- Update merge: every Reconfigure field merged into fixture schemas. Quantizer switch rejected in each direction (including HFresh with RQ and no `pq` key). Other quantizers disabled. Generative and reranker replacement. `asyncConfig` replaced. `propertyDescriptions` with an unknown property. Missing or dropped vector names. An index-type mismatch (PHP addition). `vectorConfig` combined with the legacy args.
- Parser: every §12.2 default. Unknown module → string. Unknown index type → exception. `none` → `VectorIndexConfigNone`. MT → `shardingConfig` null. The quantizer priority order.
- `toArray()` round trip: parse → `toArray` → parse gives an equal result, including `dot` distance, multivector and PQ bit compression (the Python bugs in §12.3).
- The dropped-vector strip: some dropped (with a warning), all dropped (throws), `createFromConfig` with no vectors (throws).
- Version gates: each **Py** and **PHP** gate from §13 throws on N-1 and passes on N, using a mocked `ServerVersion`.

**Integration tests** against the CI server matrix ([05](05-testing-and-ci.md)):
- Create, get, listAll, exists, delete and deleteAll, including lowercase-name capitalization and `exists` on 404 versus 500 (toxiproxy or mock).
- Create one collection per locally runnable module (none, transformers, contextionary, clip, img2vec, ref2vec, model2vec, ollama) plus the cloud modules with keys from CI secrets. For each one, assert the round trip of `config->get()`.
- Every index type × quantizer supported by the server version, create plus update. HFresh on 1.36 and later.
- Legacy collections created through `createFromArray` (`vectorizer` and top-level `vectorIndexConfig`) parse, and can be updated through `vectorizerConfig`.
- `addProperty` on legacy, named-vector and no-vector collections, checking the property `moduleConfig`. `addReference`. Duplicates throwing. `addVector` on 1.31 and later.
- `deletePropertyIndex` (1.36 and later) and `deleteVectorIndex` (1.39 and later): the async state `VectorIndexConfigNone`, then its disappearance, a repeat during the drop, and 422 afterwards. `update` on a dropped vector throws.
- `getShards` and `updateShards`, both all and named, with and without a tenant.
- Object TTL create, update and disable (1.35 and later), confirming that the `objectTtlConfig` key round-trips (§9.4).
- `createFromConfig(exportConfig())` for a rich collection gives an equal config. Assert distance and multivector specifically.

---

## 18. Unverified or open items

1. **Python round-trip bugs** (§12.3: `distanceMetric`, `multiVector`, `internalBitCompression`), **the PQ `encoder.type_` key on create** (§6.2), **`objectTTLConfig` versus `objectTtlConfig` on update** (§9.4) and **per-property `skip`/`vectorizePropertyName` being ignored with `vectorConfig`** (§11.3). All of these were read from the source and not confirmed against a running server. Confirm them with integration tests in P1, then file upstream issues.
2. Server behaviour for `DELETE /schema/{missing}` (200 or 404) and for `addVector` with an existing name. Neither is asserted by Python.
3. Whether current servers still forbid combining sharding and replication (the Python docstring says they do).
4. Minimum versions that come only from the changelog and weren't tested: `multi2multivec-weaviate` 1.35, `generative-*` modules added recently (deepseek, digitalocean, meta, xai, contextualai), `multi2vec-twelvelabs`, `text2vec-morph`/`multi2vec-aws` 1.33 and `kagome_kr` 1.25.8. None of these is gated in Python.
5. Whether the server needs URL normalization (§15.3).
6. `Dynamic` update with a top-level `bq` quantizer: Python merges into `schema['bq']` of the dynamic config, which may not exist. Covered by a test.
7. `case Object` / `case None` in PHP enums: check with PHP 8.2 in P1.
8. Tokenization (`client.tokenization.text()` and `for_property()`, `POST /v1/tokenize` and `POST /v1/schema/{c}/properties/{p}/tokenize`, 1.37) and **export** (`client.export.create/get_status/cancel`, `/v1/export/{backend}[/{id}]`, 1.37) use config types such as `StopwordsCreate` and `TextAnalyzerConfigCreate`. They are **client-level namespaces**, and are out of scope here; see the corrections to [02](02-feature-parity-matrix.md).
