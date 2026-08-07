<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Support;

final class SchemaRows
{
  public static function standard(): array
  {
    return [
      ['COLUMN_NAME' => 'id', 'DATA_TYPE' => 'bigint', 'CHARACTER_MAXIMUM_LENGTH' => null],
      ['COLUMN_NAME' => 'code', 'DATA_TYPE' => 'varchar', 'CHARACTER_MAXIMUM_LENGTH' => 64],
      ['COLUMN_NAME' => 'name', 'DATA_TYPE' => 'varchar', 'CHARACTER_MAXIMUM_LENGTH' => 191],
      ['COLUMN_NAME' => 'note', 'DATA_TYPE' => 'text', 'CHARACTER_MAXIMUM_LENGTH' => 65535],
      ['COLUMN_NAME' => 'active', 'DATA_TYPE' => 'tinyint', 'CHARACTER_MAXIMUM_LENGTH' => null],
    ];
  }

  public static function field(string $name, string $type = 'varchar'): array
  {
    return [[
      'COLUMN_NAME' => $name,
      'DATA_TYPE' => $type,
      'CHARACTER_MAXIMUM_LENGTH' => $type === 'varchar' ? 191 : null,
    ]];
  }
}

