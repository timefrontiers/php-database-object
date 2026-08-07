# Upgrading from 1.0 to 1.1

Version 1.1 aligns Database Object with SQL Database 1.1 false-result and
transaction semantics. Review every item below before changing the Composer
constraint.

## Dependencies

Require the coordinated 1.1 line:

```bash
composer require timefrontiers/php-database-object:^1.1
```

The package requires PHP 8.4+, SQL Database 1.1, HasErrors 1.x, and PHP Core
1.x. PDO/PDO-MySQL remain optional integration capabilities rather than hard
extension requirements.

## Check false results strictly

`get()` and `findAll()` now return `array|false`:

```php
$records = UserRecord::query()->get();
if ($records === false) {
  // Database failure; inspect the builder errors.
} elseif ($records === []) {
  // Successful query with no records.
}
```

`count()` and `countAll()` now return `int|false`:

```php
$count = UserRecord::query()->count();
if ($count === false) {
  // Database failure.
} elseif ($count === 0) {
  // Successful count with no matches.
}
```

`first()` and `exists()` retain their boolean/false-compatible public shapes.
Inspect the query builder's errors to distinguish failure from no match.

## Remove duplicate HasErrors usage

`DatabaseObject` already includes `HasErrors`:

```php
final class UserRecord
{
  use DatabaseObject;
}
```

Do not declare `use DatabaseObject, HasErrors` in the same model.

## Inject the transaction facade explicitly

Every aggregate operation must use the caller's exact facade:

```php
$database->transaction(function (SQLDatabase $database): void {
  UserRecord::useConnection($database);

  $record = new UserRecord();
  $record->setConnection($database);

  if (!$record->save()) {
    throw new PersistenceException('User record could not be saved.');
  }
});
```

The model does not own the transaction. A false callback result is not a
rollback request; translate a model failure to an exception when rollback is
required. Do not retry or hide a commit failure here.

## Move raw fluent expressions to custom SQL

Public `select()` now accepts only `*` and plain identifiers. General operators
are limited to `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE`, and
`<=>`. Unsupported operators, expressions, identifiers, ordering directions,
and negative pagination throw `InvalidArgumentException` before execution.

Move reviewed expressions to `findBySql()`:

```php
$totals = UserRecord::findBySql(
  'SELECT status, COUNT(*) AS total FROM :db:.:table: GROUP BY status'
);
```

Model placeholders now expand to quoted identifiers. Remove backticks already
wrapped around placeholders to avoid double quoting.

Explicit three-argument null values are no longer reinterpreted as the
two-argument form. Use `whereNull()` or `whereNotNull()` for SQL null tests.
Empty `IN` matches nothing and empty `NOT IN` matches everything.

## Review write assumptions

- A false `execute()` result always makes create, update, or delete fail.
- A successfully executed unchanged update may still return true.
- Delete succeeds only when exactly one row is affected.
- A populated primary key still routes `save()` to update. Use explicit SQL for
  preassigned-key inserts.
- Optimistic locking and exact affected-row policies remain repository logic.

New model errors contain only connection diagnostics added by the current
operation. They retain the ecosystem tuple shape and omit bound values and
credentials.

## Review schema behavior

Schema discovery now fails instead of silently caching empty metadata or
assuming `id`. Caches are isolated by concrete connection identity. If a
deployment changes a table at runtime, clear its metadata explicitly with
`TableSchema::clearCache()`.

## Review `_upgradeConn()` callers

`_upgradeConn()` remains a Linktude-only compatibility helper. It requires the
global bootstrap helpers and throws `RuntimeException` when they are absent. It
throws `LogicException` rather than replacing a guest connection in an active
transaction. Acquire the correct facade before beginning a repository
transaction.

## Verification checklist

- Replace loose result checks with strict `=== false`, `=== []`, or `=== 0`
  comparisons as appropriate.
- Inspect builder errors after false `first()` or `exists()` when the distinction
  matters.
- Remove fluent raw expressions and unsupported operators.
- Confirm every transaction-bound record receives the callback facade.
- Run the unit, MySQLi, PDO, transaction, and full Composer suites.

