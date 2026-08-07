<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Fixtures;

use TimeFrontiers\AccessGroup;
use TimeFrontiers\Helper\DatabaseObject;
use TimeFrontiers\SQLDatabase;

class TestRecord
{
  use DatabaseObject;

  protected static string $_db_name = 'test_database';
  protected static string $_table_name = 'test_records';
  protected static string $_primary_key = 'id';
  protected static array $_db_fields = ['id', 'code', 'name', 'note', 'active'];

  public int|string|null $id = null;
  public ?string $code = null;
  public string $name = '';
  public ?string $note = null;
  public bool|int $active = false;

  public function __construct(mixed ...$arguments)
  {
  }

  public static function resetFixture(): void
  {
    static::$_schema = null;
    static::$_schema_connection = null;
    static::$_static_conn = null;
  }

  public static function upgradeConnection(
    ?SQLDatabase $conn = null,
    AccessGroup $access_group = AccessGroup::USER
  ): SQLDatabase {
    return static::_upgradeConn($conn, $access_group);
  }
}
