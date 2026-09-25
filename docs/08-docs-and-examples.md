# 08: Docs & examples

## Repo documentation

- **README:** install, the curl HTTP/2 requirement (and ext-grpc as an option), a 30-line quickstart, a link to docs.weaviate.io, the transport notes, and supported PHP and server versions.
- **`UPGRADING.md`:** breaking changes between pre-releases, and after 1.0 between major versions.
- **API reference:** generated with phpDocumentor from docblocks and published to GitHub Pages. `@internal` namespaces are left out.
- **Docblock rule:** each public method links to the matching docs.weaviate.io page and names the minimum server version when it's version-gated.

## `examples/` (runnable, tested in CI)

```
examples/
  01-connect-local.php
  02-connect-cloud.php
  03-create-collection.php
  04-insert-and-insert-many.php
  05-query-near-text.php
  06-hybrid-and-bm25.php
  07-filters.php
  08-generate-rag.php
  09-aggregate.php
  10-batch-import.php
  11-multi-tenancy.php
  12-named-vectors-and-multi-target.php
  13-rbac.php
  14-backup.php
  laravel/   symfony/   async/
```

Each example reads its connection settings from env vars and runs against the CI docker-compose setup (`tests/Examples` runs them all).

## docs.weaviate.io PHP tabs

The docs site keeps snippets as tested source files per language, which the Python, TS, Go and Java tabs pull in. Before writing PHP snippets, confirm the current mechanism and directory layout with the docs team.

Plan:
1. Add a `php` language to the docs site's code-tab component and snippet loader.
2. Keep the PHP snippets as real `.php` files with start/end markers in the docs repo, following the same convention as the other languages, so CI can run them.
3. Write them in priority order:
   1. Quickstart (local and cloud)
   2. Connect (all auth modes)
   3. Manage collections (create, vectorizer config, named vectors, multi-tenancy config)
   4. Manage objects (create, batch import, update, delete)
   5. Search (basic, similarity, keyword, hybrid, filters, generative, aggregate, rerank, multi-target)
   6. Multi-tenancy operations
   7. RBAC, backups, aliases, replication
4. Add a "PHP" entry to the client libraries index page, and a PHP client page for install, transports and framework integrations.

Target: sections 1–5 by the 1.0 release and the rest by 1.1.

## Launch communications (at 1.0)

- A blog post: "Official PHP client for Weaviate". It should cover gRPC without extensions, Laravel and Symfony support, and the migration from the community client.
- A Weaviate forum and Slack announcement, plus posts on Laravel News and Symfony community channels.
- A Packagist description and keywords (`weaviate`, `vector-database`, `grpc`, `rag`, `laravel`, `symfony`).
