<?php

declare(strict_types=1);

namespace TimeFrontiers\Internal;

use TimeFrontiers\SQLDatabase;

trait ImportsConnectionErrors
{
  /**
   * @return array<string, int>
   */
  protected function _snapshotConnectionErrors(SQLDatabase $conn): array
  {
    return ConnectionErrorDelta::snapshot($conn);
  }

  /**
   * @param array<string, int> $snapshot
   */
  protected function _importConnectionErrorDelta(
    SQLDatabase $conn,
    array $snapshot,
    string $context,
    string $fallback = 'Database operation failed.'
  ): void {
    $imported = false;

    foreach (ConnectionErrorDelta::appended($conn, $snapshot) as $error) {
      if (\count($error) !== 5) {
        continue;
      }

      $this->_errors[$context][] = $error;
      $imported = true;
    }

    if ($imported) {
      return;
    }

    $details = [];
    $driver_code = $conn->lastErrorCode();
    $sql_state = $conn->lastSqlState();

    if ($driver_code !== null && $driver_code !== '') {
      $details[] = 'code ' . (string)$driver_code;
    }
    if ($sql_state !== null && $sql_state !== '') {
      $details[] = 'SQLSTATE ' . $sql_state;
    }

    if ($details !== []) {
      $fallback .= ' (' . \implode('; ', $details) . ')';
    }

    $this->_systemError($context, $fallback);
  }
}

