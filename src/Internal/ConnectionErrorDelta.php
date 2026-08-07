<?php

declare(strict_types=1);

namespace TimeFrontiers\Internal;

use TimeFrontiers\SQLDatabase;

final class ConnectionErrorDelta
{
  /**
   * @return array<string, int>
   */
  public static function snapshot(SQLDatabase $conn): array
  {
    $counts = [];

    foreach ($conn->getErrors() as $context => $errors) {
      if (\is_array($errors)) {
        $counts[(string)$context] = \count($errors);
      }
    }

    return $counts;
  }

  /**
   * @param array<string, int> $snapshot
   * @return list<array>
   */
  public static function appended(SQLDatabase $conn, array $snapshot): array
  {
    $appended = [];

    foreach ($conn->getErrors() as $context => $errors) {
      if (!\is_array($errors)) {
        continue;
      }

      $offset = $snapshot[(string)$context] ?? 0;
      if (\count($errors) <= $offset) {
        continue;
      }

      foreach (\array_slice($errors, $offset) as $error) {
        if (\is_array($error)) {
          $appended[] = $error;
        }
      }
    }

    return $appended;
  }
}

