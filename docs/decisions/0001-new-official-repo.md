# ADR 0001: Build a new official repository instead of forking

- **Status:** Accepted
- **Date:** 2026-09-25

## Context

`timkley/weaviate-php` (0.12, MIT, one maintainer) is the only PHP client today ([00](../00-context.md)). It covers REST schema, objects, batch and meta, and runs search as raw GraphQL. It depends on Laravel HTTP.

To reach Python v4 parity we need:
- gRPC transports,
- a collection-centric API,
- typed builders for config, filters and search,
- about ten new API areas.

Almost none of the existing code would survive: the transport, the API surface, the models and the tests would all be replaced.

## Decision

Create a new package from scratch: `dudanogueira/weaviate-php-client`, at `github.com/dudanogueira/weaviate-php-client`. It's intended to move to the `weaviate` GitHub organisation as the official client ([ADR 0005](0005-namespace-and-package-name.md)). We'll publish a migration guide ([07](../07-migration-from-timkley.md)) and reach out to the existing maintainer.

## Consequences

- We get a clean design that follows Python v4 and has no framework dependency. We also own it: releases, security fixes and the support SLA.
- Existing users can't upgrade in place and need to migrate. The guide and a call-mapping table reduce the effort.
- There may be a namespace conflict with the community package ([ADR 0005](0005-namespace-and-package-name.md)).
- A fork would inherit the Laravel dependency and the GraphQL-centric design. Contributing upstream would put a mostly new codebase under a single outside maintainer with no official support. We rejected both.
