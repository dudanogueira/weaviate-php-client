# Contributing

## Setup

You only need Docker. PHP, Composer and ext-grpc run in containers through `bin/php`.

```bash
docker compose up -d weaviate          # Weaviate 1.39.7 on :8080 (REST) and :50051 (gRPC)
bin/php composer install
bin/php vendor/bin/phpunit             # unit + integration
bin/php vendor/bin/phpstan analyse --memory-limit=1G
bin/php vendor/bin/php-cs-fixer fix
```

Other environments:

```bash
PHP_VERSION=8.4 DEBIAN=trixie bin/php vendor/bin/phpunit   # newer libcurl (HTTP/2 connection reuse)
GRPC=1 bin/php vendor/bin/phpunit                          # with ext-grpc (slow first image build)
WEAVIATE_VERSION=1.29.11 docker compose up -d weaviate     # the oldest supported server
```

## Protos

The `.proto` files are vendored at a pinned Weaviate tag, and the generated PHP code in `src/Proto` is committed.

```bash
bin/sync-protos v1.39.7
bin/generate-protos        # needs protoc 35.0 on the host, or Docker
```

CI regenerates the code and fails when `src/Proto` differs.

## Where things are

- Plans, specs and decision records: [`docs/`](docs/README.md). The parity matrix, [`docs/02-feature-parity-matrix.md`](docs/02-feature-parity-matrix.md), tracks progress.
- Every class lives under `Weaviate\Client\` ([ADR 0005](docs/decisions/0005-namespace-and-package-name.md)). A unit test enforces this.
- `Transport\*` and `Proto\*` are `@internal`.

## Pull requests

- Add a unit test, an integration test for anything that talks to Weaviate, and update the parity-matrix row.
- Gate features newer than the 1.29 floor with a server-version check ([ADR 0004](docs/decisions/0004-server-version-floor.md)).
