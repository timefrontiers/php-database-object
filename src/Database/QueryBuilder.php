<?php

declare(strict_types=1);

namespace TimeFrontiers\Database;

use TimeFrontiers\SQLDatabase;

/**
 * Simple fluent query builder.
 *
 * Usage:
 *   $users = User::query()
 *     ->where('status', 'active')
 *     ->where('created_at', '>', '2024-01-01')
 *     ->orderBy('name')
 *     ->limit(10)
 *     ->get();
 */
class QueryBuilder {

  private SQLDatabase $_conn;
  private string $_database;
  private string $_table;
  private string $_entity_class;

  private array $_select = ['*'];
  private array $_where = [];
  private array $_params = [];
  private array $_order_by = [];
  private ?int $_limit = null;
  private ?int $_offset = null;

  public function __construct(
    SQLDatabase $conn,
    string $database,
    string $table,
    string $entity_class
  ) {
    $this->_conn = $conn;
    $this->_database = $database;
    $this->_table = $table;
    $this->_entity_class = $entity_class;
  }

  /**
   * Set columns to select.
   */
  public function select(string|array $columns):self {
    $this->_select = \is_array($columns) ? $columns : \func_get_args();
    return $this;
  }

  /**
   * Add a where condition.
   *
   * @param string $column Column name
   * @param mixed $operator Operator or value (if 2 args)
   * @param mixed $value Value (if 3 args)
   */
  public function where(string $column, mixed $operator, mixed $value = null):self {
    if ($value === null) {
      // Two args: where('status', 'active') means =
      $value = $operator;
      $operator = '=';
    }

    $this->_where[] = [
      'column' => $column,
      'operator' => \strtoupper($operator),
      'value' => $value,
      'boolean' => 'AND',
    ];

    return $this;
  }

  /**
   * Add an OR where condition.
   */
  public function orWhere(string $column, mixed $operator, mixed $value = null):self {
    if ($value === null) {
      $value = $operator;
      $operator = '=';
    }

    $this->_where[] = [
      'column' => $column,
      'operator' => \strtoupper($operator),
      'value' => $value,
      'boolean' => 'OR',
    ];

    return $this;
  }

  /**
   * Add a WHERE IN condition.
   */
  public function whereIn(string $column, array $values):self {
    $this->_where[] = [
      'column' => $column,
      'operator' => 'IN',
      'value' => $values,
      'boolean' => 'AND',
    ];

    return $this;
  }

  /**
   * Add a WHERE NOT IN condition.
   */
  public function whereNotIn(string $column, array $values):self {
    $this->_where[] = [
      'column' => $column,
      'operator' => 'NOT IN',
      'value' => $values,
      'boolean' => 'AND',
    ];

    return $this;
  }

  /**
   * Add a WHERE NULL condition.
   */
  public function whereNull(string $column):self {
    $this->_where[] = [
      'column' => $column,
      'operator' => 'IS NULL',
      'value' => null,
      'boolean' => 'AND',
    ];

    return $this;
  }

  /**
   * Add a WHERE NOT NULL condition.
   */
  public function whereNotNull(string $column):self {
    $this->_where[] = [
      'column' => $column,
      'operator' => 'IS NOT NULL',
      'value' => null,
      'boolean' => 'AND',
    ];

    return $this;
  }

  /**
   * Add ORDER BY clause.
   */
  public function orderBy(string $column, string $direction = 'ASC'):self {
    $this->_order_by[] = [
      'column' => $column,
      'direction' => \strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC',
    ];

    return $this;
  }

  /**
   * Add ORDER BY DESC.
   */
  public function orderByDesc(string $column):self {
    return $this->orderBy($column, 'DESC');
  }

  /**
   * Set LIMIT.
   */
  public function limit(int $limit):self {
    $this->_limit = $limit;
    return $this;
  }

  /**
   * Set OFFSET.
   */
  public function offset(int $offset):self {
    $this->_offset = $offset;
    return $this;
  }

  /**
   * Shorthand for limit + offset.
   */
  public function take(int $limit, int $offset = 0):self {
    $this->_limit = $limit;
    $this->_offset = $offset;
    return $this;
  }

  /**
   * Execute and get all results.
   *
   * @return array Array of entity instances
   */
  public function get():array {
    [$sql, $params] = $this->_buildSelect();

    $rows = $this->_conn->fetchAll($sql, $params);

    if (empty($rows)) {
      return [];
    }

    return $this->_hydrateMany($rows);
  }

  /**
   * Execute and get first result.
   *
   * @return object|false Entity instance or false if not found
   */
  public function first():object|false {
    $this->_limit = 1;
    $results = $this->get();

    return $results[0] ?? false;
  }

  /**
   * Get count of matching records.
   */
  public function count():int {
    $this->_select = ['COUNT(*) AS cnt'];
    [$sql, $params] = $this->_buildSelect();

    $row = $this->_conn->fetchOne($sql, $params);

    return (int) ($row['cnt'] ?? 0);
  }

  /**
   * Check if any records exist.
   */
  public function exists():bool {
    return $this->count() > 0;
  }

  /**
   * Get the generated SQL (for debugging).
   */
  public function toSql():array {
    return $this->_buildSelect();
  }

  // =========================================================================
  // Private Methods
  // =========================================================================

  private function _buildSelect():array {
    $params = [];

    // SELECT
    $columns = \implode(', ', \array_map(fn($c) => $c === '*' ? '*' : "`{$c}`", $this->_select));
    $sql = "SELECT {$columns} FROM `{$this->_database}`.`{$this->_table}`";

    // WHERE
    if (!empty($this->_where)) {
      $sql .= ' WHERE ';
      $conditions = [];

      foreach ($this->_where as $i => $clause) {
        $condition = '';

        if ($i > 0) {
          $condition .= $clause['boolean'] . ' ';
        }

        $column = "`{$clause['column']}`";

        switch ($clause['operator']) {
          case 'IN':
          case 'NOT IN':
            $placeholders = \implode(', ', \array_fill(0, \count($clause['value']), '?'));
            $condition .= "{$column} {$clause['operator']} ({$placeholders})";
            $params = \array_merge($params, $clause['value']);
            break;

          case 'IS NULL':
          case 'IS NOT NULL':
            $condition .= "{$column} {$clause['operator']}";
            break;

          default:
            $condition .= "{$column} {$clause['operator']} ?";
            $params[] = $clause['value'];
            break;
        }

        $conditions[] = $condition;
      }

      $sql .= \implode(' ', $conditions);
    }

    // ORDER BY
    if (!empty($this->_order_by)) {
      $orders = \array_map(
        fn($o) => "`{$o['column']}` {$o['direction']}",
        $this->_order_by
      );
      $sql .= ' ORDER BY ' . \implode(', ', $orders);
    }

    // LIMIT
    if ($this->_limit !== null) {
      $sql .= " LIMIT {$this->_limit}";

      if ($this->_offset !== null) {
        $sql .= " OFFSET {$this->_offset}";
      }
    }

    return [$sql, $params];
  }

  private function _hydrateMany(array $rows):array {
    $entities = [];
    $class = $this->_entity_class;

    foreach ($rows as $row) {
      $entities[] = $class::_instantiateFromRow($row);
    }

    return $entities;
  }
}
