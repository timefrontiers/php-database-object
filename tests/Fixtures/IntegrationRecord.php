<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Fixtures;

use TimeFrontiers\Helper\DatabaseObject;
use TimeFrontiers\SQLDatabase;

final class IntegrationRecord
{
  use DatabaseObject;

  protected static string $_db_name = '';
  protected static string $_table_name = '';
  protected static string $_primary_key = 'id';
  protected static array $_db_fields = [];

  public ?int $id = null;
  public string $code = '';
  public string $name = '';
  protected ?string $_created = null;
  protected ?string $_updated = null;

  public function __construct(mixed ...$arguments)
  {
  }

  public static function configure(string $database, string $table, SQLDatabase $conn): void
  {
    static::$_db_name = $database;
    static::$_table_name = $table;
    static::$_schema = null;
    static::$_schema_connection = null;
    static::useConnection($conn);
  }
}

