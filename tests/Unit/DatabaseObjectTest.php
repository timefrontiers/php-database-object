<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\Database\QueryBuilder;
use TimeFrontiers\Database\Schema\TableSchema;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Tests\Fixtures\{LazyCodeRecord, TestRecord};
use TimeFrontiers\Tests\Support\{SchemaRows, ScriptedMySQLiConnection};

final class DatabaseObjectTest extends TestCase
{
  protected function setUp(): void
  {
    TableSchema::clearCache();
    TestRecord::resetFixture();
    LazyCodeRecord::resetFixture();
  }

  public function testCreateFailureImportsOnlyNewConnectionErrors(): void
  {
    [$conn, $driver] = $this->connection();
    $historical = [0, 1205, 'Historical failure.', __FILE__, __LINE__];
    $current = [0, 1062, 'Prepared statement execution failed.', __FILE__, __LINE__];
    $driver->appendError('execute', $historical);
    $driver->queueFetchAll(SchemaRows::standard());
    $driver->queueExecute(false, error: $current, driver_code: 1062, sql_state: '23000');

    $record = new TestRecord();
    $record->setConnection($conn);
    $record->name = 'BOUND-SENTINEL';

    self::assertFalse($record->save());
    self::assertSame([$current], $record->getErrors()['_create']);
    self::assertStringNotContainsString('BOUND-SENTINEL', $record->firstError('_create'));
    self::assertStringNotContainsString('secret-password', $record->firstError('_create'));
  }

  public function testUpdateCannotSucceedAfterFailedExecuteWithZeroAffectedRows(): void
  {
    [$conn, $driver] = $this->connection();
    $driver->queueFetchAll(SchemaRows::standard());
    $driver->queueExecute(false, affected_rows: 0, driver_code: 1213, sql_state: '40001');

    $record = $this->record($conn, 10);
    self::assertFalse($record->save());
    self::assertTrue($record->hasErrors('_update'));
    self::assertStringContainsString('1213', $record->firstError('_update'));
  }

  public function testSuccessfulUnchangedUpdateMayReturnTrue(): void
  {
    [$conn, $driver] = $this->connection();
    $driver->queueFetchAll(SchemaRows::standard());
    $driver->queueExecute(true, affected_rows: 0);

    self::assertTrue($this->record($conn, 10)->save());
  }

  public function testCreateReadsInsertIdOnlyAfterSuccessfulExecute(): void
  {
    [$conn, $driver] = $this->connection();
    $driver->queueFetchAll(SchemaRows::standard());
    $driver->queueExecute(true, affected_rows: 1, insert_id: 42);

    $record = $this->record($conn);
    self::assertTrue($record->save());
    self::assertSame(42, $record->id);
  }

  public function testDeleteRequiresSuccessfulExecuteAndExactlyOneRow(): void
  {
    [$conn, $driver] = $this->connection();
    $record = $this->record($conn, 10);

    $driver->queueExecute(false, affected_rows: 1, driver_code: 1064, sql_state: '42000');
    self::assertFalse($record->delete());
    self::assertTrue($record->hasErrors('_delete'));

    $record->clearErrors();
    $driver->queueExecute(true, affected_rows: 0);
    self::assertFalse($record->delete());
    self::assertFalse($record->hasErrors());

    $driver->queueExecute(true, affected_rows: 1);
    self::assertTrue($record->delete());
  }

  public function testSchemaFailureIsNotCachedOrConvertedToNoDataError(): void
  {
    [$conn, $driver] = $this->connection();
    $failure = [0, 1146, 'Failed to prepare the database statement.', __FILE__, __LINE__];
    $driver->queueFetchAll(false, $failure, 1146, '42S02');
    $driver->queueFetchAll(SchemaRows::standard());
    $driver->queueExecute(true, affected_rows: 1, insert_id: 9);

    $record = $this->record($conn);
    self::assertFalse($record->save());
    self::assertSame('Failed to prepare the database statement.', $record->firstError('_create'));
    self::assertNotSame('No data to insert', $record->firstError('_create'));

    self::assertTrue($record->save());
    self::assertCount(2, $driver->fetches_all);
  }

  public function testHydrationKeepsTheExactReadFacade(): void
  {
    [$static_conn] = $this->connection();
    [$read_conn, $read_driver] = $this->connection();
    TestRecord::useConnection($static_conn);
    $read_driver->queueFetchAll([['id' => 7, 'code' => 'A', 'name' => 'Read']]);

    $builder = new QueryBuilder($read_conn, 'test_database', 'test_records', TestRecord::class);
    $records = $builder->get();

    self::assertIsArray($records);
    self::assertSame($read_conn, $records[0]->conn());
  }

  public function testFindByIdLoadsLazyFieldsBeforeCheckingCode(): void
  {
    [$conn, $driver] = $this->connection();
    LazyCodeRecord::useConnection($conn);
    $driver->queueFetchAll(SchemaRows::standard());
    $driver->queueFetchAll([]);

    self::assertFalse(LazyCodeRecord::findById('REC-7'));
    self::assertCount(2, $driver->fetches_all);
    [$sql, $params] = $driver->fetches_all[1];
    self::assertStringContainsString('`id` = ? OR `code` = ?', $sql);
    self::assertSame(['REC-7', 'REC-7'], $params);
  }

  public function testLazyModelHonorsExplicitSchemaCacheClear(): void
  {
    [$conn, $driver] = $this->connection();
    LazyCodeRecord::useConnection($conn);
    $driver->queueFetchAll(SchemaRows::field('old_field'));
    self::assertSame(['old_field'], LazyCodeRecord::tableFields());

    TableSchema::clearCache('test_database', 'lazy_records');
    $driver->queueFetchAll(SchemaRows::field('new_field'));
    self::assertSame(['new_field'], LazyCodeRecord::tableFields());
  }

  public function testUpgradeConnectionReturnsNonGuestFacadeUnchanged(): void
  {
    [$conn] = $this->connection('APP_USER');
    self::assertSame($conn, TestRecord::upgradeConnection($conn));
  }

  public function testUpgradeConnectionRefusesToReplaceActiveGuestTransaction(): void
  {
    [$conn] = $this->connection('APP_GUEST');
    $conn->beginTransaction();

    $this->expectException(\LogicException::class);
    TestRecord::upgradeConnection($conn);
  }

  public function testUpgradeConnectionFailsClearlyWithoutBootstrapHelpers(): void
  {
    self::assertFalse(\function_exists('get_constant'));
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('bootstrap helpers are unavailable');
    TestRecord::upgradeConnection();
  }

  private function record(SQLDatabase $conn, int|string|null $id = null): TestRecord
  {
    $record = new TestRecord();
    $record->setConnection($conn);
    $record->id = $id;
    $record->code = 'REC-1';
    $record->name = 'Example';
    $record->active = true;
    return $record;
  }

  /** @return array{SQLDatabase, ScriptedMySQLiConnection} */
  private function connection(string $user = 'TEST_USER'): array
  {
    $driver = new ScriptedMySQLiConnection($user);
    return [SQLDatabase::fromConnection($driver), $driver];
  }
}
