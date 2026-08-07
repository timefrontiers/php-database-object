<?php

declare(strict_types=1);

namespace TimeFrontiers\Internal;

final class SqlIdentifier
{
  private const SEGMENT_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_$]*\z/D';

  public static function quote(string $identifier): string
  {
    self::assertPlain($identifier);
    return '`' . $identifier . '`';
  }

  public static function quotePath(string $identifier): string
  {
    $segments = \explode('.', $identifier);
    if ($segments === []) {
      throw new \InvalidArgumentException('SQL identifier cannot be empty.');
    }

    return \implode('.', \array_map(self::quote(...), $segments));
  }

  public static function quoteTable(string $database, string $table): string
  {
    return self::quote($database) . '.' . self::quote($table);
  }

  public static function assertPlain(string $identifier): void
  {
    if (!\preg_match(self::SEGMENT_PATTERN, $identifier)) {
      throw new \InvalidArgumentException('Unsupported SQL identifier.');
    }
  }
}

