<?php

declare(strict_types=1);

namespace TimeFrontiers\Database\Schema;

use TimeFrontiers\Exceptions\SchemaException;
use TimeFrontiers\Internal\SqlIdentifier;
use TimeFrontiers\SQLDatabase;

/**
 * Cached table schema information.
 *
 * Metadata is isolated by the concrete wrapped connection so workers may use
 * identically named databases on different servers without sharing schemas.
 */
class TableSchema
{
  /** @var \WeakMap<object, array<string, array<string, array>>>|null */
  private static ?\WeakMap $_cache = null;

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

    $identity = $conn->getInstance();
    $entry = self::_cacheEntry($identity, $database, $table);

    if ($entry === null) {
      $entry = $this->_loadSchema($conn);

      if ($primary_key === null) {
        $entry['primary_key'] = $this->_loadPrimaryKey($conn);
      }

      self::_storeCacheEntry($identity, $database, $table, $entry);
    } elseif ($primary_key === null && $entry['primary_key'] === null) {
      $entry['primary_key'] = $this->_loadPrimaryKey($conn);
      self::_storeCacheEntry($identity, $database, $table, $entry);
    }

    $this->_fields = $entry['fields'];
    $this->_field_types = $entry['types'];
    $this->_field_sizes = $entry['sizes'];
    $this->_primary_key = $primary_key ?? $entry['primary_key'];
  }

  /**
   * @return array{fields: list<string>, types: array<string, string>, sizes: array<string, int|null>, primary_key: null}
   */
  private function _loadSchema(SQLDatabase $conn): array
  {
    $rows = $conn->fetchAll(
      "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
       FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
      [$this->_database, $this->_table]
    );

    if ($rows === false || $rows === []) {
      throw new SchemaException('Table schema could not be discovered.');
    }

    $fields = [];
    $types = [];
    $sizes = [];

    foreach ($rows as $row) {
      if (
        !\is_array($row)
        || !isset($row['COLUMN_NAME'], $row['DATA_TYPE'])
        || !\is_string($row['COLUMN_NAME'])
        || $row['COLUMN_NAME'] === ''
        || !\is_string($row['DATA_TYPE'])
        || $row['DATA_TYPE'] === ''
      ) {
        throw new SchemaException('Table schema metadata was incomplete.');
      }

      $field = $row['COLUMN_NAME'];
      $fields[] = $field;
      $types[$field] = \strtoupper($row['DATA_TYPE']);
      $size = $row['CHARACTER_MAXIMUM_LENGTH'] ?? null;
      $sizes[$field] = $size !== null ? (int)$size : null;
    }

    return [
      'fields' => $fields,
      'types' => $types,
      'sizes' => $sizes,
      'primary_key' => null,
    ];
  }

  private function _loadPrimaryKey(SQLDatabase $conn): string
  {
    $table = SqlIdentifier::quoteTable($this->_database, $this->_table);
    $row = $conn->fetchOne("SHOW INDEX FROM {$table} WHERE Key_name = 'PRIMARY'");

    if (
      $row === false
      || !isset($row['Column_name'])
      || !\is_string($row['Column_name'])
      || $row['Column_name'] === ''
    ) {
      throw new SchemaException('Table primary key could not be discovered.');
    }

    return $row['Column_name'];
  }

  public function getDatabase(): string
  {
    return $this->_database;
  }

  public function getTable(): string
  {
    return $this->_table;
  }

  public function getPrimaryKey(): string
  {
    return $this->_primary_key;
  }

  public function getFields(): array
  {
    return $this->_fields;
  }

  public function getFieldType(string $field): ?string
  {
    return $this->_field_types[$field] ?? null;
  }

  public function getFieldSize(string $field): ?int
  {
    return $this->_field_sizes[$field] ?? null;
  }

  public function hasField(string $field): bool
  {
    return \in_array($field, $this->_fields, true);
  }

  public function isNumeric(string $field): bool
  {
    $type = $this->_field_types[$field] ?? '';
    return \in_array($type, [
      'BIT', 'TINYINT', 'BOOLEAN', 'SMALLINT', 'MEDIUMINT',
      'INT', 'INTEGER', 'BIGINT', 'FLOAT', 'DOUBLE', 'DECIMAL', 'DEC',
    ], true);
  }

  public function isDateTime(string $field): bool
  {
    $type = $this->_field_types[$field] ?? '';
    return \in_array($type, ['DATE', 'DATETIME', 'TIMESTAMP', 'TIME', 'YEAR'], true);
  }

  public function isText(string $field): bool
  {
    $type = $this->_field_types[$field] ?? '';
    return \in_array($type, [
      'CHAR', 'VARCHAR', 'BLOB', 'TEXT', 'TINYBLOB', 'TINYTEXT',
      'MEDIUMBLOB', 'MEDIUMTEXT', 'LONGBLOB', 'LONGTEXT', 'ENUM', 'JSON',
    ], true);
  }

  public function isBoolean(string $field): bool
  {
    $type = $this->_field_types[$field] ?? '';
    return \in_array($type, ['BIT', 'TINYINT', 'BOOLEAN'], true);
  }

  public static function clearCache(?string $database = null, ?string $table = null): void
  {
    if (self::$_cache === null) {
      return;
    }

    if ($database === null) {
      self::$_cache = new \WeakMap();
      return;
    }

    $identities = [];
    foreach (self::$_cache as $identity => $_databases) {
      $identities[] = $identity;
    }

    foreach ($identities as $identity) {
      $databases = self::$_cache[$identity];
      if (!isset($databases[$database])) {
        continue;
      }

      if ($table === null) {
        unset($databases[$database]);
      } else {
        unset($databases[$database][$table]);
        if ($databases[$database] === []) {
          unset($databases[$database]);
        }
      }

      if ($databases === []) {
        unset(self::$_cache[$identity]);
      } else {
        self::$_cache[$identity] = $databases;
      }
    }
  }

  private static function _cacheEntry(object $identity, string $database, string $table): ?array
  {
    self::$_cache ??= new \WeakMap();
    return self::$_cache[$identity][$database][$table] ?? null;
  }

  private static function _storeCacheEntry(
    object $identity,
    string $database,
    string $table,
    array $entry
  ): void {
    self::$_cache ??= new \WeakMap();
    $databases = self::$_cache[$identity] ?? [];
    $databases[$database][$table] = $entry;
    self::$_cache[$identity] = $databases;
  }
}
