# Security policy

## Reporting a vulnerability

Please **don't open a public issue** for security problems. Report them privately through GitHub's
[private vulnerability reporting](https://github.com/dudanogueira/weaviate-php-client/security/advisories/new).

Include the affected version or commit, a description, and steps to reproduce. You'll get an acknowledgement
within a few working days.

## Supported versions

The client is pre-1.0. Fixes land on `main` and in the next pre-release.

## Security properties

- TLS certificate verification is always on; there's no option to turn it off.
- The built-in HTTP client never follows redirects, doesn't decompress responses, and caps response size
  (`AdditionalConfig::maxResponseBytes`).
- Credentials are redacted from `var_dump`/`print_r`, kept out of object properties, marked
  `#[\SensitiveParameter]`, and never included in exception messages or chained exceptions.
- Header names and values are validated (no CR/LF/NUL), and API paths are built from encoded segments.

Reviews: [docs/security/](docs/security/2026-09-25-review.md).
