# ADR 0005: Package name and root namespace

- **Status:** Accepted: option C, a `Weaviate\Client\` root
- **Decided:** 2026-09-25

## Context

- The repository is **`github.com/dudanogueira/weaviate-php-client`**, a public personal repo for now. Its name follows `weaviate-python-client`.
- Only the Weaviate organisation can publish under the `weaviate/` vendor on Packagist. Until the repo is transferred, the Composer package uses the maintainer's own vendor.
- The community package `timkley/weaviate-php` uses the root namespace **`Weaviate\`**, and its entry class is `Weaviate\Weaviate`.
- If we also use `Weaviate\` with a `Weaviate\Weaviate` entry class, **installing both packages in the same app fails**: the class names clash, and whichever autoloader loads first wins.

## Options

| Option | Pros | Cons |
|---|---|---|
| **A. `Weaviate\`, entry class `Weaviate\Weaviate`** | Cleanest and matches the brand | Hard conflict with timkley, so no side-by-side migration |
| **B. `Weaviate\`, entry class `Weaviate\Client\…`** (all our classes under sub-namespaces the community package doesn't use, e.g. `Weaviate\Client\Weaviate`, `Weaviate\Client\Collections\…`) | Branded and doesn't conflict | Longer `use` statements |
| **C. `Weaviate\Client\` root (PSR-4)** | The same as B in practice; one PSR-4 root | Same as B |
| **D. `Weaviate\` and coordinate with timkley** so they rename their namespace in a new major version | Clean for us | Depends on an outside maintainer and breaks their users |

## Decision

We chose **option C**: the PSR-4 root is **`Weaviate\Client\`**.

**Package name:** `dudanogueira/weaviate-php-client` (decided 2026-09-25). If the repo moves to the `weaviate` GitHub org, the package is renamed `weaviate/weaviate-php-client`, and the old name is marked `abandoned` on Packagist with the new name as its replacement. **The namespace doesn't change**, so user code only needs a `composer require` update.

```json
"autoload": { "psr-4": { "Weaviate\\Client\\": "src/" } }
```

- The entry points are `Weaviate\Client\Weaviate::connectToLocal()` / `connectToWeaviateCloud()` / `connectToCustom()` and `Weaviate\Client\WeaviateClient`.
- Sub-namespaces follow the `src/` layout in [01](../01-architecture.md): `Weaviate\Client\Connect`, `…\Collections`, `…\Config`, `…\Query`, `…\Result`, `…\Exceptions`, `…\Backup`, `…\Rbac`, `…\Users`, `…\Alias`, `…\Cluster`, `…\Transport` (@internal), and `…\Proto\V1` (generated, @internal).
- The companion packages use sibling roots, which keeps them clear of the community package too:
  - `Weaviate\Laravel\` (`weaviate/weaviate-laravel`)
  - `Weaviate\Symfony\` (`weaviate/weaviate-symfony`)
  - `Weaviate\Async\` (`weaviate/weaviate-php-async`)

## Consequences

- The package can be installed next to `timkley/weaviate-php`, whose classes are directly under `Weaviate\`, so users can migrate gradually ([07](../07-migration-from-timkley.md)).
- `use` statements are slightly longer. IDEs add them automatically, so the cost is small.
- We must never declare a class directly under `Weaviate\` (for example `Weaviate\Weaviate`), because it could clash. A PHPStan rule or architecture test (for example `phpat`) enforces this in CI.
- Every example and docs snippet uses the `Weaviate\Client\` form.
