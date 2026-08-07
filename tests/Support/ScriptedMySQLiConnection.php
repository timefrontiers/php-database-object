<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Support;

use TimeFrontiers\MySQLiDatabase;

final class ScriptedMySQLiConnection extends MySQLiDatabase
{
  private array $execute_queue = [];
  private array $fetch_all_queue = [];
  private array $fetch_one_queue = [];
  private array $errors = [];
  private int $affected_rows = 0;
  private int|string $insert_id = 0;
  private int $transaction_depth = 0;
  private int|string|null $last_error_code = null;
  private ?string $last_sql_state = null;

  public array $executions = [];
  public array $fetches_all = [];
  public array $fetches_one = [];

  public function __construct(private string $user = 'TEST_USER')
  {
  }

  public function queueExecute(
    bool $result,
    int $affected_rows = 0,
    int|string $insert_id = 0,
    ?array $error = null,
    int|string|null $driver_code = null,
    ?string $sql_state = null
  ): void {
    $this->execute_queue[] = \compact(
      'result',
      'affected_rows',
      'insert_id',
      'error',
      'driver_code',
      'sql_state'
    );
  }

  public function queueFetchAll(
    array|false $result,
    ?array $error = null,
    int|string|null $driver_code = null,
    ?string $sql_state = null
  ): void {
    $this->fetch_all_queue[] = \compact('result', 'error', 'driver_code', 'sql_state');
  }

  public function queueFetchOne(
    array|false $result,
    ?array $error = null,
    int|string|null $driver_code = null,
    ?string $sql_state = null
  ): void {
    $this->fetch_one_queue[] = \compact('result', 'error', 'driver_code', 'sql_state');
  }

  public function appendError(string $context, array $error): void
  {
    $this->errors[$context][] = $error;
  }

  public function execute(string $sql, array $params = []): \mysqli_result|bool
  {
    $this->executions[] = [$sql, $params];
    $entry = \array_shift($this->execute_queue);
    if ($entry === null) {
      throw new \RuntimeException('No scripted execute result is available.');
    }

    $this->affected_rows = $entry['affected_rows'];
    $this->insert_id = $entry['insert_id'];
    $this->_applyFailure($entry, 'execute');
    return $entry['result'];
  }

  public function fetchAll(string $sql, array $params = []): array|false
  {
    $this->fetches_all[] = [$sql, $params];
    $entry = \array_shift($this->fetch_all_queue);
    if ($entry === null) {
      throw new \RuntimeException('No scripted fetchAll result is available.');
    }

    $this->_applyFailure($entry, 'execute');
    return $entry['result'];
  }

  public function fetchOne(string $sql, array $params = []): array|false
  {
    $this->fetches_one[] = [$sql, $params];
    $entry = \array_shift($this->fetch_one_queue);
    if ($entry === null) {
      throw new \RuntimeException('No scripted fetchOne result is available.');
    }

    $this->_applyFailure($entry, 'execute');
    return $entry['result'];
  }

  public function affectedRows(): int
  {
    return $this->affected_rows;
  }

  public function insertId(): int|string
  {
    return $this->insert_id;
  }

  public function getUser(): string
  {
    return $this->user;
  }

  public function getErrors(): array
  {
    return $this->errors;
  }

  public function lastErrorCode(): int|string|null
  {
    return $this->last_error_code;
  }

  public function lastSqlState(): ?string
  {
    return $this->last_sql_state;
  }

  public function beginTransaction(): bool
  {
    ++$this->transaction_depth;
    return true;
  }

  public function commit(): bool
  {
    if ($this->transaction_depth === 0) {
      return false;
    }
    --$this->transaction_depth;
    return true;
  }

  public function rollBack(): bool
  {
    if ($this->transaction_depth === 0) {
      return false;
    }
    --$this->transaction_depth;
    return true;
  }

  public function inTransaction(): bool
  {
    return $this->transaction_depth > 0;
  }

  public function transactionDepth(): int
  {
    return $this->transaction_depth;
  }

  public function transaction(callable $callback): mixed
  {
    $this->beginTransaction();
    try {
      $result = $callback($this);
      $this->commit();
      return $result;
    } catch (\Throwable $exception) {
      $this->rollBack();
      throw $exception;
    }
  }

  private function _applyFailure(array $entry, string $context): void
  {
    $this->last_error_code = $entry['driver_code'];
    $this->last_sql_state = $entry['sql_state'];

    if ($entry['error'] !== null) {
      $this->errors[$context][] = $entry['error'];
    }
  }
}

