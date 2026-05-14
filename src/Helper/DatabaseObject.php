<?php

declare(strict_types=1);

namespace TimeFrontiers\Helper;

use TimeFrontiers\{SQLDatabase, AccessGroup};
use TimeFrontiers\Database\QueryBuilder;
use TimeFrontiers\Database\Schema\TableSchema;

use function TimeFrontiers\{
  get_constant,
  get_dbuser,
  get_dbserver,
  get_database
};

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
 *     use DatabaseObject, HasErrors;
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

  use HasErrors;

  // Connection (instance override or static fallback)
  protected ?SQLDatabase $_instance_conn = null;
  private static ?SQLDatabase $_static_conn = null;

  // Schema cache
  protected static ?TableSchema $_schema = null;

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
    if ($conn && $conn instanceof SQLDatabase) {
      if (!\str_ends_with($conn->getUser(), "GUEST")) return $conn;
    }
    $server_name = get_constant("PRJ_SERVER_NAME");
    $db_user = get_dbuser($server_name, $access_group->value);
    $db_server = get_dbserver($server_name);
    try {
      $conn = new SQLDatabase($db_server, $db_user[0], $db_user[1], static::$_table_name, true);
    } catch (\Throwable $th) {
      throw new \Exception("Failed to create database connection upgrade: {$th->getMessage()}", 1);
    }
    return $conn;
  }

  // =========================================================================
  // Schema Management
  // =========================================================================

  /**
   * Get the table schema.
   */
  protected function _getSchema():TableSchema {
    if (static::$_schema === null) {
      static::$_schema = new TableSchema(
        $this->_getConnection(),
        static::$_db_name,
        static::$_table_name,
        static::$_primary_key ?? null
      );
    }

    return static::$_schema;
  }

  /**
   * Get field list (lazy-loaded).
   */
  protected function _getFields():array {
    if (empty(static::$_db_fields)) {
      static::$_db_fields = $this->_getSchema()->getFields();
    }

    return static::$_db_fields;
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
    return static::$_db_fields;
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
   * @return array<static>
   */
  public static function findAll():array {
    return static::query()->get();
  }

  /**
   * Find by primary key.
   *
   * @return static|false
   */
  public static function findById(int|string $id):static|false {
    if (\in_array("code", static::$_db_fields)) {
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
    $sql = \str_replace([':database:', ':db:'], static::$_db_name, $sql);
    $sql = \str_replace([':table:', ':tbl:'], static::$_table_name, $sql);
    $sql = \str_replace([':primary_key:', ':pkey:'], static::$_primary_key, $sql);

    $rows = $conn->fetchAll($sql, $params);
    if ($rows === false) {
      return false;
    }

    if (empty($rows)) {
      return [];
    }

    return \array_map(fn($row) => static::_instantiateFromRow($row), $rows);
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
  public static function countAll():int {
    return static::query()->count();
  }

  // =========================================================================
  // CRUD Operations
  // =========================================================================

  /**
   * Save the entity (insert or update).
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

    $attributes = $this->_getSanitizedAttributes();

    if (empty($attributes)) {
      $this->_userError('_create', 'No data to insert');
      return false;
    }

    $columns = \array_keys($attributes);
    $placeholders = \array_fill(0, \count($columns), '?');

    $sql = \sprintf(
      "INSERT INTO `%s`.`%s` (`%s`) VALUES (%s)",
      static::$_db_name,
      static::$_table_name,
      \implode('`, `', $columns),
      \implode(', ', $placeholders)
    );

    try {
      $conn->execute($sql, \array_values($attributes));

      // Set auto-increment ID if applicable
      $pkey = static::$_primary_key;
      if (\property_exists($this, $pkey)) {
        $schema = $this->_getSchema();
        if ($schema->isNumeric($pkey)) {
          $this->$pkey = $conn->insertId();
        }
      }

      return true;
    } catch (\Exception $e) {
      $this->_systemError('_create', $e->getMessage());
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

    $attributes = $this->_getSanitizedAttributes();

    if (empty($attributes)) {
      $this->_userError('_update', 'No data to update');
      return false;
    }

    $setPairs = [];
    $params = [];

    foreach ($attributes as $column => $value) {
      if ($value === null) {
        $setPairs[] = "`{$column}` = NULL";
      } else {
        $setPairs[] = "`{$column}` = ?";
        $params[] = $value;
      }
    }

    $params[] = $this->$pkey;

    $sql = \sprintf(
      "UPDATE `%s`.`%s` SET %s WHERE `%s` = ?",
      static::$_db_name,
      static::$_table_name,
      \implode(', ', $setPairs),
      $pkey
    );

    try {
      $conn->execute($sql, $params);
      $affected = $conn->affectedRows();

      // 0 rows affected could mean no changes, not necessarily an error
      return $affected >= 0;
    } catch (\Exception $e) {
      $this->_systemError('_update', $e->getMessage());
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

    $sql = \sprintf(
      "DELETE FROM `%s`.`%s` WHERE `%s` = ? LIMIT 1",
      static::$_db_name,
      static::$_table_name,
      $pkey
    );

    try {
      $conn->execute($sql, [$this->$pkey]);
      return $conn->affectedRows() === 1;
    } catch (\Exception $e) {
      $this->_systemError('_delete', $e->getMessage());
      return false;
    }
  }

  // =========================================================================
  // Attribute Handling
  // =========================================================================

  /**
   * Get object attributes that map to database fields.
   */
  protected function _getAttributes():array {
    $fields = $this->_getFields();
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
    $attributes = $this->_getAttributes();
    $sanitized = [];

    foreach ($attributes as $field => $value) {
      // Skip empty values unless allowed
      if ($this->_isEmpty($field, $value) && !\in_array($field, $this->empty_props, true)) {
        continue;
      }

      // Handle by field type
      if ($schema->isBoolean($field)) {
        $sanitized[$field] = $value ? 1 : 0;
      } elseif ($value === null) {
        // Explicit null always stays null regardless of column type
        $sanitized[$field] = null;
      } elseif ($this->_isEmpty($field, $value) && \in_array($field, $this->empty_props, true)) {
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
  protected function _isEmpty(string $field, mixed $value):bool {
    $schema = $this->_getSchema();

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
  public static function _instantiateFromRow(array $row):static {
    $instance = new static(
      static::$_db_name,
      static::$_table_name,
      static::$_primary_key,
      static::_getStaticConnection()
    );

    foreach ($row as $key => $value) {
      if (!\is_int($key) && \property_exists($instance, $key)) {
        $instance->$key = $value;
      }
    }

    return $instance;
  }
}
