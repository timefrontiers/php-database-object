# Changelog

## 1.1.0 - 2026-08-07

### Compatibility-impacting corrections

- Changed `QueryBuilder::get()` and forwarding `findAll()` to return
  `array|false`, preserving an empty array for a successful no-match result.
- Changed `QueryBuilder::count()` and forwarding `countAll()` to return
  `int|false`, preserving integer zero for a successful no-match result.
- Restricted fluent select input to plain columns or `*`, allowlisted query
  operators, and validated non-negative pagination.
- Changed quoted `findBySql()` placeholders to expand to safely quoted model
  identifiers.
- Made `_upgradeConn()` fail clearly without Linktude bootstrap helpers and
  reject guest-connection replacement during an active transaction.

### Fixes

- Treat SQL Database 1.1 false write results as failures before reading insert
  IDs or affected-row counts.
- Import only newly appended connection errors into model and query-builder
  `HasErrors` contexts while preserving five-element tuples.
- Retain the exact read facade on hydrated records.
- Resolve lazy schema fields before `findById()` checks the `code` column.
- Keep count, existence, and first-result queries from mutating reusable builder
  state.
- Treat schema query failures and incomplete metadata as exceptions rather than
  empty schemas or default primary keys.
- Isolate schema cache entries by wrapped connection identity and prevent
  primary-key overrides from poisoning shared metadata.

### Security and transaction hardening

- Centralized identifier validation and quoting for generated SQL.
- Defined safe empty `IN` and `NOT IN` behavior without generating `IN ()`.
- Prevented implicit connection privilege replacement from splitting an active
  transaction.
- Added MySQLi and PDO-MySQL contracts covering caller-owned transactions,
  rollback-only failures, observer visibility, nested scopes, and facade
  identity.
- Added safe fallback diagnostics that omit bound values and credentials.

