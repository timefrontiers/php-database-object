# Changelog

## 1.1.1 - 2026-08-13

### Requirements

- **Raised the minimum PHP version from 8.4 to 8.5.** Consumers still running
  PHP 8.4 cannot install this release and must stay on 1.1.0. This narrowing is
  released as a patch by project decision; treat it as a required-action item
  rather than a routine patch upgrade.

### Fixed

- **`_create()` no longer raises a `TypeError` under PDO-MySQL.** PDO reports
  `lastInsertId()` as a string while MySQLi reports an integer, so assigning the
  insert ID to a typed `int`/`?int` primary key failed on every PDO-backed
  insert. The value is now normalised to an integer when it is integral and
  within PHP's integer range, and left as a string beyond that range so a wide
  `BIGINT` key is never truncated. Models with a typed integer primary key were
  effectively unable to insert through a PDO facade in 1.1.0.
- `_create()` now resolves every schema-dependent decision, including whether
  the primary key takes the insert ID, before dispatching the insert. Schema
  discovery raised after a committed insert could previously be reported as a
  write failure and invite a duplicating retry.

### Removed

- The unused `DatabaseObject::$_schema` and `$_schema_connection` static
  properties. Schema metadata and its connection-identity isolation have always
  been owned by `TableSchema`; these model-level fields were never read. A
  subclass that assigned or cleared `$_schema` was not affecting any cache.

### Tests

- Ran the MySQLi and PDO-MySQL integration suites for the first time, against a
  disposable MariaDB 10.11.18 instance created for the run and destroyed
  afterwards: **44 tests, 163 assertions, no skips**. The PDO insert-ID defect
  above was found by that run.
- Added regression tests for string and beyond-int-range insert IDs, so the
  driver difference is locked in without needing a database.
- Added a regression test proving schema discovery fails before any insert is
  dispatched.
- Added a test proving the fallback diagnostic carries only the driver code and
  SQLSTATE, with no SQL text, table name, bound value, or connection user.

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

