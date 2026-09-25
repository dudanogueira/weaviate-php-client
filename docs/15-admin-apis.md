# 15: Admin and management APIs specification

This maps **every** admin and management API outside `collections` in Python v4 to PHP: backups, RBAC roles and permissions, users, OIDC groups, aliases, cluster and replication, debug, export, tokenization, and the misc methods on `WeaviateClient`. The sources are on the Python client's `main` branch, read on 2026-09-25:

- `weaviate/backup/{backup,backup_location,executor}.py` and `weaviate/collections/backups/executor.py`
- `weaviate/rbac/{models,executor}.py`, `weaviate/users/{base,users,sync}.py` and `weaviate/groups/base.py`
- `weaviate/aliases/{alias,executor}.py`
- `weaviate/cluster/{base,models,types}.py`, `weaviate/cluster/replicate/executor.py` and `weaviate/collections/classes/cluster.py`
- `weaviate/debug/{executor,types}.py`, `weaviate/export/{export,executor}.py` and `weaviate/tokenization/{executor,models}.py`
- `weaviate/client.py`, `client.pyi`, `client_executor.py` and `connect/v4.py`
- The `integration/test_{backup_v4,rbac,users,groups,alias,replicate,export,tokenize,client,client_debug}.py` tests, which were used for the server minimums

Rows in [02 §9–12](02-feature-parity-matrix.md#9-backups-clientbackup-collectionbackup) link here. All of these APIs are **REST** and live under the `/v1` prefix, which is left out of the paths below.

## 1. Shared rules

### 1.1 Namespaces on `WeaviateClient`

Python builds all of these in `WeaviateClient.__init__`. PHP exposes them as `public readonly` properties, and none of them do I/O when constructed.

| Python attribute | PHP property | Class | Section |
|---|---|---|---|
| `client.backup` | `$client->backup` | `Weaviate\Client\Backup\Backup` | §2 |
| `collection.backup` | `$col->backup` | `Weaviate\Client\Collections\Backup\CollectionBackup` | §2.8 |
| `client.export` | `$client->export` | `Weaviate\Client\Export\Export` | §8 |
| `client.roles` | `$client->roles` | `Weaviate\Client\Rbac\Roles` | §3 |
| `client.users` (`.db`, `.oidc`) | `$client->users` (`->db`, `->oidc`) | `Weaviate\Client\Users\Users`, `UsersDb`, `UsersOidc` | §4 |
| `client.groups` (`.oidc`) | `$client->groups` (`->oidc`) | `Weaviate\Client\Groups\Groups`, `GroupsOidc` | §5 |
| `client.alias` | `$client->alias` | `Weaviate\Client\Alias\Alias` | §6 |
| `client.cluster` (`.replications`) | `$client->cluster` (`->replications`) | `Weaviate\Client\Cluster\Cluster`, `Replications` | §7 |
| `client.debug` | `$client->debug` | `Weaviate\Client\Debug\Debug` (`@experimental`) | §9 |
| `client.tokenization` | `$client->tokenization` | `Weaviate\Client\Tokenization\Tokenization` | §10 |
| (methods on the client) | `graphqlRawQuery()`, `getMeta()`, `isReady()`, `isLive()`, … | `WeaviateClient` | §11 |

The async package has the same tree, and each method returns `Amp\Future<T>` (or is awaited inline, depending on the design chosen in [06](06-integrations.md)).

### 1.2 Status codes and errors

Python's `_ConnectionBase.__handle_response` handles responses like this, and PHP does the same:

- **403 always** throws `ForbiddenException` (Python `InsufficientPermissionsError`), before any other check.
- Each method has an **expected status set** (Python `_ExpectedStatusCodes.ok_in`). Anything outside it throws `UnexpectedStatusCodeException`, which carries the status code and the decoded body.
- Some methods have **no expected set**. They rely on `_decode_json_response_dict`, which accepts any 2xx and throws `UnexpectedStatusCodeException` otherwise. PHP treats "no expected set" as "any 2xx".
- A 2xx whose JSON can't be decoded throws `ResponseCannotBeDecodedException`. It's a new subclass of `UnexpectedStatusCodeException`, and it should be added to [01 §Errors](01-architecture.md#errors).
- A 2xx whose body is empty where one is required throws `EmptyResponseException`. This is also new and should be added to 01.
- Where the tables below say "404 → `null`/`false`", the 404 is in the expected set and maps to that value. It's not an exception.

### 1.3 Timeouts

The timeout class comes from the HTTP method, as in Python's `__get_timeout`: `GET` and `HEAD` use `query`; `POST`, `PUT`, `PATCH` and `DELETE` use `insert`; and the one exception is `POST /graphql`, which uses `query`. The backup and export wait loops have their own overall deadline (§2.6).

### 1.4 Name normalisation, which PHP ports exactly

| Rule | Where it applies in Python |
|---|---|
| `_capitalize_first_letter` (upper-cases only the first character) | Backup and export `include`/`exclude`; `nodes(collection)`; `tokenization.forProperty(collection)`; the `collection` field of the collections, data, tenants, nodes, replicate and backups permissions; the **`alias`** field of alias permissions (the alias permission's `collection` is **not** capitalised) |
| Lower-case | `backup_id` and `incremental_base_backup_id` in backups, and `export_id` in exports ("case insensitive" in the docstrings) |
| URL-encoding path segments (`escape_string` = `quote(safe="")`) | Only `/authz/users/{id}/…` and `/authz/groups/{id}/…` |

**PHP deviation:** PHP runs `rawurlencode()` on **every** path segment that comes from user input (role names, db user ids, alias names, backup ids, UUIDs). Python leaves `/authz/roles/{role}`, `/users/db/{user}` and `/aliases/{alias}` unencoded. Encoding is only a change for ids that contain `/`, `?` or `#`, which break the Python calls anyway.

### 1.5 Query-parameter booleans

httpx serialises `True` as `true`. **PHP's `http_build_query` turns `true` into `1`**, and the Go server doesn't read `1` as true for every parameter. `RestTransport` must turn booleans into the literal strings `"true"` and `"false"` before encoding. This matters for `includeFullRoles`, `includeLastUsedTime` and `includeHistory`.

### 1.6 Version gates

The rule is:

- **Python's hard client-side gates become PHP hard gates.** They throw `UnsupportedFeatureException` before any I/O. They're marked **gate** in the tables.
- **Server minimums that only appear in the Python tests** (pytest skips) are documented in the tables but **not enforced** in PHP, the same as Python. On an older server they fail with the server's own error. They're marked **server**.

This keeps PHP from rejecting calls that Python allows, for example on a patched backport.

| Feature | Min server | Kind | Source |
|---|---|---|---|
| Backup `backupLocation` (create, restore, status, cancel) | 1.27.2 | Python gate; not needed in PHP (below the floor) | `backup/executor.py` |
| Backup `incrementalBaseBackupId` | 1.37.0 | gate | `backup/executor.py` |
| Backup `config` (CPU %, compression) | 1.25 (below the floor) | server | test skip |
| `backup.cancel` for create | 1.24.25 (below the floor) | server | test skip |
| `backup.cancel(operation: 'restore')` | 1.36.0 | server | test skip |
| `backup.listBackups` | 1.30.0 | server | test skip |
| `listBackups(sortByStartingTimeAsc: true)` | 1.33.2 | server | test skip |
| `restore(rolesRestore:, usersRestore:)` | 1.30.10 | server | test skip |
| `restore(overwriteAlias: true)` | 1.32.0 (the skip message says 1.33) | server | test skip |
| RBAC roles and permissions (the base scopes) | 1.28.0 | server | test skip |
| `Permissions::roles(scope:)` (`RoleScope`) | 1.28.4 | server | test skip |
| Alias, replicate and groups permissions | 1.32.0 | server | test params |
| MCP permissions | 1.37.0 | server | test params |
| `users->getMyUser` | 1.28.0 | server | test skip |
| `users->db->*` and `users->oidc->*` | 1.30.0 | server | test skip |
| `groups->oidc->*`, `roles->getGroupAssignments` | 1.32.0 | server | test skip |
| `alias->*` (all methods) | 1.32.0 | **gate** (`check_is_at_least_1_32_0`) | `aliases/executor.py` |
| `cluster->replicate`, `replications->*`, `queryShardingState` | 1.32.0 | server | test skip |
| `cluster->statistics` | † not verified | server | no skip in the tests |
| `export->*` | 1.37.0 | **gate** | `export/executor.py` |
| `tokenization->*` | 1.37.0 | **gate** | `tokenization/executor.py` |

The client floor is **1.29.0** ([ADR 0004](decisions/0004-server-version-floor.md)), so every gate at or below 1.29 is moot. That includes the 1.27.2 backup-location gate and the 1.28 RBAC minimums. They're listed above for reference only; PHP doesn't implement them.

---

## 2. Backups (`$client->backup`)

### 2.1 Enums

```php
enum BackupStorage: string {            // Python BackupStorage (ExportStorage is an alias of it)
    case Filesystem = 'filesystem';
    case S3 = 's3';
    case GCS = 'gcs';
    case Azure = 'azure';
}

enum BackupStatus: string {             // Python BackupStatus
    case Started = 'STARTED';
    case Transferring = 'TRANSFERRING';
    case Transferred = 'TRANSFERRED';
    case Cancelling = 'CANCELLING';
    case Finalizing = 'FINALIZING';
    case Success = 'SUCCESS';
    case Failed = 'FAILED';
    case Canceled = 'CANCELED';

    public function isTerminal(): bool;   // Success, Failed or Canceled. PHP addition
}

enum BackupCompressionLevel: string {   // Python BackupCompressionLevel
    case Default = 'DefaultCompression';
    case BestSpeed = 'BestSpeed';
    case BestCompression = 'BestCompression';
    case ZstdBestSpeed = 'ZstdBestSpeed';
    case ZstdDefault = 'ZstdDefaultCompression';
    case ZstdBestCompression = 'ZstdBestCompression';
    case NoCompression = 'NoCompression';
}

enum RestoreOption: string {            // Python Literal["noRestore", "all"]
    case NoRestore = 'noRestore';
    case All = 'all';
}
```

Every `backend:` parameter takes `BackupStorage|string`. A string is lower-cased and passed through `BackupStorage::from()`. An unknown value throws `InvalidInputException` that lists the allowed values, like Python's `ValueError`.

### 2.2 Config objects

Python: `BackupConfigCreate(cpu_percentage, chunk_size, compression_level)` and `BackupConfigRestore(cpu_percentage)`. Both use pydantic `model_dump(exclude_none=True)`, so the wire keys are the **field names**, in PascalCase.

```php
final readonly class BackupConfigCreate {
    public function __construct(
        public ?int $cpuPercentage = null,                       // wire "CPUPercentage"
        public ?int $chunkSize = null,                           // DEPRECATED, never sent (see below)
        public ?BackupCompressionLevel $compressionLevel = null, // wire "CompressionLevel"
    ) {}
}

final readonly class BackupConfigRestore {
    public function __construct(
        public ?int $cpuPercentage = null,                       // wire "CPUPercentage"
    ) {}
}
```

| Python field | PHP field | Type | Wire key | Notes |
|---|---|---|---|---|
| `cpu_percentage` | `cpuPercentage` | `?int` | `CPUPercentage` | Python doesn't validate the range. The server accepts 1–80 †. PHP validates `1..100` and leaves the rest to the server |
| `chunk_size` | `chunkSize` | `?int` | — | **Deprecated, and has no effect.** Python marks it `exclude=True`. PHP accepts it, logs one PSR-3 deprecation notice, and never sends it |
| `compression_level` | `compressionLevel` | `?BackupCompressionLevel` | `CompressionLevel` | Create only |
| — | — | — | `rolesOptions`, `usersOptions` | Restore only. These come from the `rolesRestore`/`usersRestore` arguments, not from the config object (§2.4) |
| — | — | — | `path`, `bucket` | Merged in from `backupLocation` (§2.3) |

`null` fields are left out. If nothing ends up in the config, the `config` key is left out of the body.

### 2.3 Backup location (dynamic path and bucket)

Python: `BackupLocation.FileSystem(path)`, `.S3(path, bucket)`, `.GCP(path, bucket)` and `.Azure(path, bucket)`.

```php
abstract readonly class BackupLocation {
    public static function filesystem(string $path): BackupLocationFilesystem;
    public static function s3(string $path, string $bucket): BackupLocationS3;
    public static function gcp(string $path, string $bucket): BackupLocationGcp;
    public static function azure(string $path, string $bucket): BackupLocationAzure;

    /** @return array{path: string, bucket?: string} */
    public function toArray(): array;
}
```

| Variant | Fields | Pairs with backend |
|---|---|---|
| `filesystem` | `path` | `BackupStorage::Filesystem` |
| `s3` | `path`, `bucket` | `BackupStorage::S3` |
| `gcp` | `path`, `bucket` | `BackupStorage::GCS`. Python calls it `GCP`, not `GCS`, and PHP keeps `gcp()` for parity |
| `azure` | `path`, `bucket` (the container) | `BackupStorage::Azure` |

How it's used:
- **create and restore:** it's merged into the `config` object as `config.path` and `config.bucket`.
- **status and cancel:** it's sent as the query parameters `?path=…&bucket=…`.
- Always available: the server minimum (1.27.2) is below the 1.29 floor, so there's no gate.
- Python doesn't check that the location variant matches `backend`. PHP **logs a warning** when they don't match (for example `s3()` with `Filesystem`), but still sends the request, because the server has the final say.

This is where "the bucket and path" go. Credentials, endpoints and regions for S3, GCS and Azure are **server-side module config** (`BACKUP_S3_BUCKET`, `BACKUP_FILESYSTEM_PATH` and so on), and they are never sent by the client.

### 2.4 Methods

#### `create()` (Python `backup.create`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `backup_id` | `backupId` | `string` | required | Lower-cased |
| `backend` | `backend` | `BackupStorage\|string` | required | |
| `include_collections` | `includeCollections` | `string\|list<string>\|null` | `null` | First letter capitalised. Setting both this and `excludeCollections` (non-empty) throws `InvalidInputException` |
| `exclude_collections` | `excludeCollections` | `string\|list<string>\|null` | `null` | |
| `incremental_base_backup_id` | `incrementalBaseBackupId` | `?string` | `null` | Lower-cased. **gate 1.37.0** |
| `wait_for_completion` | `waitForCompletion` | `bool` | `false` | §2.6 |
| `config` | `config` | `?BackupConfigCreate` | `null` | |
| `backup_location` | `backupLocation` | `?BackupLocation` | `null` | — (below the floor) |
| — | `waitTimeout` | `?float` (seconds) | `null` (no limit, like Python) | PHP addition, §2.6 |
| — | `pollInterval` | `float` (seconds) | `1.0` | PHP addition. Python hard-codes 1 s |

- **REST:** `POST /backups/{backend}`, expecting any 2xx.
- **Body.** This matches Python exactly, including empty arrays and a JSON `null`:
  ```json
  {"id": "nightly-1", "include": ["Article"], "exclude": [], "incremental_base_backup_id": null,
   "config": {"CPUPercentage": 40, "CompressionLevel": "ZstdDefaultCompression", "path": "…", "bucket": "…"}}
  ```
  `incremental_base_backup_id` is **snake_case** on the wire, and PHP sends it that way. `config` is left out when it's empty.
- **Returns** `BackupReturn` (§2.5). When the call waits, `status` is updated to the final status.

#### `getCreateStatus()` (Python `get_create_status`)

| Python param | PHP param | Type | Default |
|---|---|---|---|
| `backup_id` | `backupId` | `string` | required (lower-cased) |
| `backend` | `backend` | `BackupStorage\|string` | required |
| `backup_location` | `backupLocation` | `?BackupLocation` | `null` |

- **REST:** `GET /backups/{backend}/{id}[?path=&bucket=]`, expecting any 2xx. An empty body throws `EmptyResponseException`.
- **Returns** `BackupStatusReturn`. The client sets `backupId` to the id that was **requested**, not the one in the response, like Python's `typed_response["id"] = backup_id`.

#### `restore()` (Python `backup.restore`)

| Python param | PHP param | Type | Default | Notes |
|---|---|---|---|---|
| `backup_id` | `backupId` | `string` | required | Lower-cased |
| `backend` | `backend` | `BackupStorage\|string` | required | |
| `include_collections` | `includeCollections` | `string\|list<string>\|null` | `null` | The same rules as for create |
| `exclude_collections` | `excludeCollections` | `string\|list<string>\|null` | `null` | |
| `roles_restore` | `rolesRestore` | `RestoreOption\|string\|null` | `null` | Goes to `config.rolesOptions` (server 1.30.10) |
| `users_restore` | `usersRestore` | `RestoreOption\|string\|null` | `null` | Goes to `config.usersOptions` (server 1.30.10) |
| `wait_for_completion` | `waitForCompletion` | `bool` | `false` | |
| `config` | `config` | `?BackupConfigRestore` | `null` | |
| `backup_location` | `backupLocation` | `?BackupLocation` | `null` | — |
| `overwrite_alias` | `overwriteAlias` | `bool` | `false` | **Always sent** (server 1.32 for `true`) |
| — | `waitTimeout`, `pollInterval` | as for create | | PHP additions |

- **REST:** `POST /backups/{backend}/{id}/restore`, expecting any 2xx.
- **Body:** `{"include": [...], "exclude": [...], "overwriteAlias": false, "config": {...}}`. The `config` key is only present when there's something in it (`CPUPercentage`, `path`, `bucket`, `rolesOptions` or `usersOptions`).
- **Returns** `BackupReturn`.

#### `getRestoreStatus()` (Python `get_restore_status`)

It takes the same parameters as `getCreateStatus`. It calls `GET /backups/{backend}/{id}/restore[?path=&bucket=]` and returns `BackupStatusReturn`, with `backupId` set to the requested id.

#### `cancel()` (Python `backup.cancel`)

| Python param | PHP param | Type | Default |
|---|---|---|---|
| `backup_id` | `backupId` | `string` | required (lower-cased) |
| `backend` | `backend` | `BackupStorage\|string` | required |
| `backup_location` | `backupLocation` | `?BackupLocation` | `null` |
| `operation` | `operation` | `BackupOperation\|string` | `BackupOperation::Create` (`'create'`) |

`BackupOperation` is a PHP enum with the cases `Create` and `Restore`. It stands in for Python's `Literal["create", "restore"]`.

- **REST:** `DELETE /backups/{backend}/{id}` for create, or `DELETE /backups/{backend}/{id}/restore` for restore (server 1.36). The location goes in the query string. The expected status set is `[204, 404]`.
- **Returns** `bool`. 204 returns `true`.
- **Deviation:** in Python, a 404 falls through to `_decode_json_response_dict`, and that **raises** on anything that isn't 2xx. So in practice, cancelling an unknown or finished backup raises, even though 404 is in the "ok" list. PHP does what the code clearly meant: **404 returns `false`**, and any other 2xx with a body also returns `false`. Confirm this with the Python team and fix it upstream, or else mirror the raise.

#### `listBackups()` (Python `list_backups`)

| Python param | PHP param | Type | Default |
|---|---|---|---|
| `backend` | `backend` | `BackupStorage\|string` | required |
| `sort_by_starting_time_asc` | `sortByStartingTimeAsc` | `?bool` | `null` |

- **REST:** `GET /backups/{backend}`, with `?order=asc` **only** when the flag is truthy. `false` and `null` send nothing, which leaves the order to the server. The expected status is `200`.
- **Returns** `list<BackupListReturn>`.
- The PHP name is `listBackups`, **not** `list`, to follow the Python name (03 rule). This corrects [02 §9](02-feature-parity-matrix.md).

### 2.5 Result objects (`Weaviate\Client\Backup\Result\*`, `final readonly`)

| PHP class / field | Type | Wire key | Default | Python |
|---|---|---|---|---|
| **`BackupStatusReturn`** | | | | `BackupStatusReturn` |
| `error` | `?string` | `error` | `null` | |
| `status` | `BackupStatus` | `status` | required | |
| `path` | `string` | `path` | required | The storage URI the server reports |
| `backupId` | `string` | `id` | required | Overwritten with the requested id on the status calls |
| `size` | `float` | `size` | `0.0` | In bytes (a float in the server response) |
| **`BackupReturn`** extends `BackupStatusReturn` | | | | `BackupReturn` |
| `collections` | `list<string>` | `classes` | `[]` | |
| **`BackupListReturn`** | | | | `BackupListReturn` |
| `collections` | `list<string>` | `classes` | `[]` | |
| `status` | `BackupStatus` | `status` | required | |
| `backupId` | `string` | `id` | required | |
| `startedAt` | `?\DateTimeImmutable` | `startedAt` | `null` | RFC 3339 |
| `completedAt` | `?\DateTimeImmutable` | `completedAt` | `null` | |
| `size` | `float` | `size` | `0.0` | |
| `incrementalBaseBackupId` | `?string` | `incremental_base_backup_id` | `null` | Also `null` when the caller isn't root: the server hides the field |

Unknown response keys are ignored, the same as pydantic's default. An unknown `status` string makes `BackupStatus::from()` fail and throws `UnexpectedStatusCodeException`. **Open question:** should that be tolerant (`tryFrom`, keeping the raw string) so that a new server status doesn't break older clients? Python fails too, so the default is to fail.

### 2.6 `waitForCompletion` in PHP (a blocking poll loop)

Python runs `while True: status = get_*_status(); …; time.sleep(1)`, with **no overall timeout**. PHP keeps that loop and adds an optional deadline:

```php
private function waitFor(callable $poll, BackupReturn $initial, ?float $waitTimeout, float $pollInterval): BackupReturn
{
    $deadline = $waitTimeout === null ? null : hrtime(true) + (int) ($waitTimeout * 1e9);
    while (true) {
        $s = $poll();                                   // getCreateStatus / getRestoreStatus, same backupLocation
        $current = $initial->withStatus($s->status);    // like Python, only `status` is copied onto the create response
        match ($s->status) {
            BackupStatus::Success  => return $current,
            BackupStatus::Failed   => throw new BackupFailedException($current, $s->error),
            BackupStatus::Canceled => throw new BackupCanceledException($current, $s->error),
            default => null,
        };
        if ($deadline !== null && hrtime(true) + (int) ($pollInterval * 1e9) > $deadline) {
            throw new BackupTimeoutException($current, $waitTimeout);   // the backup keeps running on the server
        }
        usleep((int) ($pollInterval * 1e6));
    }
}
```

The rules:

1. **The first poll happens right away**, with no sleep before it. That's the same as Python.
2. **`hrtime()` is a monotonic clock**, so wall-clock changes can't cut the wait short or stretch it.
3. **Only terminal statuses stop the loop.** `SUCCESS` returns. `FAILED` throws `BackupFailedException`, and `CANCELED` throws **`BackupCanceledException`**, which is new in PHP and matches Python's `BackupCanceledError`. Both exceptions carry `->result` (the last `BackupReturn`) and `->serverError`, and both extend `BackupException`.
4. **A timeout doesn't cancel the backup.** `BackupTimeoutException` extends `BackupException` and carries the last status, so the caller can go on polling or call `cancel()` explicitly. Cancelling behind the caller's back would be surprising.
5. **`pollInterval` must be greater than 0 and `waitTimeout` must be at least 0**, or `InvalidInputException` is thrown before any I/O. A `waitTimeout` of `0` means "check the status once, then give up if it isn't terminal".
6. **Each poll has its own timeout.** Every poll is an ordinary `GET` that uses the `query` timeout (§1.3). Transient 5xx and network errors on a poll go through the normal `RetryConfig`. If the retries run out, the exception is thrown and the loop ends.
7. **PHP-FPM and `max_execution_time`.** The loop blocks the worker. The docs must say to use `waitForCompletion` **only in CLI, queue workers or cron**, and in web requests to call `create()` and then `getCreateStatus()` later. PHP **doesn't** call `set_time_limit()`. If `waitTimeout` is `null` and `ini_get('max_execution_time') > 0`, PHP logs a one-time notice that the script may be killed before the backup finishes.
8. **Async package:** the same loop, using `Amp\delay($pollInterval)` instead of `usleep`, so it doesn't block the event loop.
9. **Result shape:** the loop returns the **create or restore response** with its `status` replaced. It doesn't return the status response. This matches Python, where `collections` comes from the create response.
10. **The same `waitFor()` helper is used by `export->create`** (§8), which has its own exception classes.

### 2.7 Validation (it runs before any I/O, like Python's `_get_and_validate_*`)

- `backupId` must be a string that isn't empty. PHP adds the non-empty check.
- `include` and `exclude` each take `string|list<string>|null`. A string is wrapped in a list and `null` becomes `[]`. **If both lists are non-empty**, `InvalidInputException` is thrown ("Either 'includeCollections' OR 'excludeCollections' can be set, not both."). Python raises `TypeError` here.
- A `backend` string that isn't one of the four values throws `InvalidInputException`.
- `waitForCompletion` is typed `bool` in PHP, so Python's runtime type check isn't needed.

### 2.8 Collection-scoped backups (`$col->backup`, Python `_CollectionBackup`)

This is a thin wrapper around §2.4 with `includeCollections: [$this->name]` and `excludeCollections: null`. **It has no `cancel`, `listBackups`, incremental, `rolesRestore` or `usersRestore`.** Python's collection wrapper leaves them out, and PHP does too.

| Python | PHP | Returns | Notes |
|---|---|---|---|
| `create(backup_id, backend, wait_for_completion=False, config=None, backup_location=None)` | `create(backupId:, backend:, waitForCompletion: false, config: null, backupLocation: null, waitTimeout: null, pollInterval: 1.0)` | `BackupStatusReturn` | Python rebuilds the return from `error`, `status` and `path`, with `id` set to `backup_id`. **`size` is not copied**, so it's always `0` |
| `restore(backup_id, backend, wait_for_completion=False, config=None, backup_location=None, overwrite_alias=False)` | `restore(backupId:, backend:, waitForCompletion: false, config: null, backupLocation: null, overwriteAlias: false, waitTimeout: null, pollInterval: 1.0)` | `BackupStatusReturn` | |
| `get_create_status(backup_id, backend, backup_location=None)` | `getCreateStatus(...)` | `BackupStatusReturn` | Passed straight through |
| `get_restore_status(backup_id, backend, backup_location=None)` | `getRestoreStatus(...)` | `BackupStatusReturn` | Passed straight through |

The collection handle's tenant and consistency level don't affect backups.

### 2.9 Example

```php
use Weaviate\Client\Backup\{BackupStorage, BackupConfigCreate, BackupConfigRestore, BackupCompressionLevel, BackupLocation, RestoreOption};
use Weaviate\Client\Exceptions\{BackupFailedException, BackupTimeoutException};

// CLI / cron: blocking with a deadline
try {
    $res = $client->backup->create(
        backupId: 'nightly-2026-09-25',
        backend: BackupStorage::S3,
        includeCollections: ['Article', 'Author'],
        waitForCompletion: true,
        waitTimeout: 3600,
        config: new BackupConfigCreate(cpuPercentage: 40, compressionLevel: BackupCompressionLevel::ZstdDefault),
        backupLocation: BackupLocation::s3(path: 'weaviate/prod', bucket: 'acme-backups'),
    );
    echo $res->status->value, ' ', $res->path, PHP_EOL;   // SUCCESS s3://acme-backups/weaviate/prod/nightly-2026-09-25
} catch (BackupTimeoutException $e) {
    $client->backup->cancel(backupId: 'nightly-2026-09-25', backend: 's3',
        backupLocation: BackupLocation::s3('weaviate/prod', 'acme-backups'));
} catch (BackupFailedException $e) {
    error_log($e->serverError ?? 'unknown');
}

// Web request: start it, then poll from a later request
$client->backup->create(backupId: 'adhoc-1', backend: 'filesystem');
$status = $client->backup->getCreateStatus(backupId: 'adhoc-1', backend: 'filesystem');

// Incremental (1.37+) and restore with RBAC data (1.30.10+)
$client->backup->create(backupId: 'inc-2', backend: BackupStorage::GCS, incrementalBaseBackupId: 'nightly-2026-09-25');
$client->backup->restore(
    backupId: 'nightly-2026-09-25', backend: BackupStorage::S3,
    rolesRestore: RestoreOption::All, usersRestore: RestoreOption::NoRestore,
    overwriteAlias: true, waitForCompletion: true,
    config: new BackupConfigRestore(cpuPercentage: 60),
);

foreach ($client->backup->listBackups(BackupStorage::S3, sortByStartingTimeAsc: true) as $b) {
    printf("%s %s %s\n", $b->backupId, $b->status->value, $b->startedAt?->format(DATE_ATOM));
}

// Collection-scoped
$client->collections->use('Article')->backup->create(backupId: 'article-only', backend: 'filesystem', waitForCompletion: true);
```

---

## 3. RBAC: roles and permissions (`$client->roles`)

### 3.1 The permission model

A **permission** is one or more **actions** on a **resource**. On the wire each action is its own object: `{"action": "<action>", "<scopeKey>": {<resource fields>}}`. A builder with several flags set therefore turns into **several wire permissions**, one for each action. The cluster and MCP permissions have **no resource object**.

| Scope | PHP builder | Actions enum → wire values | Wire resource key | Resource fields (wire) | Defaults and normalisation | Min server |
|---|---|---|---|---|---|---|
| Alias | `Permissions::alias(alias:, collection:, create:, read:, update:, delete:)` | `AliasAction`: `create_aliases`, `read_aliases`, `update_aliases`, `delete_aliases` | `aliases` | `alias`, `collection` | `alias` is **capitalised**; `collection` is left as it is | 1.32 |
| Collections | `Permissions::collections(collection:, createCollection:, readConfig:, updateConfig:, deleteCollection:)` | `CollectionsAction`: `create_collections`, `read_collections`, `update_collections`, `delete_collections`, `manage_collections`* | `collections` | `collection` | Capitalised | 1.28 |
| Data | `Permissions::data(collection:, tenant: null, create:, read:, update:, delete:)` | `DataAction`: `create_data`, `read_data`, `update_data`, `delete_data`, `manage_data`* | `data` | `collection`, `tenant` | `collection` capitalised; `tenant` defaults to `'*'` | 1.28 (tenant 1.30 †) |
| Tenants | `Permissions::tenants(collection:, tenant: null, create:, read:, update:, delete:)` | `TenantsAction`: `create_tenants`, `read_tenants`, `update_tenants`, `delete_tenants` | `tenants` | `collection`, `tenant` | `collection` capitalised; `tenant` defaults to `'*'` | 1.28 (tenant 1.30 †) |
| Replicate | `Permissions::replicate(collection:, shard: null, create:, read:, update:, delete:)` | `ReplicateAction`: `create_replicate`, `read_replicate`, `update_replicate`, `delete_replicate` | `replicate` | `collection`, `shard` | `collection` capitalised; `shard` defaults to `'*'` | 1.32 |
| Roles | `Permissions::roles(role:, create:, read:, update:, delete:, scope: null)` | `RolesAction`: `create_roles`, `read_roles`, `update_roles`, `delete_roles`, `manage_roles`* | `roles` | `role`, `scope`? | `scope` is sent only when set: `RoleScope::Match` (`match`) or `RoleScope::All` (`all`) | 1.28 (scope 1.28.4) |
| Users | `Permissions::users(user:, create:, read:, update:, delete:, assignAndRevoke:)` | `UsersAction`: `create_users`, `read_users`, `update_users`, `delete_users`, `assign_and_revoke_users` | `users` | `users` (**plural key**) | Left as it is | 1.28 (create, update, delete 1.30 †) |
| Groups | `Permissions::groups()->oidc(group:, read:, assignAndRevoke:)` | `GroupAction`: `read_groups`, `assign_and_revoke_groups` | `groups` | `group`, `groupType` | `groupType` is always `'oidc'` | 1.32 |
| Backups | `Permissions::backup(collection:, manage:)` | `BackupsAction`: `manage_backups` | `backups` | `collection` | Capitalised | 1.28 |
| Nodes (verbose) | `Permissions::nodes()->verbose(collection:, read:)` | `NodesAction`: `read_nodes` | `nodes` | `collection`, `verbosity` = `'verbose'` | Capitalised | 1.28 |
| Nodes (minimal) | `Permissions::nodes()->minimal(read:)` | `NodesAction`: `read_nodes` | `nodes` | `collection` = `'*'`, `verbosity` = `'minimal'` | There's no collection argument | 1.28 |
| Cluster | `Permissions::cluster(read:)` | `ClusterAction`: `read_cluster` | — | — | | 1.28 |
| MCP | `Permissions::mcp(create:, read:, update:)` | `MCPAction`: `create_mcp`, `read_mcp`, `update_mcp` | — | — | There's no resource | 1.37 |

`*` means **output only or backward compatibility.** Python's builders never emit `manage_collections`, `manage_data` or `manage_roles` ("backward compatibility, remove in a bit"), but those values can come back from the server, so the enum cases exist for parsing. PHP builders don't emit them either. Users who need them can build `new CollectionsPermission(collection: 'X', actions: [CollectionsAction::Manage])` directly.

The builder rules, ported exactly:
- **Resource arguments take `string|list<string>`.** That's `alias`, `collection`, `tenant`, `shard`, `role`, `user` and `group`. The builder produces the **cartesian product**: `alias × collection` for alias, `collection × tenant` for data and tenants, and `collection × shard` for replicate. It returns **one `Permission` per resource combination**, and each one holds every flagged action.
- **If no action flag is true, the builder returns `[]`.** It doesn't throw. PHP adds one thing: `roles->create()` throws `InvalidInputException` when the flattened list is empty, because the server would reject the request anyway. **Check** whether the server allows a role with no permissions (†). If it does, drop this check.
- Every flag defaults to `false`, and every argument is **named only**. Python uses a bare `*`, and PHP documents named-argument use and doesn't promise positional order.
- The wildcards `'*'` and glob patterns such as `'Art*'` are plain strings that the server interprets.
- **The capitalisation in the table is applied when the wire array is built**, not in the constructor. A permission that was read back from the server is never re-capitalised.

### 3.2 PHP types (`Weaviate\Client\Rbac\…`)

```php
enum RoleScope: string { case Match = 'match'; case All = 'all'; }

// One backed enum per scope, all implementing the marker interface Weaviate\Client\Rbac\Action\Action
enum CollectionsAction: string implements Action { case Create = 'create_collections'; case Read = 'read_collections'; case Update = 'update_collections'; case Delete = 'delete_collections'; case Manage = 'manage_collections'; }
// … AliasAction, DataAction, TenantsAction, RolesAction, UsersAction, GroupAction,
//   ClusterAction, NodesAction, BackupsAction, MCPAction, ReplicateAction (values in §3.1)

final class Actions {   // Python `Actions` namespace; class-string constants for discoverability
    public const Alias = AliasAction::class;  public const Data = DataAction::class; /* … all 12 … */
}

abstract readonly class Permission {
    /** @param list<Action> $actions  (deduplicated, order-insensitive; Python uses a Set) */
    public function __construct(public array $actions) {}
    /** @return list<array<string,mixed>> one wire object per action */
    abstract public function toWeaviate(): array;
}
final readonly class CollectionsPermission extends Permission { public string $collection; }
final readonly class DataPermission extends Permission { public string $collection; public string $tenant; }
final readonly class TenantsPermission extends Permission { public string $collection; public string $tenant; }
final readonly class ReplicatePermission extends Permission { public string $collection; public string $shard; }
final readonly class RolesPermission extends Permission { public string $role; public ?RoleScope $scope; }
final readonly class UsersPermission extends Permission { public string $users; }
final readonly class GroupsPermission extends Permission { public string $group; public string $groupType; }
final readonly class AliasPermission extends Permission { public string $alias; public string $collection; }
final readonly class BackupsPermission extends Permission { public string $collection; }
final readonly class NodesPermission extends Permission { public string $collection; public string $verbosity; }
final readonly class ClusterPermission extends Permission {}
final readonly class MCPPermission extends Permission {}
```

**Design decision: one class per scope, used for both input and output.** Python has `_CollectionsPermission`, used for input, and a subclass `CollectionsPermissionOutput`, used for output, with no difference between them. PHP merges the two. `Role` exposes the same classes, so a permission read back from the server can be passed straight to `addPermissions`, `removePermissions` or `hasPermissions`, as it can in Python.

`permissions:` parameters take `Permission|list<Permission|list<Permission>>` and flatten one level, like Python's `_flatten_permissions`. That's so `[Permissions::data(...), Permissions::collections(...)]` works, because each builder returns a list.

### 3.3 The `Role` result and parsing

```php
final readonly class RoleBase { public function __construct(public string $name) {} }

final readonly class Role extends RoleBase {
    /** @var list<AliasPermission> */       public array $aliasPermissions;
    /** @var list<ClusterPermission> */     public array $clusterPermissions;
    /** @var list<CollectionsPermission> */ public array $collectionsPermissions;
    /** @var list<DataPermission> */        public array $dataPermissions;
    /** @var list<RolesPermission> */       public array $rolesPermissions;
    /** @var list<UsersPermission> */       public array $usersPermissions;
    /** @var list<BackupsPermission> */     public array $backupsPermissions;
    /** @var list<MCPPermission> */         public array $mcpPermissions;
    /** @var list<NodesPermission> */       public array $nodesPermissions;
    /** @var list<TenantsPermission> */     public array $tenantsPermissions;
    /** @var list<ReplicatePermission> */   public array $replicatePermissions;
    /** @var list<GroupsPermission> */      public array $groupsPermissions;

    /** @return list<Permission> in exactly this order: alias, cluster, collections, data, roles, users, backups, mcp, nodes, tenants, replicate, groups */
    public function permissions(): array;
}
```

`Role::fromWeaviate(array $role)` is a port of `Role._from_weaviate_role`:

1. **Classify by action string.** The action sets are disjoint, so the order doesn't matter.
2. **Read the resource object** under the scope key. If it's missing, **skip the permission silently**, as Python does (for example a `users` action with no `users` object). Cluster and MCP permissions never have one.
3. **Fill in defaults:** `tenants.tenant` and `data.tenant` default to `'*'`, `nodes.collection` defaults to `'*'`, `replicate.shard` defaults to `'*'`, and `roles.scope` becomes `null` when it's missing or empty.
4. **Unknown action strings** produce a PSR-3 `warning` (Python `_Warnings.unknown_permission_encountered`) and are skipped. They don't throw, so older clients keep working against newer servers.
5. **Join per scope** (`_join_permissions`). Permissions whose non-action fields are all equal are merged into one, with the union of their actions, and **first-seen order** is kept. The PHP key is `serialize()` of the resource fields, excluding `actions`.

### 3.4 Methods

| Python | PHP | REST | Expected | Returns | Notes |
|---|---|---|---|---|---|
| `list_all()` | `listAll()` | `GET /authz/roles` | 200 | `array<string, Role>` keyed by name | |
| `exists(role_name)` | `exists(string $roleName)` | `GET /authz/roles/{role}` | 200, 404 | `bool` (whether the status is 200) | |
| `get(role_name)` | `get(string $roleName)` | `GET /authz/roles/{role}` | 200, 404 | `?Role` | |
| `create(*, role_name, permissions)` | `create(roleName:, permissions:)` | `POST /authz/roles` body `{"name", "permissions": [...]}` | **201** | `Role` | **The return value is built from the request**, not from the response (Python `Role._from_weaviate_role(role)`) |
| `delete(role_name)` | `delete(string $roleName)` | `DELETE /authz/roles/{role}` | 204 | `void` | A 404 throws `UnexpectedStatusCodeException` |
| `add_permissions(*, permissions, role_name)` | `addPermissions(permissions:, roleName:)` | `POST /authz/roles/{role}/add-permissions` body `{"permissions": [...]}` | 200 | `void` | An upsert |
| `remove_permissions(*, permissions, role_name)` | `removePermissions(permissions:, roleName:)` | `POST /authz/roles/{role}/remove-permissions` | 200 | `void` | Missing permissions are ignored. **If that leaves the role with no permissions, the server deletes the role** (Python docstring) |
| `has_permissions(*, permissions, role)` | `hasPermissions(permissions:, role:)` | `POST /authz/roles/{role}/has-permission` body = **one** wire permission | 200, 404 | `bool` | See below |
| `get_user_assignments(role_name)` | `getUserAssignments(string $roleName)` | `GET /authz/roles/{role}/user-assignments` | 200 | `list<UserAssignment>` | `[{userId, userType}]` |
| `get_group_assignments(role_name)` | `getGroupAssignments(string $roleName)` | `GET /authz/roles/{role}/group-assignments` | 200 | `list<GroupAssignment>` | `[{groupId, groupType}]`, server 1.32 |
| `get_current_roles()` (deprecated, "remove Q4 25") | **not ported** | `GET /authz/users/own-roles` | | | Use `users->getMyUser()` |
| `get_assigned_user_ids(role_name)` (deprecated) | **not ported** | `GET /authz/roles/{role}/users` | | | Use `getUserAssignments` |

**`hasPermissions` fans out.** It sends **one request for each wire permission**, meaning each (resource, action) pair, and returns `true` only when every one returns 200. A 404 counts as `false`.
- The sync version short-circuits on the first `false`, as Python's `all(generator)` does, and sends the requests one after another.
- The async version sends them all at once, like Python's `asyncio.gather`, with no short-circuit.
- The docs must warn that `Permissions::data(collection: [...10], tenant: [...10], create: true, read: true, update: true, delete: true)` means **400 HTTP calls**. PHP also logs a notice when the fan-out is larger than 50.

Result value objects:

```php
enum UserTypes: string { case DbDynamic = 'db_user'; case DbStatic = 'db_env_user'; case Oidc = 'oidc'; }
enum GroupTypes: string { case Oidc = 'oidc'; }
final readonly class UserAssignment { public function __construct(public string $userId, public UserTypes $userType) {} }
final readonly class GroupAssignment { public function __construct(public string $groupId, public GroupTypes $groupType) {} }
```

### 3.5 Example

```php
use Weaviate\Client\Rbac\{Permissions, RoleScope};

$client->roles->create(
    roleName: 'tenant-editor',
    permissions: [
        Permissions::collections(collection: 'Article', readConfig: true),
        Permissions::data(collection: 'Article', tenant: ['acme', 'globex'], create: true, read: true, update: true),
        Permissions::tenants(collection: 'Article', tenant: 'acme', read: true),
        Permissions::nodes()->minimal(read: true),
        Permissions::roles(role: 'tenant-*', read: true, scope: RoleScope::Match),
        Permissions::alias(alias: 'Articles*', collection: 'Article', read: true),
        Permissions::groups()->oidc(group: '/eng', read: true),
        Permissions::mcp(read: true),            // 1.37+
    ],
);

$role = $client->roles->get('tenant-editor');
foreach ($role?->dataPermissions ?? [] as $p) {
    echo $p->collection, '/', $p->tenant, ': ', implode(',', array_map(fn ($a) => $a->value, $p->actions)), PHP_EOL;
}

$client->roles->addPermissions(roleName: 'tenant-editor', permissions: Permissions::backup(collection: 'Article', manage: true));
$ok = $client->roles->hasPermissions(role: 'tenant-editor', permissions: $role->dataPermissions);   // round-trip
```

---

## 4. Users (`$client->users`, `->db`, `->oidc`)

### 4.1 `$client->users`

| Python | PHP | REST | Expected | Returns | Notes |
|---|---|---|---|---|---|
| `get_my_user()` | `getMyUser()` | `GET /users/own-info` | 200 | `OwnUser` | On 1.29 the id is under `username`, and on later versions under `user_id`: take `username` when it's present. `roles: null` becomes `[]`, and a missing `groups` becomes `[]` |
| `get_assigned_roles(user_id)` (deprecated) | **not ported** | `GET /authz/users/{id}/roles` | | | Use `->db` or `->oidc` |
| `assign_roles(*, user_id, role_names)` (deprecated) | **not ported** | `POST /authz/users/{id}/assign`, with no `userType` | | | |
| `revoke_roles(*, user_id, role_names)` (deprecated) | **not ported** | | | | |

```php
final readonly class OwnUser {
    /** @param array<string, Role> $roles  @param list<string> $groups */
    public function __construct(public string $userId, public array $roles, public array $groups) {}
}
```

### 4.2 `$client->users->db` (database users, server 1.30)

| Python | PHP | REST | Expected | Returns | Notes |
|---|---|---|---|---|---|
| `create(*, user_id)` | `create(userId:)` | `POST /users/db/{id}` body `{}` | 201 | `string` (the API key) | **The key can't be read again later.** The docs must say so clearly |
| `delete(*, user_id)` | `delete(userId:)` | `DELETE /users/db/{id}` | 204, 404 | `bool` (`true` on 204) | |
| `rotate_key(*, user_id)` | `rotateKey(userId:)` | `POST /users/db/{id}/rotate-key` body `{}` | 200 | `string` (the new key) | |
| `activate(*, user_id)` | `activate(userId:)` | `POST /users/db/{id}/activate` body `{}` | 200, 409 | `bool` (`false` on 409 = already active) | |
| `deactivate(*, user_id, revoke_key=False)` | `deactivate(userId:, revokeKey: false)` | `POST /users/db/{id}/deactivate` body `{"revoke_key": bool}` | 200, 409 | `bool` (`false` on 409 = already inactive) | The body key is **snake_case** |
| `get(*, user_id, include_last_used_time=False)` | `get(userId:, includeLastUsedTime: false)` | `GET /users/db/{id}?includeLastUsedTime=true\|false` | 200, 404 | `?UserDB` | The parameter is always sent (§1.5) |
| `list_all(*, include_last_used_time=False)` | `listAll(includeLastUsedTime: false)` | `GET /users/db?includeLastUsedTime=…` | 200 | `list<UserDB>` | |
| `get_assigned_roles(*, user_id, include_permissions=False)` | `getAssignedRoles(userId:, includePermissions: false)` | `GET /authz/users/{id}/roles/db?includeFullRoles=…` | 200 | `array<string, RoleBase>`, or `array<string, Role>` when `includePermissions` is true | PHPStan conditional return type on `$includePermissions` |
| `assign_roles(*, user_id, role_names)` | `assignRoles(userId:, roleNames: string\|list<string>)` | `POST /authz/users/{id}/assign` body `{"roles": [...], "userType": "db"}` | 200 | `void` | |
| `revoke_roles(*, user_id, role_names)` | `revokeRoles(userId:, roleNames:)` | `POST /authz/users/{id}/revoke` body `{"roles": [...], "userType": "db"}` | 200 | `void` | See the deviation below |

**Deviation (a Python bug):** `_revoke_roles_from_user` builds `payload` with `userType` and then sends `weaviate_object={"roles": roles}`, so **`userType` is never sent on revoke**. PHP sends `userType`, as `assign` does. **Check against the server** (†) that `/authz/users/{id}/revoke` accepts `userType` (the OpenAPI spec lists it), and report the bug upstream.

```php
final readonly class UserDB {
    public function __construct(
        public string $userId,
        /** @var list<string> */ public array $roleNames,     // wire "roles" (names only)
        public UserTypes $userType,                            // wire "dbUserType": db_user | db_env_user
        public bool $active,
        public ?\DateTimeImmutable $createdAt = null,          // wire "createdAt"
        public ?\DateTimeImmutable $lastUsedTime = null,       // wire "lastUsedAt"; Go zero time 0001-01-01T00:00:00Z → null
        public ?string $apiKeyFirstLetters = null,             // wire "apiKeyFirstLetters"
    ) {}
}
```

Timestamps are parsed as RFC 3339, with `Z` read as UTC. Python has a `UserOIDC` dataclass that no method returns, and PHP doesn't port it.

### 4.3 `$client->users->oidc`

| Python | PHP | REST | Notes |
|---|---|---|---|
| `get_assigned_roles(*, user_id, include_permissions=False)` | `getAssignedRoles(userId:, includePermissions: false)` | `GET /authz/users/{id}/roles/oidc?includeFullRoles=…` | Returns the same shape as db |
| `assign_roles(*, user_id, role_names)` | `assignRoles(userId:, roleNames:)` | `POST /authz/users/{id}/assign` `{"roles", "userType": "oidc"}` | |
| `revoke_roles(*, user_id, role_names)` | `revokeRoles(userId:, roleNames:)` | `POST /authz/users/{id}/revoke` `{"roles", "userType": "oidc"}` | The same deviation as for db |

The user id is URL-encoded, which matters because OIDC subjects often contain `|` or `@`.

### 4.4 Example

```php
$apiKey = $client->users->db->create(userId: 'svc-ingest');       // store it now; it can't be read back
$client->users->db->assignRoles(userId: 'svc-ingest', roleNames: ['writer']);
$u = $client->users->db->get(userId: 'svc-ingest', includeLastUsedTime: true);
$u?->active;                                                       // true
$client->users->db->deactivate(userId: 'svc-ingest', revokeKey: true);
$newKey = $client->users->db->rotateKey(userId: 'svc-ingest');
$client->users->db->activate(userId: 'svc-ingest');

$client->users->oidc->assignRoles(userId: 'auth0|123', roleNames: 'reader');
$me = $client->users->getMyUser();
echo $me->userId, ' ', implode(',', array_keys($me->roles)), ' ', implode(',', $me->groups);
```

---

## 5. OIDC groups (`$client->groups->oidc`, server 1.32)

This namespace is **missing from [02](02-feature-parity-matrix.md)**.

| Python | PHP | REST | Expected | Returns |
|---|---|---|---|---|
| `get_assigned_roles(*, group_id, include_permissions=False)` | `getAssignedRoles(groupId:, includePermissions: false)` | `GET /authz/groups/{id}/roles/oidc?includeFullRoles=…` | 200 | `array<string, RoleBase\|Role>` |
| `assign_roles(*, group_id, role_names)` | `assignRoles(groupId:, roleNames:)` | `POST /authz/groups/{id}/assign` `{"roles", "groupType": "oidc"}` | 200 | `void` |
| `revoke_roles(*, group_id, role_names)` | `revokeRoles(groupId:, roleNames:)` | `POST /authz/groups/{id}/revoke` `{"roles", "groupType": "oidc"}` | 200 | `void` |
| `get_known_group_names()` | `getKnownGroupNames()` | `GET /authz/groups/oidc` | 200 | `list<string>`. A response that isn't a list throws `UnexpectedStatusCodeException` |

The group id is URL-encoded, which matters because groups such as `/admins` contain a `/`. `$client->groups` itself only has the `oidc` property.

---

## 6. Aliases (`$client->alias`, gate 1.32.0 on every method)

| Python | PHP | REST | Expected | Returns | Notes |
|---|---|---|---|---|---|
| `list_all(*, collection=None)` | `listAll(collection: null)` | `GET /aliases[?class={collection}]` | 200 | `array<string, AliasReturn>` keyed by alias | The response is `{"aliases": [{"alias", "class"}]}`. A missing `aliases` key throws `EmptyResponseException`. `collection` is **not** capitalised |
| `get(*, alias_name)` | `get(aliasName:)` | `GET /aliases/{alias}` | 200, 404 | `?AliasReturn` | |
| `create(*, alias_name, target_collection)` | `create(aliasName:, targetCollection:)` | `POST /aliases` `{"class": target, "alias": name}` | 200 | `void` | |
| `update(*, alias_name, new_target_collection)` | `update(aliasName:, newTargetCollection:)` | `PUT /aliases/{alias}` `{"class": target}` | 200, 404 | `bool` (`false` = the alias wasn't found) | |
| `delete(*, alias_name)` | `delete(aliasName:)` | `DELETE /aliases/{alias}` | 204, 404 | `bool` | |
| `exists(*, alias_name)` | `exists(aliasName:)` | `GET /aliases/{alias}` | 200, 404 | `bool` | |

```php
final readonly class AliasReturn { public function __construct(public string $alias, public string $collection) {} } // wire "class" → collection
```

Aliases are also accepted wherever a collection name is (`collections->use('Articles')`). That's handled on the server side, and PHP does nothing special for it. Backups interact with aliases through `restore(overwriteAlias:)`.

```php
$client->alias->create(aliasName: 'Articles', targetCollection: 'Article_v1');
$client->alias->update(aliasName: 'Articles', newTargetCollection: 'Article_v2');   // blue-green switch
$client->alias->listAll(collection: 'Article_v2');   // ['Articles' => AliasReturn(...)]
```

---

## 7. Cluster (`$client->cluster`) and replication (`$client->cluster->replications`)

### 7.1 `nodes()`

| Python param | PHP param | Type | Default | Wire |
|---|---|---|---|---|
| `collection` (positional) | `collection` | `?string` | `null` | Path `/nodes/{Collection}`, **capitalised** |
| `shard` (positional) | `shard` | `?string` | `null` | `?shardName=` |
| `output` (keyword-only) | `output` | `Verbosity\|string\|null` | `null` (the server default, minimal) | `?output=minimal\|verbose` |

`enum Verbosity: string { case Minimal = 'minimal'; case Verbose = 'verbose'; }`. It's also used by `NodesPermission`.

- **REST:** `GET /nodes[/{collection}]`. There's no expected status set, so any 2xx is accepted.
- If `nodes` is missing or `[]`, it throws `EmptyResponseException` ("Nodes status response returned empty").
- **Returns** `list<Node>`. It's parsed as **verbose only when `output` is Verbose**. Otherwise it's parsed as minimal, and that includes `null`.

```php
final readonly class Node {
    public function __construct(
        public string $gitHash,          // wire "gitHash", default "None" (literal string, as Python)
        public string $name,
        public ?array $shards,           // list<Shard>; null for minimal; [] for verbose when missing
        public ?Stats $stats,            // null for minimal; Stats(0, 0) for verbose when missing
        public string $status,           // "HEALTHY", "UNHEALTHY", "UNAVAILABLE", … (string, not enum)
        public string $version,          // default ""
    ) {}
}
final readonly class Shard {
    public string $collection;           // wire "class"
    public string $name;
    public string $node;                 // copied from the parent node's name
    public int $objectCount;
    public string $vectorIndexingStatus; // READONLY | INDEXING | READY | LAZY_LOADING (string; server may add values)
    public int $vectorQueueLength;
    public bool $compressed;
    public ?bool $loaded;
}
final readonly class Stats { public int $objectCount; public int $shardCount; }
```

**Design decision:** Python has `NodeMinimal = Node[None, None]` and `NodeVerbose = Node[Shards, Stats]`. PHP has **one `Node` class**, and a PHPStan conditional return type narrows `shards` and `stats` to non-null when the output is Verbose.

### 7.2 Other cluster methods

| Python | PHP | REST | Expected | Returns | Notes |
|---|---|---|---|---|---|
| `replicate(*, collection, shard, source_node, target_node, replication_type=COPY)` | `replicate(collection:, shard:, sourceNode:, targetNode:, replicationType: ReplicationType::Copy)` | `POST /replication/replicate` `{"collection", "shard", "sourceNode", "targetNode", "type": "COPY"\|"MOVE"}` | 200 | `string` (the operation UUID) | `collection` is **not** capitalised. Server 1.32 |
| `query_sharding_state(*, collection, shard=None)` | `queryShardingState(collection:, shard: null)` | `GET /replication/sharding-state?collection=&shard=` | 200, 404 | `?ShardingState` | 404 returns `null`. Server 1.32 |
| `statistics()` | `statistics()` | `GET /cluster/statistics` | 200 | `ClusterStatistics` | RAFT state. **Missing from 02.** Min server † not verified |

```php
enum ReplicationType: string { case Copy = 'COPY'; case Move = 'MOVE'; }

final readonly class ShardingState { public string $collection; /** @var list<ShardReplicas> */ public array $shards; }  // wire {"shardingState": {...}}
final readonly class ShardReplicas { public string $name; /** @var list<string> */ public array $replicas; }        // wire "shard" → name

final readonly class ClusterStatistics { /** @var list<NodeStatistics> */ public array $statistics; public bool $synchronized; }
final readonly class NodeStatistics {
    public array $candidates;            // array<string,mixed>, default []
    public bool $dbLoaded;               // "dbLoaded", default false
    public int $initialLastAppliedIndex; // default 0
    public bool $isVoter;                // "isVoter"
    public string $leaderAddress;        // "leaderAddress", default ""
    public string $leaderId;             // "leaderId"
    public string $name;
    public bool $isOpen;                 // wire "open"
    public RaftStats $raft;
    public bool $ready;
    public string $status;
}
final readonly class RaftStats {        // all strings (the server reports them as strings), default ""
    public string $appliedIndex, $commitIndex, $fsmPending, $lastContact, $lastLogIndex, $lastLogTerm,
        $lastSnapshotIndex, $lastSnapshotTerm, $latestConfigurationIndex, $numPeers, $protocolVersion,
        $protocolVersionMax, $protocolVersionMin, $snapshotVersionMax, $snapshotVersionMin,
        $state /* Leader|Follower|Candidate|"" */, $term;
    /** @var list<RaftConfigurationMember> */ public array $latestConfiguration;
}
final readonly class RaftConfigurationMember { public string $address; public string $nodeId /* wire "id" */; public int $suffrage; }
```

Every `statistics` field has a default, as Python's `.get(…, default)` does, so a partial response never throws.

### 7.3 `$client->cluster->replications` (server 1.32)

| Python | PHP | REST | Expected | Returns |
|---|---|---|---|---|
| `get(*, uuid, include_history=False)` | `get(uuid:, includeHistory: false)` | `GET /replication/replicate/{uuid}[?includeHistory=true]` (the parameter is only sent when true) | 200, 404 | `?ReplicateOperation` |
| `list_all()` | `listAll()` | `GET /replication/replicate/list?includeHistory=true` | 200 | `list<ReplicateOperation>`, always with history |
| `query(*, collection=None, shard=None, target_node=None, include_history=False)` | `query(collection: null, shard: null, targetNode: null, includeHistory: false)` | `GET /replication/replicate/list?collection=&shard=&targetNode=&includeHistory=` (only the truthy parameters are sent) | 200 | `list<ReplicateOperation>` |
| `cancel(*, uuid)` | `cancel(uuid:)` | `POST /replication/replicate/{uuid}/cancel` body `{}` | 204 | `void` |
| `delete(*, uuid)` | `delete(uuid:)` | `DELETE /replication/replicate/{uuid}` | 204 | `void` |
| `delete_all()` | `deleteAll()` | `DELETE /replication/replicate` | 204 | `void` |

`query` is **missing from 02**.

```php
enum ReplicateOperationState: string {
    case Registered = 'REGISTERED'; case Hydrating = 'HYDRATING'; case Finalizing = 'FINALIZING';
    case Dehydrating = 'DEHYDRATING'; case Integrating = 'INTEGRATING'; case Ready = 'READY'; case Cancelled = 'CANCELLED';
}
final readonly class ReplicateOperationStatus { public ReplicateOperationState $state; /** @var list<string> */ public array $errors; } // errors null → []
final readonly class ReplicateOperation {
    public string $collection; public string $shard; public string $sourceNode; public string $targetNode;
    public ReplicateOperationStatus $status;
    /** @var list<ReplicateOperationStatus>|null */ public ?array $statusHistory;   // null unless history requested AND present
    public ReplicationType $transferType;   // wire "type"
    public string $uuid;                    // wire "id"
}
```

Note that the spelling differs: `BackupStatus::Canceled` versus `ReplicateOperationState::Cancelled`. Both follow the server. `uuid:` takes `string|UuidInterface`, like everywhere else.

### 7.4 Example

```php
use Weaviate\Client\Cluster\{Verbosity, ReplicationType};

foreach ($client->cluster->nodes('Article', output: Verbosity::Verbose) as $node) {
    foreach ($node->shards as $s) { echo "{$node->name} {$s->name} {$s->objectCount} {$s->vectorIndexingStatus}\n"; }
}
$state = $client->cluster->queryShardingState(collection: 'Article');
$opId = $client->cluster->replicate(collection: 'Article', shard: $state->shards[0]->name,
    sourceNode: 'node1', targetNode: 'node2', replicationType: ReplicationType::Move);
do { usleep(500_000); $op = $client->cluster->replications->get(uuid: $opId); }
while ($op !== null && !in_array($op->status->state, [ReplicateOperationState::Ready, ReplicateOperationState::Cancelled], true));
$client->cluster->statistics()->synchronized;
```

Python has no `wait_for_completion` for replication, so PHP doesn't add one either. The loop above is only an example in the docs.

---

## 8. Export (`$client->export`, gate 1.37.0)

This is a **client-level** namespace. [02 §2](02-feature-parity-matrix.md#2-collections-management) lists it as "`$col->export`", which is wrong. It exports collections to object storage as Parquet.

| Python | PHP | REST | Expected | Returns |
|---|---|---|---|---|
| `create(*, export_id, backend, file_format, include_collections=None, exclude_collections=None, wait_for_completion=False)` | `create(exportId:, backend:, fileFormat: ExportFileFormat::Parquet, includeCollections: null, excludeCollections: null, waitForCompletion: false, waitTimeout: null, pollInterval: 1.0)` | `POST /export/{backend}` `{"id", "file_format", "include"?, "exclude"?}` | any 2xx | `ExportCreateReturn`, or `ExportStatusReturn` when it waits |
| `get_status(*, export_id, backend)` | `getStatus(exportId:, backend:)` | `GET /export/{backend}/{id}` | any 2xx | `ExportStatusReturn` |
| `cancel(*, export_id, backend)` | `cancel(exportId:, backend:)` | `DELETE /export/{backend}/{id}` | 204, 409 | `bool` (`false` on 409 = already finished) |

- In Python `file_format` is **required**, and it's the only value there is. PHP gives it the default `Parquet`, because there's only one value. Mark this as a PHP convenience.
- `backend` takes `BackupStorage|string`. PHP `ExportStorage` is a class alias of `BackupStorage` (`class_alias`), as in Python.
- `exportId` is lower-cased and the collection names are capitalised. Unlike backups, **empty `include` or `exclude` lists are left out** of the body. Setting both throws `InvalidInputException` (Python raises `ValueError` here and `TypeError` for backups).
- **The wait loop is the §2.6 helper.** On success it returns the **status** response, not the create response. That's the opposite of backups, and it's what Python does. The statuses are `ExportStatus` (`STARTED`, `TRANSFERRING`, `SUCCESS`, `FAILED`, `CANCELED`), and the failures throw `ExportFailedException` or `ExportCanceledException` (new, both extending `ExportException`).

```php
final readonly class ExportCreateReturn { public string $exportId /* "id" */; public string $backend; public string $path;
    public ExportStatus $status; public ?\DateTimeImmutable $startedAt; /** @var list<string> */ public array $collections /* "classes" */; }
final readonly class ExportStatusReturn /* has all ExportCreateReturn fields + */ { public ?\DateTimeImmutable $completedAt;
    /** @var array<string, array<string, ShardProgress>>|null  collection → shard → progress */ public ?array $shardStatus;
    public ?string $error; public ?int $tookInMs; }
final readonly class ShardProgress { public ShardExportStatus $status /* TRANSFERRING|SUCCESS|FAILED|SKIPPED */;
    public int $objectsExported /* default 0 */; public ?string $error; public ?string $skipReason; }
```

---

## 9. Debug (`$client->debug`, marked `@experimental`)

The Python docstring says: "deemed experimental and is subject to change … no guarantees about the stability of this namespace". PHP marks the class `@experimental` and **leaves it out of the SemVer promise**.

| Python | PHP | REST | Expected | Returns |
|---|---|---|---|---|
| `get_object_over_rest(collection, uuid, *, consistency_level=None, node_name=None, tenant=None)` | `getObjectOverRest(string $collection, string\|UuidInterface $uuid, consistencyLevel: null, nodeName: null, tenant: null)` | `GET /objects/{collection}/{uuid}?consistency=&node_name=&tenant=` | 200, 404 | `?DebugRestObject` |

- The name is **`get_object_over_rest`**.
- `collection` is **not** capitalised here.
- `node_name` is snake_case on the wire. It lets you read the object from one specific replica, which is useful for checking consistency.

```php
final readonly class DebugRestObject {
    public string $collection;                      // "class"
    public \DateTimeImmutable $creationTime;        // "creationTimeUnix" (ms since epoch)
    public \DateTimeImmutable $lastUpdateTime;      // "lastUpdateTimeUnix" (ms)
    public array $properties;                       // array<string,mixed>, raw REST JSON (no type coercion)
    public ?string $tenant;
    public string $uuid;                            // "id"
    /** @var list<float>|null */ public ?array $vector;
    /** @var array<string, list<float>>|null */ public ?array $vectors;
}
```

Python converts the Unix milliseconds with pydantic's int-to-datetime rule, which treats values above about 2e10 as milliseconds. PHP always divides by 1000 and uses UTC.

---

## 10. Tokenization (`$client->tokenization`, gate 1.37.0)

This namespace is **missing from 02**. It lets you see how text is tokenized without writing any data.

| Python | PHP | REST | Expected | Returns |
|---|---|---|---|---|
| `text(text, tokenization, *, analyzer_config=None, stopwords=None, stopword_presets=None)` | `text(string $text, Tokenization $tokenization, analyzerConfig: null, stopwords: null, stopwordPresets: null)` | `POST /tokenize` `{"text", "tokenization", "analyzerConfig"?, "stopwords"?, "stopwordPresets"?}` | 200 | `TokenizeResult` |
| `for_property(collection, property_name, text)` | `forProperty(string $collection, string $propertyName, string $text)` | `POST /schema/{Collection}/properties/{prop}/tokenize` `{"text"}` | 200 | `TokenizeResult` |

- `analyzerConfig` takes a `?TextAnalyzerConfig`, the same class as `Configure::textAnalyzer(asciiFold:, asciiFoldIgnore:, stopwordPreset:)` ([P1 config](02-feature-parity-matrix.md#2-collections-management)). It's left out when it's empty.
- `stopwords` takes the `StopwordsConfig` write shape (`preset`, `additions`, `removals`), **or** the read-side object that `config->get()` returns, which is converted into the write shape.
- `stopwordPresets` is `array<string, list<string>>`. Every value must be a list of strings: a string value, or a list with anything other than strings in it, throws `InvalidInputException`.
- **`stopwords` and `stopwordPresets` can't both be set.** Passing both throws `InvalidInputException`.
- `final readonly class TokenizeResult { /** @var list<string> */ public array $indexed; /** @var list<string> */ public array $query; }`

For `word` tokenization, the server applies the `en` stopwords when nothing is configured. Document that, using the wording from the Python docstring.

---

## 11. Misc `WeaviateClient` methods

This complements [09 §7](09-connection.md#7-connect-sequence-and-skipinitchecks).

| Python | PHP | REST | Returns | Behaviour |
|---|---|---|---|---|
| `graphql_raw_query(gql_query)` | `graphqlRawQuery(string $gqlQuery): RawGqlReturn` | `POST /graphql` `{"query": …}`, **`query` timeout** | `RawGqlReturn` | Expects 200. `data.Aggregate`, `data.Explore` and `data.Get` go to `aggregate`, `explore` and `get` (`[]` when missing), and `errors` is passed through. **GraphQL `errors` don't throw**: the caller has to check `$r->errors`. The docs must warn about injection when building query strings |
| `get_meta()` | `getMeta(): Meta` | `GET /meta` | `Meta` | Python returns a raw `dict`. PHP returns `Meta(hostname, version, modules: array, grpcMaxMessageSize: ?int, raw: array)`, and `raw` keeps any fields not listed here. This decision was made in 03 |
| `get_open_id_configuration()` | `getOpenIdConfiguration(): ?array` | `GET /.well-known/openid-configuration` | `?array` | 200 returns the array, 404 returns `null`, and any other status throws `UnexpectedStatusCodeException` |
| `is_ready()` | `isReady(): bool` | `GET /.well-known/ready` | `bool` | `true` only when the status is 200. **Every exception is swallowed and returns `false`** (Python's `exc` prints it). PHP logs it at `debug` level |
| `is_live()` | `isLive(): bool` | `GET /.well-known/live` **and then a gRPC health check** | `bool` | Python's `is_live` also pings gRPC (`/grpc.health.v1.Health/Check`, `init` timeout) after an HTTP 200, and returns `true` only when both pass. Exceptions return `false` |
| `is_connected()` | `isConnected(): bool` | — | `bool` | Local state only |
| `connect()` / `close()` | same | | | See 09 |

```php
final readonly class RawGqlReturn {
    public function __construct(
        public array $aggregate,   // data.Aggregate
        public array $explore,     // data.Explore
        public array $get,         // data.Get
        public ?array $errors,     // GraphQL errors, if any (not thrown)
    ) {}
}
```

---

## 12. Other top-level Python features, and what PHP does with them

| Python | Status in PHP | Notes |
|---|---|---|
| `weaviate.agents` (the optional `weaviate-agents` package, re-exported when it's installed) | Out of scope (02 §13, already there) | A separate package that talks to Weaviate Cloud services |
| `client.export` | This spec, §8 | 02 has it on the wrong object |
| `client.tokenization` | This spec, §10 | Missing from 02 |
| `client.groups` | This spec, §5 | Missing from 02 |
| `client.cluster.statistics` | This spec, §7.2 | Missing from 02 |
| `Permissions.mcp` / `Actions.MCP` | This spec, §3.1 | Missing from 02 (server 1.37). The MCP server itself is server-side only, with no client API |
| `weaviate.Client` (the v3 stub that raises) | Not ported | |
| `connect_to_wcs` | Not ported | 09 §2.5 |
| `weaviate.outputs.*` / `weaviate.classes.*` re-export modules | PHP namespaces instead | `Weaviate\Client\Backup\Result\*` and so on |
| `grpc-web` / Pyodide shim (`weaviate_client_web`) | Covered in 09 §6 | |

---

## 13. Exceptions added by this spec

These should be merged into [01 §Errors](01-architecture.md#errors):

```
WeaviateException
├── UnexpectedStatusCodeException
│   ├── ResponseCannotBeDecodedException   2xx with bad JSON
│   └── EmptyResponseException             2xx with a missing required body or field
├── BackupException
│   ├── BackupFailedException              ->result, ->serverError
│   ├── BackupCanceledException            ->result, ->serverError       (Python BackupCanceledError)
│   └── BackupTimeoutException             ->result, ->waitTimeout       (PHP only)
└── ExportException
    ├── ExportFailedException
    ├── ExportCanceledException
    └── ExportTimeoutException              (PHP only)
```

`ForbiddenException` (403) is thrown by every method in this spec. Tests have to cover that path.

## 14. Test checklist (it becomes the P4 unit and integration tests)

**Unit tests** (a mocked PSR-18 client; every wire body is asserted byte for byte):
- Backups:
  - The create body has `include: []`, `exclude: []`, `incremental_base_backup_id: null`, and `config` only when it isn't empty.
  - `chunkSize` is never sent, and it logs a deprecation.
  - The location is merged into `config` for create and restore, and goes into the query string for status and cancel.
  - Restore sends `rolesOptions` and `usersOptions` inside `config`, and always sends `overwriteAlias`.
  - Ids are lower-cased, collection names are capitalised, include plus exclude throws, and a bad backend string throws.
  - `listBackups` sends `order=asc` only for `true`.
  - Cancel returns `true` on 204 and `false` on 404.
  - Status calls overwrite `backupId`.
  - The collection wrapper forces the include list and returns a `BackupStatusReturn` with `size = 0`.
- The wait loop, with a fake clock and a fake sleeper that are injected:
  - SUCCESS after N polls.
  - FAILED and CANCELED throw the right exception, carrying `result` and `serverError`.
  - A timeout throws `BackupTimeoutException` and **doesn't** send a DELETE.
  - `waitTimeout: 0` makes exactly one poll.
  - The first poll has no sleep before it.
  - An invalid `pollInterval` or `waitTimeout` throws before any I/O.
  - The `max_execution_time` notice is logged.
- Permissions:
  - Every builder with every flag, and with no flags (returns `[]`).
  - The cartesian products (alias × collection, collection × tenant, collection × shard).
  - The `'*'` defaults.
  - The capitalisation rules, including alias capitalised and alias collection not.
  - `scope` is only sent when it's set.
  - `nodes()->minimal` gives `collection = '*'`.
  - MCP and cluster have no resource key.
  - Nested lists are flattened one level.
- Role parsing:
  - A golden JSON with every scope.
  - Joining several actions on the same resource into one permission, with order kept.
  - The missing-tenant, shard and collection defaults.
  - An unknown action is logged and skipped.
  - A permission with a missing resource object is skipped.
  - The `manage_*` values are parsed.
- `hasPermissions`:
  - One request per action.
  - Sync short-circuits on the first 404.
  - A 404 means `false`.
- Users:
  - `getMyUser` with `username` (1.29) and with `user_id`, and with `roles: null`.
  - The Go zero time becomes `null`.
  - `includeLastUsedTime` and `includeFullRoles` are sent as `"true"`/`"false"` (**regression test for `http_build_query`**).
  - Assign and revoke bodies contain `userType`.
  - Activate and deactivate return `false` on 409.
  - `deactivate` sends `revoke_key`.
  - URL-encoding of `auth0|x`, `a/b` and `me@x`.
- Aliases: every method throws `UnsupportedFeatureException` on 1.31 **before** any I/O; `listAll` sends `?class=`; `update` and `delete` return `false` on 404.
- Cluster:
  - `nodes` path capitalisation and `shardName`.
  - Minimal versus verbose parsing, including the `gitHash` default `"None"`.
  - An empty `nodes` array throws.
  - `statistics` defaults on a partial JSON.
  - `queryShardingState` returns `null` on 404.
  - Replication `get` sends `includeHistory` only when it's true, and `statusHistory` is `null` otherwise.
  - `listAll` always has history.
  - The status codes for cancel, delete and deleteAll.
- Export and tokenization:
  - The 1.37 gate.
  - Export leaves out empty include and exclude.
  - Export waiting returns the status object.
  - Cancel returns `false` on 409.
  - `stopwords` together with `stopwordPresets` throws.
  - Bad `stopwordPresets` values throw.
- Misc:
  - `graphqlRawQuery` uses the query timeout and returns `errors` without throwing.
  - `isReady` and `isLive` return `false` on a connection error.
  - `isLive` returns `false` when HTTP is 200 but the gRPC health check fails.
  - `getOpenIdConfiguration` returns `null` on 404.
- Every method: a 403 throws `ForbiddenException`, and a status outside the expected set throws `UnexpectedStatusCodeException` with the body attached.

**Integration tests** (a compose matrix: the floor, 1.30, 1.32, 1.36, 1.37 and latest):
- Filesystem backups: create, wait, restore, and the status calls. Use MinIO for S3 with a dynamic `bucket` and `path`. The GCS emulator (fake-gcs-server) and Azurite for Azure are **optional nightly** jobs.
- Backups: cancel create; cancel restore (1.36+); `listBackups` with sorting (1.33.2+); incremental (1.37+); restore with roles and users (1.30.10+); `overwriteAlias` (1.32+); the collection-scoped round trip.
- An RBAC-enabled compose (`AUTHORIZATION_RBAC_ENABLED`, a root API key and db users). Cover:
  - The full role lifecycle for every scope, with the version-gated scopes skipped below their minimum, as in `integration/test_rbac.py`.
  - `hasPermissions` round-tripping the output objects.
  - `removePermissions` of the last permission deleting the role.
  - The db user lifecycle: create, then log in with the returned key, then rotate and check the old key fails, then deactivate with `revokeKey`.
  - `getMyUser` as a non-root user.
- A Keycloak compose for the OIDC users and groups: assigning, revoking and listing roles for users and groups, `getKnownGroupNames`, and `getGroupAssignments`.
- Aliases: the full CRUD, and a query through an alias.
- A three-node cluster: verbose `nodes`, `statistics` with one leader, `replicate` COPY and MOVE, the `replications` queries, cancel, delete and deleteAll, and `queryShardingState` before and after.
- Debug: `getObjectOverRest` with `nodeName` on the three-node cluster.
- Export (1.37+) to MinIO: wait for it, then check that `shardStatus` is filled in.
- Tokenization (1.37+): parity cases ported from `integration/test_tokenize.py`.

## 15. Not verified, and open questions

- **The server minimums** for `cluster.statistics`, tenant-scoped data and tenants permissions (1.30?), and the user permission actions other than read and assign (1.30?) were not verified. The 1.32 minimum for `overwriteAlias` also conflicts with the test's own skip message ("1.33.0"). **Confirm all of these against the server changelog.**
- **The range for `cpuPercentage`.** The server's accepted range (1–80?) wasn't checked, and Python doesn't validate it.
- **Revoke `userType`.** It needs checking that `/authz/users/{id}/revoke` accepts `userType` (§4.2). Python never sends it.
- **Cancelling a backup on 404.** PHP returns `false` and Python raises (§2.4). Agree on this with the Python team.
- **Empty roles.** It needs checking whether the server accepts `roles->create()` with an empty permissions list (§3.1).
- **`vectorIndexingStatus` and `Node::status`** are kept as strings, because the full set of server values wasn't checked. Consider making them enums once that's done.
- **The `BackupStatus` statuses** were taken from the Python enum. The server may also have `CANCELLING` handling that's specific to restore. Tests will show.
- **Async signatures** follow the async package design in [06](06-integrations.md). Nothing here is specific to async except the wait loop (§2.6) and the `hasPermissions` fan-out (§3.4).
