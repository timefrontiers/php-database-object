<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\Database\Schema\TableSchema;
use TimeFrontiers\Exceptions\SchemaException;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Tests\Support\{SchemaRows, ScriptedMySQLiConnection};

final class TableSchemaTest extends TestCase
{
  protected function setUp(): void
  {
    TableSchema::clearCache();
  }

  public function testFailedSchemaLoadIsNotCached(): void
  {
    [$conn, $driver] = $this->connection();
    $driver->queueFetchAll(false);

    try {
      new TableSchema($conn, 'same_database', 'same_table', 'id');
      self::fail('Schema failure was not reported.');
    } catch (SchemaException) {
    }

    $driver->queueFetchAll(SchemaRows::field('recovered'));
    $schema = new TableSchema($conn, 'same_database', 'same_table', 'id');
    self::assertSame(['recovered'], $schema->getFields());
    self::assertCount(2, $driver->fetches_all);
  }

  public function testCacheDoesNotCrossConnectionIdentities(): void
  {
    [$first_conn, $first_driver] = $this->connection();
    [$second_conn, $second_driver] = $this->connection();
    $first_driver->queueFetchAll(SchemaRows::field('first_field'));
    $second_driver->queueFetchAll(SchemaRows::field('second_field'));

    $first = new TableSchema($first_conn, 'same_database', 'same_table', 'id');
    $second = new TableSchema($second_conn, 'same_database', 'same_table', 'id');

    self::assertSame(['first_field'], $first->getFields());
    self::assertSame(['second_field'], $second->getFields());
  }

  public function testSuccessfulSchemaIsReusedOnSameConnection(): void
  {
    [$conn, $driver] = $this->connection();
    $driver->queueFetchAll(SchemaRows::field('cached'));

    new TableSchema($conn, 'same_database', 'same_table', 'id');
    $schema = new TableSchema($conn, 'same_database', 'same_table', 'other_id');

    self::assertSame(['cached'], $schema->getFields());
    self::assertSame('other_id', $schema->getPrimaryKey());
    self::assertCount(1, $driver->fetches_all);
  }

  public function testExplicitPrimaryKeyDoesNotPoisonCachedMetadata(): void
  {
    [$conn, $driver] = $this->connection();
    $driver->queueFetchAll(SchemaRows::field('id', 'bigint'));
    $override = new TableSchema($conn, 'same_database', 'same_table', 'external_id');
    self::assertSame('external_id', $override->getPrimaryKey());

    $driver->queueFetchOne(['Column_name' => 'id']);
    $discovered = new TableSchema($conn, 'same_database', 'same_table');
    self::assertSame('id', $discovered->getPrimaryKey());
    self::assertCount(1, $driver->fetches_one);
  }

  public function testClearCacheRemovesTableAcrossConnections(): void
  {
    [$first_conn, $first_driver] = $this->connection();
    [$second_conn, $second_driver] = $this->connection();
    $first_driver->queueFetchAll(SchemaRows::field('old_first'));
    $second_driver->queueFetchAll(SchemaRows::field('old_second'));
    new TableSchema($first_conn, 'same_database', 'same_table', 'id');
    new TableSchema($second_conn, 'same_database', 'same_table', 'id');

    TableSchema::clearCache('same_database', 'same_table');
    $first_driver->queueFetchAll(SchemaRows::field('new_first'));
    $second_driver->queueFetchAll(SchemaRows::field('new_second'));

    self::assertSame(
      ['new_first'],
      (new TableSchema($first_conn, 'same_database', 'same_table', 'id'))->getFields()
    );
    self::assertSame(
      ['new_second'],
      (new TableSchema($second_conn, 'same_database', 'same_table', 'id'))->getFields()
    );
  }

  /** @return array{SQLDatabase, ScriptedMySQLiConnection} */
  private function connection(): array
  {
    $driver = new ScriptedMySQLiConnection();
    return [SQLDatabase::fromConnection($driver), $driver];
  }
}

