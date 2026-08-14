<?php

declare(strict_types=1);

namespace TimeFrontiers\Helper;

use TimeFrontiers\Database\QueryBuilder;
use TimeFrontiers\Database\Schema\TableSchema;
use TimeFrontiers\Exceptions\{DatabaseException, SchemaException};
use TimeFrontiers\Internal\{ImportsConnectionErrors, SqlIdentifier};
use TimeFrontiers\{AccessGroup, SQLDatabase};

/**
 * Database Object trait for Active Record pattern.
 *
 * Provides CRUD operations, query building, and schema management
 * for entity classes.
 *
 * Required static properties in using class:
 *   protected static string $_db_name;
 *   protected static string $_table_name;
 *   protected static string $_primary_key = 'id';
 *
 * Optional static properties:
 *   protected static array $_db_fields = [];  // Auto-loaded if empty
 *
 * Usage:
 *   class User {
 *     use DatabaseObject;
 *
 *     protected static string $_db_name = 'myapp';
 *     protected static string $_table_name = 'users';
 *     protected static string $_primary_key = 'id';
 *
 *     public int $id;
 *     public string $name;
 *     public string $email;
 *   }
 *
 *   $user = User::findById(123);
 *   $user->name = 'John';
 *   $user->save();
 */
trait DatabaseObject {

  use HasErrors, ImportsConnectionErrors;

  // Connection (instance override or static fallback)
  protected ?SQLDatabase $_instance_conn = null;
  private static ?SQLDatabase $_static_conn = null;

  // Properties that can be set to empty values
  public array $empty_props = [];

  // =========================================================================
  // Connection Management
  // =========================================================================

  /**
   * Set connection for this instance.
   */
  public function setConnection(SQLDatabase $conn):void {
    $this->_instance_conn = $conn;
  }

  /**
   * Set static connection for the class.
   */
  public static function useConnection(SQLDatabase $conn):void {
    static::$_static_conn = $conn;
  }

  /**
   * Get the active connection.
   */
  public function conn():SQLDatabase {
    return $this->_getConnection();
  }

  /**
   * Get connection (instance → static → global fallback).
   */
  protected function _getConnection():SQLDatabase {
    // 1. Instance connection
    if ($this->_instance_conn instanceof SQLDatabase) {
      return $this->_instance_conn;
    }

    // 2. Static connection
    if (static::$_static_conn instanceof SQLDatabase) {
      return static::$_static_conn;
    }

    // 3. Global fallback
    global $database;
    if ($database instanceof SQLDatabase) {
      return $database;
    }

    throw new \RuntimeException(
      'No database connection available. Use setConnection(), useConnection(), or define global $database.'
    );
  }

  /**
   * Check if connection is available (static context).
   */
  protected static function _hasConnection():bool {
    if (static::$_static_conn instanceof SQLDatabase) {
      return true;
    }

    global $database;
    return $database instanceof SQLDatabase;
  }

  /**
   * Get connection in static context.
   */
  protected static function _getStaticConnection():SQLDatabase {
    if (static::$_static_conn instanceof SQLDatabase) {
      return static::$_static_conn;
    }

    global $database;
    if ($database instanceof SQLDatabase) {
      return $database;
    }

    throw new \RuntimeException('No database connection available.');
  }
  /**
   * Upgrade db connection
   */
  /**
   * Upgrade db connection from GUEST
   *
   * @param SQLDatabase|null $conn
   * @param AccessGroup $access_group
   * @return SQLDatabase
   */
  protected static function _upgradeConn(SQLDatabase|null $conn = null, AccessGroup $access_group = AccessGroup::USER):SQLDatabase {
    $current = $conn;
    if ($current === null && static::_hasConnection()) {
      $current = static::_getStaticConnection();
    }

    if ($current instanceof SQLDatabase) {
      $user = \strtoupper((string)$current->getUser());
      if (!\str_ends_with($user, 'GUEST')) {
        return $current;
      }

      if ($current->inTransaction()) {
        throw new \LogicException(
          'Acquire the required database connection before beginning the transaction.'
        );
      }
    }

    [$db_server, $db_user, $db_password] = static::_resolveUpgradeConnectionSettings($access_group);

    try {
      return new SQLDatabase($db_server, $db_user, $db_password, static::$_db_name, true);
    } catch (\Throwable) {
      throw new \RuntimeException('Failed to create the upgraded database connection.');
    }
  }

  /**
   * Resolve Linktude bootstrap connection settings.
   *
   * @return array{string, string, string}
   */
  protected static function _resolveUpgradeConnectionSettings(AccessGroup $access_group):array {
    foreach (['get_constant', 'get_dbuser', 'get_dbserver'] as $helper) {
      if (!\function_exists($helper)) {
        throw new \RuntimeException(
          'Linktude database bootstrap helpers are unavailable.'
        );
      }
    }

    try {
      $server_name = \get_constant('PRJ_SERVER_NAME');
      if (!\is_string($server_name) || $server_name === '') {
        throw new \RuntimeException();
      }

      $credentials = \get_dbuser($server_name, $access_group->value);
      $server = \get_dbserver($server_name);
    } catch (\Throwable) {
      throw new \RuntimeException('Linktude database bootstrap configuration is invalid.');
    }

    if (
      !\is_array($credentials)
      || !isset($credentials[0], $credentials[1])
      || !\is_string($credentials[0])
      || !\is_string($credentials[1])
      || !\is_string($server)
      || $server === ''
    ) {
      throw new \RuntimeException('Linktude database bootstrap configuration is invalid.');
    }

    return [$server, $credentials[0], $credentials[1]];
  }

  // =========================================================================
  // Schema Management
  // =========================================================================

  /**
   * Get the table schema.
   */
  protected function _getSchema():TableSchema {
    return static::_getSchemaForConnection($this->_getConnection());
  }

  /**
   * Get the table schema for an exact connection.
   *
   * Metadata caching, including its connection-identity isolation, belongs to
   * TableSchema. This method deliberately keeps no model-level schema state.
   */
  protected static function _getSchemaForConnection(SQLDatabase $conn):TableSchema {
    return new TableSchema(
      $conn,
      static::$_db_name,
      static::$_table_name,
      static::$_primary_key ?? null
    );
  }

  /**
   * Get field list (lazy-loaded).
   */
  protected function _getFields():array {
    return static::_resolveFields($this->_getConnection());
  }

  /**
   * Resolve declared or lazily discovered fields for an exact connection.
   */
  protected static function _resolveFields(SQLDatabase $conn):array {
    if (!empty(static::$_db_fields)) {
      return static::$_db_fields;
    }

    return static::_getSchemaForConnection($conn)->getFields();
  }

  // =========================================================================
  // Static Accessors
  // =========================================================================

  public static function primaryKey():string {
    return static::$_primary_key;
  }

  public static function tableName():string {
    return static::$_table_name;
  }

  public static function databaseName():string {
    return static::$_db_name;
  }

  public static function tableFields():array {
    return static::_resolveFields(static::_getStaticConnection());
  }

  // =========================================================================
  // Query Methods
  // =========================================================================

  /**
   * Create a new query builder.
   */
  public static function query():QueryBuilder {
    return new QueryBuilder(
      static::_getStaticConnection(),
      static::$_db_name,
      static::$_table_name,
      static::class
    );
  }

  /**
   * Find all records.
   *
   * @return array<static>|false
   */
  public static function findAll():array|false {
    return static::query()->get();
  }

  /**
   * Find by primary key.
   *
   * @return static|false
   */
  public static function findById(int|string $id):static|false {
    $fields = static::_resolveFields(static::_getStaticConnection());

    if (\in_array('code', $fields, true)) {
      return static::query()
        ->where(static::$_primary_key, $id)
        ->orWhere("code", $id)
        ->first();
    } else {
      return static::query()
        ->where(static::$_primary_key, $id)
        ->first();
    }
  }

  /**
   * Find by SQL query.
   *
   * Placeholders:
   *   :db: or :database: → database name
   *   :tbl: or :table: → table name
   *   :pkey: or :primary_key: → primary key column
   *
   * @return array<static>|false
   */
  public static function findBySql(string $sql, array $params = []):array|false {
    $conn = static::_getStaticConnection();

    // Replace placeholders
    $sql = \str_replace(
      [':database:', ':db:'],
      SqlIdentifier::quote(static::$_db_name),
      $sql
    );
    $sql = \str_replace(
      [':table:', ':tbl:'],
      SqlIdentifier::quote(static::$_table_name),
      $sql
    );
    $sql = \str_replace(
      [':primary_key:', ':pkey:'],
      SqlIdentifier::quote(static::$_primary_key),
      $sql
    );

    $rows = $conn->fetchAll($sql, $params);
    if ($rows === false) {
      return false;
    }

    if (empty($rows)) {
      return [];
    }

    return \array_map(fn($row) => static::_instantiateFromRow($row, $conn), $rows);
  }

  /**
   * Check if a value exists in a column.
   */
  public static function valueExists(string $column, mixed $value):bool {
    return static::query()
      ->where($column, $value)
      ->exists();
  }

  /**
   * Count all records.
   */
  public static function countAll():int|false {
    return static::query()->count();
  }

  // =========================================================================
  // CRUD Operations
  // =========================================================================

  /**
   * Save the entity (insert or update).
   *
   * A populated primary key always routes to update. Inserts with preassigned
   * keys belong in explicit repository SQL.
   */
  public function save():bool {
    $pkey = static::$_primary_key;
    return !empty($this->$pkey) ? $this->_update() : $this->_create();
  }

  /**
   * Delete the entity.
   */
  public function delete():bool {
    return $this->_delete();
  }

  /**
   * Set a property value.
   */
  public function setProp(string $prop, mixed $value):void {
    if (\property_exists($this, $prop)) {
      $this->$prop = $value;
    }
  }

  /**
   * Get the next auto-increment value.
   */
  public function nextAutoIncrement():int|false {
    $conn = $this->_getConnection();

    $row = $conn->fetchOne(
      "SELECT AUTO_INCREMENT
       FROM INFORMATION_SCHEMA.TABLES
       WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
      [static::$_db_name, static::$_table_name]
    );

    return $row ? (int) $row['AUTO_INCREMENT'] : false;
  }

  // =========================================================================
  // Timestamp Accessors
  // =========================================================================

  public function created(?string $date = null):?string {
    if ($date !== null && \strtotime($date)) {
      $this->_created = $date;
    }

    return \property_exists($this, '_created') ? $this->_created : null;
  }

  public function updated():?string {
    return \property_exists($this, '_updated') ? $this->_updated : null;
  }

  public function author():?string {
    return \property_exists($this, '_author') ? $this->_author : null;
  }

  // =========================================================================
  // Protected CRUD Implementations
  // =========================================================================

  protected function _create():bool {
    $conn = $this->_getConnection();

    // Set timestamps
    if (\property_exists($this, '_created') && empty($this->_created)) {
      $this->_created = \date('Y-m-d H:i:s');
    }
    if (\property_exists($this, '_updated')) {
      $this->_updated = \date('Y-m-d H:i:s');
    }

    // Set author
    if (\property_exists($this, '_author') && empty($this->_author)) {
      global $session;
      if (isset($session) && \is_object($session)) {
        $this->_author = $session->name ?? ($session->getName() ?? null);
      }
      if (empty($this->_author)) {
        $this->_addError('_create', 'Author not set. Provide $session->name or call setAuthor().');
      }
    }

    $error_snapshot = $this->_snapshotConnectionErrors($conn);
    try {
      $attributes = $this->_getSanitizedAttributes();

      if (empty($attributes)) {
        $this->_userError('_create', 'No data to insert');
        return false;
      }

      $columns = \array_keys($attributes);
      $placeholders = \array_fill(0, \count($columns), '?');
      $quoted_columns = \array_map(SqlIdentifier::quote(...), $columns);
      $table = SqlIdentifier::quoteTable(static::$_db_name, static::$_table_name);

      $sql = \sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        \implode(', ', $quoted_columns),
        \implode(', ', $placeholders)
      );

      // Resolve every schema-dependent decision before the mutation. Schema
      // discovery after a committed insert could otherwise report a successful
      // write as a failure and invite a duplicating retry.
      $pkey = static::$_primary_key;
      $assign_insert_id = \property_exists($this, $pkey)
        && $this->_getSchema()->isNumeric($pkey);

      if ($conn->execute($sql, \array_values($attributes)) === false) {
        $this->_importConnectionErrorDelta($conn, $error_snapshot, '_create');
        return false;
      }

      if ($assign_insert_id) {
        $this->$pkey = static::_normalizeInsertId($conn->insertId());
      }

      return true;
    } catch (SchemaException) {
      $this->_importConnectionErrorDelta(
        $conn,
        $error_snapshot,
        '_create',
        'Database schema discovery failed.'
      );
      return false;
    } catch (DatabaseException) {
      $this->_importConnectionErrorDelta($conn, $error_snapshot, '_create');
      return false;
    }
  }

  protected function _update():bool {
    $conn = $this->_getConnection();
    $pkey = static::$_primary_key;

    if (empty($this->$pkey)) {
      $this->_userError('_update', 'Cannot update: primary key not set');
      return false;
    }

    // Update timestamp
    if (\property_exists($this, '_updated')) {
      $this->_updated = \date('Y-m-d H:i:s');
    }

    $error_snapshot = $this->_snapshotConnectionErrors($conn);
    try {
      $attributes = $this->_getSanitizedAttributes();

      if (empty($attributes)) {
        $this->_userError('_update', 'No data to update');
        return false;
      }

      $setPairs = [];
      $params = [];

      foreach ($attributes as $column => $value) {
        $quoted_column = SqlIdentifier::quote($column);
        if ($value === null) {
          $setPairs[] = "{$quoted_column} = NULL";
        } else {
          $setPairs[] = "{$quoted_column} = ?";
          $params[] = $value;
        }
      }

      $params[] = $this->$pkey;
      $table = SqlIdentifier::quoteTable(static::$_db_name, static::$_table_name);
      $primary_key = SqlIdentifier::quote($pkey);
      $sql = \sprintf(
        'UPDATE %s SET %s WHERE %s = ?',
        $table,
        \implode(', ', $setPairs),
        $primary_key
      );

      if ($conn->execute($sql, $params) === false) {
        $this->_importConnectionErrorDelta($conn, $error_snapshot, '_update');
        return false;
      }

      // 0 rows affected could mean no changes, not necessarily an error
      return $conn->affectedRows() >= 0;
    } catch (SchemaException) {
      $this->_importConnectionErrorDelta(
        $conn,
        $error_snapshot,
        '_update',
        'Database schema discovery failed.'
      );
      return false;
    } catch (DatabaseException) {
      $this->_importConnectionErrorDelta($conn, $error_snapshot, '_update');
      return false;
    }
  }

  protected function _delete():bool {
    $conn = $this->_getConnection();
    $pkey = static::$_primary_key;

    if (empty($this->$pkey)) {
      $this->_userError('_delete', 'Cannot delete: primary key not set');
      return false;
    }

    $table = SqlIdentifier::quoteTable(static::$_db_name, static::$_table_name);
    $primary_key = SqlIdentifier::quote($pkey);
    $sql = "DELETE FROM {$table} WHERE {$primary_key} = ? LIMIT 1";
    $error_snapshot = $this->_snapshotConnectionErrors($conn);
    try {
      if ($conn->execute($sql, [$this->$pkey]) === false) {
        $this->_importConnectionErrorDelta($conn, $error_snapshot, '_delete');
        return false;
      }

      return $conn->affectedRows() === 1;
    } catch (DatabaseException) {
      $this->_importConnectionErrorDelta($conn, $error_snapshot, '_delete');
      return false;
    }
  }

  // =========================================================================
  // Attribute Handling
  // =========================================================================

  /**
   * Normalize a driver insert ID for a numeric primary-key property.
   *
   * MySQLi reports the value as an integer while PDO reports the same value as
   * a string. An integral value inside PHP's integer range is returned as an
   * int so a typed `int`/`?int` primary key accepts it on both drivers. A value
   * beyond that range is returned unchanged so a wide BIGINT key is never
   * silently truncated.
   */
  protected static function _normalizeInsertId(int|string $insert_id):int|string {
    if (\is_int($insert_id)) {
      return $insert_id;
    }

    $candidate = \filter_var($insert_id, \FILTER_VALIDATE_INT);

    return $candidate === false ? $insert_id : $candidate;
  }

  /**
   * Get object attributes that map to database fields.
   */
  protected function _getAttributes(?array $fields = null):array {
    $fields ??= $this->_getFields();
    $attributes = [];

    foreach ($fields as $field) {
      if (\property_exists($this, $field)) {
        $attributes[$field] = $this->$field;
      }
    }

    return $attributes;
  }

  /**
   * Get sanitized attributes for SQL.
   */
  protected function _getSanitizedAttributes():array {
    $schema = $this->_getSchema();
    $fields = !empty(static::$_db_fields) ? static::$_db_fields : $schema->getFields();
    $attributes = $this->_getAttributes($fields);
    $sanitized = [];

    foreach ($attributes as $field => $value) {
      // Skip empty values unless allowed
      if ($this->_isEmpty($field, $value, $schema) && !\in_array($field, $this->empty_props, true)) {
        continue;
      }

      // Handle by field type
      if ($schema->isBoolean($field)) {
        $sanitized[$field] = $value ? 1 : 0;
      } elseif ($value === null) {
        // Explicit null always stays null regardless of column type
        $sanitized[$field] = null;
      } elseif ($this->_isEmpty($field, $value, $schema) && \in_array($field, $this->empty_props, true)) {
        // Empty (but not null) whitelisted field — coerce to safe default for type
        if ($schema->isDateTime($field) || $schema->isText($field)) {
          $sanitized[$field] = null;
        } elseif ($schema->isNumeric($field)) {
          $sanitized[$field] = 0;
        } else {
          $sanitized[$field] = '';
        }
      } else {
        $sanitized[$field] = $value;
      }
    }

    return $sanitized;
  }

  /**
   * Check if a value is empty for its field type.
   */
  protected function _isEmpty(string $field, mixed $value, ?TableSchema $schema = null):bool {
    $schema ??= $this->_getSchema();

    if ($schema->isNumeric($field)) {
      return \strlen((string) $value) === 0;
    }

    if ($schema->isDateTime($field)) {
      return empty($value) || !\strtotime((string) $value);
    }

    if (\is_bool($value)) {
      return false; // Booleans are never "empty"
    }

    return empty($value);
  }

  // =========================================================================
  // Instantiation
  // =========================================================================

  /**
   * Create instance from database row.
   * Called by QueryBuilder and findBySql.
   */
  public static function _instantiateFromRow(array $row, ?SQLDatabase $conn = null):static {
    $conn ??= static::_getStaticConnection();
    $instance = new static(
      static::$_db_name,
      static::$_table_name,
      static::$_primary_key,
      $conn
    );

    foreach ($row as $key => $value) {
      if (!\is_int($key) && \property_exists($instance, $key)) {
        $instance->$key = $value;
      }
    }

    $instance->setConnection($conn);

    return $instance;
  }
}
