<?php

declare(strict_types=1);

namespace TimeFrontiers\Database\Schema;

use TimeFrontiers\SQLDatabase;

/**
 * Cached table schema information.
 *
 * Fetches and caches field names, types, and sizes from the database
 * to avoid repeated INFORMATION_SCHEMA queries.
 */
class TableSchema {

  private static array $_cache = [];

  private string $_database;
  private string $_table;
  private string $_primary_key;
  private array $_fields = [];
  private array $_field_types = [];
  private array $_field_sizes = [];

  public function __construct(
    SQLDatabase $conn,
    string $database,
    string $table,
    ?string $primary_key = null
  ) {
    $this->_database = $database;
    $this->_table = $table;

    $cache_key = "{$database}.{$table}";

    if (isset(self::$_cache[$cache_key])) {
      $cached = self::$_cache[$cache_key];
      $this->_fields = $cached['fields'];
      $this->_field_types = $cached['types'];
      $this->_field_sizes = $cached['sizes'];
      $this->_primary_key = $primary_key ?? $cached['primary_key'];
    } else {
      $this->_loadSchema($conn);
      if ($primary_key === null) {
        $this->_loadPrimaryKey($conn);
      } else {
        $this->_primary_key = $primary_key;
      }

      self::$_cache[$cache_key] = [
        'fields' => $this->_fields,
        'types' => $this->_field_types,
        'sizes' => $this->_field_sizes,
        'primary_key' => $this->_primary_key,
      ];
    }
  }

  private function _loadSchema(SQLDatabase $conn):void {
    $rows = $conn->fetchAll(
      "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
       FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
      [$this->_database, $this->_table]
    );

    foreach ($rows as $row) {
      $field = $row['COLUMN_NAME'];
      $this->_fields[] = $field;
      $this->_field_types[$field] = \strtoupper($row['DATA_TYPE']);
      $this->_field_sizes[$field] = $row['CHARACTER_MAXIMUM_LENGTH'] !== null
        ? (int) $row['CHARACTER_MAXIMUM_LENGTH']
        : null;
    }
  }

  private function _loadPrimaryKey(SQLDatabase $conn):void {
    $row = $conn->fetchOne(
      "SHOW INDEX FROM `{$this->_database}`.`{$this->_table}` WHERE Key_name = 'PRIMARY'"
    );

    $this->_primary_key = $row['Column_name'] ?? 'id';
  }

  public function getDatabase():string {
    return $this->_database;
  }

  public function getTable():string {
    return $this->_table;
  }

  public function getPrimaryKey():string {
    return $this->_primary_key;
  }

  public function getFields():array {
    return $this->_fields;
  }

  public function getFieldType(string $field):?string {
    return $this->_field_types[$field] ?? null;
  }

  public function getFieldSize(string $field):?int {
    return $this->_field_sizes[$field] ?? null;
  }

  public function hasField(string $field):bool {
    return \in_array($field, $this->_fields, true);
  }

  /**
   * Check if a field is a numeric type.
   */
  public function isNumeric(string $field):bool {
    $type = $this->_field_types[$field] ?? '';
    return \in_array($type, [
      'BIT', 'TINYINT', 'BOOLEAN', 'SMALLINT', 'MEDIUMINT',
      'INT', 'INTEGER', 'BIGINT', 'FLOAT', 'DOUBLE', 'DECIMAL', 'DEC'
    ], true);
  }

  /**
   * Check if a field is a date/time type.
   */
  public function isDateTime(string $field):bool {
    $type = $this->_field_types[$field] ?? '';
    return \in_array($type, ['DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR'], true);
  }

  /**
   * Check if a field is a text type.
   */
  public function isText(string $field):bool {
    $type = $this->_field_types[$field] ?? '';
    return \in_array($type, [
      'CHAR', 'VARCHAR', 'BLOB', 'TEXT', 'TINYBLOB', 'TINYTEXT',
      'MEDIUMBLOB', 'MEDIUMTEXT', 'LONGBLOB', 'LONGTEXT', 'ENUM', 'JSON'
    ], true);
  }

  /**
   * Check if a field is a boolean-like type.
   */
  public function isBoolean(string $field):bool {
    $type = $this->_field_types[$field] ?? '';
    return \in_array($type, ['BIT', 'TINYINT', 'BOOLEAN'], true);
  }

  /**
   * Clear the schema cache.
   */
  public static function clearCache(?string $database = null, ?string $table = null):void {
    if ($database === null) {
      self::$_cache = [];
    } elseif ($table === null) {
      foreach (\array_keys(self::$_cache) as $key) {
        if (\str_starts_with($key, "{$database}.")) {
          unset(self::$_cache[$key]);
        }
      }
    } else {
      unset(self::$_cache["{$database}.{$table}"]);
    }
  }
}
