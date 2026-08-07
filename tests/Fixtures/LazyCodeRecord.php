<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Fixtures;

use TimeFrontiers\Helper\DatabaseObject;

final class LazyCodeRecord
{
  use DatabaseObject;

  protected static string $_db_name = 'test_database';
  protected static string $_table_name = 'lazy_records';
  protected static string $_primary_key = 'id';
  protected static array $_db_fields = [];

  public int|string|null $id = null;
  public ?string $code = null;
  public string $name = '';

  public function __construct(mixed ...$arguments)
  {
  }

  public static function resetFixture(): void
  {
    static::$_schema = null;
    static::$_schema_connection = null;
    static::$_static_conn = null;
  }
}

