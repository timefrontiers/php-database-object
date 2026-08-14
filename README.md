# TimeFrontiers PHP Database Object

Active Record infrastructure for SQL Database 1.1, with schema-aware writes,
prepared fluent queries, explicit connection injection, and ecosystem-standard
`HasErrors` diagnostics.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.5-8892BF.svg)](https://php.net/)
[![Release](https://img.shields.io/badge/release-1.1.1-blue.svg)](CHANGELOG.md)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## Requirements

- PHP 8.5+
- `timefrontiers/php-core` 1.x
- `timefrontiers/php-has-errors` 1.x
- `timefrontiers/php-sql-database` 1.1.x

Install the package with Composer:

```bash
composer require timefrontiers/php-database-object:^1.1
```

## Model definition

`DatabaseObject` is a trait and already includes `HasErrors`:

```php
use TimeFrontiers\Helper\DatabaseObject;

final class UserRecord
{
  use DatabaseObject;

  protected static string $_db_name = 'application';
  protected static string $_table_name = 'users';
  protected static string $_primary_key = 'id';
  protected static array $_db_fields = []; // Discovered when first needed.

  public ?int $id = null;
  public string $code = '';
  public string $name = '';
}
```

Models resolve connections in this compatibility order:

1. The connection supplied through `setConnection()`.
2. The class connection supplied through `useConnection()`.
3. Linktude's global `$database` facade.

Explicit injection is preferred, especially inside transactions:

```php
UserRecord::useConnection($database);

$record = new UserRecord();
$record->setConnection($database);
$record->code = 'USER-1042';
$record->name = 'Example';

if (!$record->save()) {
  $errors = $record->getErrors();
}
```

Hydrated records retain the exact facade that performed the read. A later
`save()` or `delete()` therefore stays on the caller's transaction-bound
connection even if static or global state changes.

## Writes and errors

`save()` inserts when the primary key is empty and updates when it is populated.
A preassigned-key insert must use explicit repository SQL because a populated
key always routes to update.

SQL Database 1.1 operational failures return `false`. Database Object checks
that result and imports only the connection errors appended by the current
operation into `_create`, `_update`, or `_delete`.

```php
if (!$record->save()) {
  $message = $record->firstError('_create');
  $all = $record->getErrors();
}
```

The behavior is deliberate:

- Create succeeds only after a successful insert statement.
- An ordinary successful update may return true with zero affected rows.
- Delete succeeds only after a successful statement affecting exactly one row.
- Model errors preserve the five-element tuple `[rank, code, message, file, line]`.

Exact-one-row updates, optimistic versions, row locks, immutable inserts, and
multi-row state transitions belong in repository SQL, not Active Record
`save()`.

## Fluent queries

```php
$query = UserRecord::query()
  ->select('id', 'code', 'name')
  ->where('status', 'active')
  ->where('created_at', '>=', '2026-01-01')
  ->whereNotIn('role', ['blocked'])
  ->orderBy('name')
  ->limit(25);

$users = $query->get();
if ($users === false) {
  $errors = $query->getErrors();
}
```

The builder binds values and accepts plain identifiers only. Supported general
operators are `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE`, and
`<=>`. Use `whereNull()`, `whereNotNull()`, `whereIn()`, and `whereNotIn()` for
their dedicated cases.

Empty `IN` matches nothing; empty `NOT IN` matches everything. Negative limits
and offsets, unsupported operators, raw select expressions, and invalid
identifiers throw `InvalidArgumentException` before execution.

Result contracts are:

| Method | Success with no match | Database failure |
|---|---:|---:|
| `get()` | `[]` | `false` |
| `first()` | `false` | `false` with builder errors |
| `count()` | `0` | `false` |
| `exists()` | `false` | `false` with builder errors |

Use strict comparisons: `[] !== false` and `0 !== false`. `count()`, `exists()`,
and `first()` do not mutate the builder's select or pagination state.

## Reviewed custom SQL

Expression-heavy or otherwise custom developer-authored SQL remains available
through `findBySql()`:

```php
$records = UserRecord::findBySql(
  'SELECT COUNT(*) AS total, status FROM :db:.:table: ' .
  'WHERE created_at >= ? GROUP BY status',
  ['2026-01-01']
);
```

The `:database:`/`:db:`, `:table:`/`:tbl:`, and
`:primary_key:`/`:pkey:` placeholders are replaced with quoted model
identifiers. Values still belong in prepared parameters.

## Caller-owned transactions

Database Object never begins, commits, rolls back, closes, changes, or upgrades
a connection during persistence, hydration, or schema discovery.

```php
$database->transaction(function (SQLDatabase $database): void {
  InvoiceRecord::useConnection($database);

  $record = new InvoiceRecord();
  $record->setConnection($database);
  // Assign ordinary infrastructure fields.

  if (!$record->save()) {
    // Translate model/connection errors at the repository boundary.
    throw new PersistenceException('Invoice record could not be saved.');
  }
});
```

The caller owns the transaction. Returning false from a SQL Database callback
does not request rollback, so a model failure must be checked and translated to
an exception when rollback is required. Commit failures and uncertain outcomes
belong to the repository/application reconciliation policy; this package does
not retry them.

External providers, mail, wallets, files, and queues stay outside the database
callback. Billing locks, version checks, immutable financial records, and exact
affected-row transitions remain explicit repository operations.

## Schema caching

Field metadata is cached by the wrapped driver object's identity plus database
and table. Identically named tables on different servers or connections do not
share metadata, failed discovery is never cached, and explicit primary-key
overrides do not alter cached table metadata.

```php
use TimeFrontiers\Database\Schema\TableSchema;

TableSchema::clearCache('application', 'users');
TableSchema::clearCache('application');
TableSchema::clearCache();
```

## Linktude connection upgrade compatibility

The protected `_upgradeConn()` helper remains for legacy Linktude models. It
requires the global `get_constant`, `get_dbuser`, and `get_dbserver` bootstrap
helpers. It returns an existing non-guest connection unchanged and refuses to
replace a guest connection during an active managed transaction. Repositories
should acquire the required connection before beginning and should not call
this helper.

## Tests

```bash
composer test-unit
composer test-mysqli
composer test-pdo
composer test-transaction
composer test-integration
composer test
```

Integration tests use `TF_SQL_TEST_HOST`, `TF_SQL_TEST_PORT`,
`TF_SQL_TEST_DATABASE`, `TF_SQL_TEST_USER`, and `TF_SQL_TEST_PASSWORD`. The
database name must contain `test`; the suite creates and removes only uniquely
named disposable tables.

See [UPGRADING.md](UPGRADING.md) before moving a 1.0 consumer to 1.1.

## License

MIT License.
