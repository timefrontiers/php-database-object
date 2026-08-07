<?php

declare(strict_types=1);

namespace TimeFrontiers\Database;

use TimeFrontiers\Helper\HasErrors;
use TimeFrontiers\Internal\{ImportsConnectionErrors, SqlIdentifier};
use TimeFrontiers\SQLDatabase;

/**
 * Prepared, identifier-safe fluent query builder.
 */
class QueryBuilder
{
  use HasErrors, ImportsConnectionErrors;

  private const OPERATORS = [
    '=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', '<=>',
  ];

  private SQLDatabase $_conn;
  private string $_database;
  private string $_table;
  private string $_entity_class;

  private array $_select = ['*'];
  private array $_where = [];
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
   * Select plain columns or *. Custom expressions belong in findBySql().
   */
  public function select(string|array $columns): self
  {
    $selected = \is_array($columns) ? $columns : \func_get_args();
    if ($selected === []) {
      throw new \InvalidArgumentException('At least one select column is required.');
    }

    foreach ($selected as $column) {
      if (!\is_string($column) || $column === '') {
        throw new \InvalidArgumentException('Select columns must be plain identifiers or *.');
      }
      if ($column !== '*') {
        SqlIdentifier::quotePath($column);
      }
    }

    $this->_select = \array_values($selected);
    return $this;
  }

  public function where(string $column, mixed $operator, mixed $value = null): self
  {
    if (\func_num_args() === 2) {
      $value = $operator;
      $operator = '=';
    }

    $this->_addWhere($column, $operator, $value, 'AND');
    return $this;
  }

  public function orWhere(string $column, mixed $operator, mixed $value = null): self
  {
    if (\func_num_args() === 2) {
      $value = $operator;
      $operator = '=';
    }

    $this->_addWhere($column, $operator, $value, 'OR');
    return $this;
  }

  public function whereIn(string $column, array $values): self
  {
    $this->_addSetWhere($column, 'IN', $values, 'AND');
    return $this;
  }

  public function whereNotIn(string $column, array $values): self
  {
    $this->_addSetWhere($column, 'NOT IN', $values, 'AND');
    return $this;
  }

  public function whereNull(string $column): self
  {
    $this->_addNullWhere($column, 'IS NULL', 'AND');
    return $this;
  }

  public function whereNotNull(string $column): self
  {
    $this->_addNullWhere($column, 'IS NOT NULL', 'AND');
    return $this;
  }

  public function orderBy(string $column, string $direction = 'ASC'): self
  {
    SqlIdentifier::quotePath($column);
    $direction = \strtoupper($direction);
    if (!\in_array($direction, ['ASC', 'DESC'], true)) {
      throw new \InvalidArgumentException('Order direction must be ASC or DESC.');
    }

    $this->_order_by[] = [
      'column' => $column,
      'direction' => $direction,
    ];

    return $this;
  }

  public function orderByDesc(string $column): self
  {
    return $this->orderBy($column, 'DESC');
  }

  public function limit(int $limit): self
  {
    if ($limit < 0) {
      throw new \InvalidArgumentException('Limit cannot be negative.');
    }

    $this->_limit = $limit;
    return $this;
  }

  public function offset(int $offset): self
  {
    if ($offset < 0) {
      throw new \InvalidArgumentException('Offset cannot be negative.');
    }

    $this->_offset = $offset;
    return $this;
  }

  public function take(int $limit, int $offset = 0): self
  {
    return $this->limit($limit)->offset($offset);
  }

  /**
   * @return array<object>|false
   */
  public function get(): array|false
  {
    [$sql, $params] = $this->_buildSelect();
    return $this->_fetchMany($sql, $params, 'get');
  }

  public function first(): object|false
  {
    [$sql, $params] = $this->_buildSelect(limit_override: 1);
    $results = $this->_fetchMany($sql, $params, 'first');

    if ($results === false) {
      return false;
    }

    return $results[0] ?? false;
  }

  public function count(): int|false
  {
    return $this->_runCount('count');
  }

  public function exists(): bool
  {
    $count = $this->_runCount('exists');
    return $count !== false && $count > 0;
  }

  /**
   * @return array{string, array}
   */
  public function toSql(): array
  {
    return $this->_buildSelect();
  }

  private function _addWhere(
    string $column,
    mixed $operator,
    mixed $value,
    string $boolean
  ): void {
    SqlIdentifier::quotePath($column);
    if (!\is_string($operator)) {
      throw new \InvalidArgumentException('Query operator must be a string.');
    }

    $operator = \strtoupper(\trim($operator));
    if (!\in_array($operator, self::OPERATORS, true)) {
      throw new \InvalidArgumentException('Unsupported query operator.');
    }

    $this->_where[] = [
      'column' => $column,
      'operator' => $operator,
      'value' => $value,
      'boolean' => $boolean,
    ];
  }

  private function _addSetWhere(
    string $column,
    string $operator,
    array $values,
    string $boolean
  ): void {
    SqlIdentifier::quotePath($column);
    $this->_where[] = [
      'column' => $column,
      'operator' => $operator,
      'value' => $values,
      'boolean' => $boolean,
    ];
  }

  private function _addNullWhere(string $column, string $operator, string $boolean): void
  {
    SqlIdentifier::quotePath($column);
    $this->_where[] = [
      'column' => $column,
      'operator' => $operator,
      'value' => null,
      'boolean' => $boolean,
    ];
  }

  /**
   * @return array{string, array}
   */
  private function _buildSelect(
    ?string $trusted_select = null,
    bool $include_order = true,
    bool $ignore_stored_pagination = false,
    ?int $limit_override = null
  ): array {
    $params = [];
    $columns = $trusted_select ?? \implode(', ', \array_map(
      fn(string $column): string => $column === '*'
        ? '*'
        : SqlIdentifier::quotePath($column),
      $this->_select
    ));
    $table = SqlIdentifier::quoteTable($this->_database, $this->_table);
    $sql = "SELECT {$columns} FROM {$table}";

    if ($this->_where !== []) {
      $conditions = [];

      foreach ($this->_where as $index => $clause) {
        $condition = $index > 0 ? $clause['boolean'] . ' ' : '';
        $values = $clause['value'];

        if ($clause['operator'] === 'IN' || $clause['operator'] === 'NOT IN') {
          if ($values === []) {
            $condition .= $clause['operator'] === 'IN' ? '0 = 1' : '1 = 1';
          } else {
            $column = SqlIdentifier::quotePath($clause['column']);
            $placeholders = \implode(', ', \array_fill(0, \count($values), '?'));
            $condition .= "{$column} {$clause['operator']} ({$placeholders})";
            $params = \array_merge($params, $values);
          }
        } elseif ($clause['operator'] === 'IS NULL' || $clause['operator'] === 'IS NOT NULL') {
          $column = SqlIdentifier::quotePath($clause['column']);
          $condition .= "{$column} {$clause['operator']}";
        } else {
          $column = SqlIdentifier::quotePath($clause['column']);
          $condition .= "{$column} {$clause['operator']} ?";
          $params[] = $values;
        }

        $conditions[] = $condition;
      }

      $sql .= ' WHERE ' . \implode(' ', $conditions);
    }

    if ($include_order && $this->_order_by !== []) {
      $orders = \array_map(
        fn(array $order): string =>
          SqlIdentifier::quotePath($order['column']) . ' ' . $order['direction'],
        $this->_order_by
      );
      $sql .= ' ORDER BY ' . \implode(', ', $orders);
    }

    $limit = $ignore_stored_pagination ? $limit_override : ($limit_override ?? $this->_limit);
    $offset = $ignore_stored_pagination ? null : $this->_offset;

    if ($limit !== null) {
      $sql .= " LIMIT {$limit}";
      if ($offset !== null) {
        $sql .= " OFFSET {$offset}";
      }
    }

    return [$sql, $params];
  }

  /**
   * @return array<object>|false
   */
  private function _fetchMany(string $sql, array $params, string $context): array|false
  {
    $error_snapshot = $this->_snapshotConnectionErrors($this->_conn);
    $rows = $this->_conn->fetchAll($sql, $params);

    if ($rows === false) {
      $this->_importConnectionErrorDelta($this->_conn, $error_snapshot, $context);
      return false;
    }

    if ($rows === []) {
      return [];
    }

    return $this->_hydrateMany($rows);
  }

  private function _runCount(string $context): int|false
  {
    [$sql, $params] = $this->_buildSelect(
      trusted_select: 'COUNT(*) AS cnt',
      include_order: false,
      ignore_stored_pagination: true
    );
    $error_snapshot = $this->_snapshotConnectionErrors($this->_conn);
    $row = $this->_conn->fetchOne($sql, $params);

    if ($row === false) {
      $this->_importConnectionErrorDelta($this->_conn, $error_snapshot, $context);
      return false;
    }

    $count = \filter_var(
      $row['cnt'] ?? null,
      \FILTER_VALIDATE_INT,
      ['options' => ['min_range' => 0]]
    );

    if ($count === false) {
      $this->_systemError($context, 'Database count query returned an invalid result.');
      return false;
    }

    return $count;
  }

  private function _hydrateMany(array $rows): array
  {
    $entities = [];
    $class = $this->_entity_class;

    foreach ($rows as $row) {
      $entities[] = $class::_instantiateFromRow($row, $this->_conn);
    }

    return $entities;
  }
}
