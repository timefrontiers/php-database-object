<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\Database\QueryBuilder;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Tests\Fixtures\TestRecord;
use TimeFrontiers\Tests\Support\ScriptedMySQLiConnection;

final class QueryBuilderTest extends TestCase
{
  public function testGetDistinguishesSuccessfulEmptyResultFromFailure(): void
  {
    [$empty, $empty_driver] = $this->builder();
    $empty_driver->queueFetchAll([]);
    self::assertSame([], $empty->get());
    self::assertFalse($empty->hasErrors());

    [$failed, $failed_driver] = $this->builder();
    $error = [0, 1064, 'Prepared statement execution failed.', __FILE__, __LINE__];
    $failed_driver->queueFetchAll(false, $error, 1064, '42000');
    self::assertFalse($failed->get());
    self::assertSame([$error], $failed->getErrors()['get']);
  }

  public function testFirstFailureIsDistinguishableFromNoRow(): void
  {
    [$empty, $empty_driver] = $this->builder();
    $empty_driver->queueFetchAll([]);
    self::assertFalse($empty->first());
    self::assertFalse($empty->hasErrors());

    [$failed, $failed_driver] = $this->builder();
    $failed_driver->queueFetchAll(false, driver_code: 2006, sql_state: 'HY000');
    self::assertFalse($failed->first());
    self::assertTrue($failed->hasErrors('first'));
  }

  public function testCountDistinguishesZeroFromFailureAndInvalidResult(): void
  {
    [$zero, $zero_driver] = $this->builder();
    $zero_driver->queueFetchOne(['cnt' => '0']);
    self::assertSame(0, $zero->count());

    [$failed, $failed_driver] = $this->builder();
    $failed_driver->queueFetchOne(false, driver_code: 1205, sql_state: 'HY000');
    self::assertFalse($failed->count());
    self::assertTrue($failed->hasErrors('count'));

    [$invalid, $invalid_driver] = $this->builder();
    $invalid_driver->queueFetchOne(['cnt' => 'not-a-count']);
    self::assertFalse($invalid->count());
    self::assertTrue($invalid->hasErrors('count'));
  }

  public function testCountExistsAndFirstDoNotMutateBuilderState(): void
  {
    [$builder, $driver] = $this->builder();
    $builder->select('id', 'name')->where('active', true)->orderBy('name')->limit(5)->offset(2);
    $before = $builder->toSql();

    $driver->queueFetchOne(['cnt' => 3]);
    self::assertSame(3, $builder->count());
    self::assertSame($before, $builder->toSql());

    $driver->queueFetchOne(['cnt' => 1]);
    self::assertTrue($builder->exists());
    self::assertSame($before, $builder->toSql());

    $driver->queueFetchAll([]);
    self::assertFalse($builder->first());
    self::assertSame($before, $builder->toSql());
  }

  public function testExistsFailureIsInspectableWithoutChangingBooleanContract(): void
  {
    [$builder, $driver] = $this->builder();
    $driver->queueFetchOne(false, driver_code: 2006, sql_state: 'HY000');

    self::assertFalse($builder->exists());
    self::assertTrue($builder->hasErrors('exists'));
  }

  public function testExplicitThreeArgumentNullIsNotReinterpreted(): void
  {
    [$builder] = $this->builder();
    [$sql, $params] = $builder->where('note', '!=', null)->toSql();

    self::assertStringContainsString('`note` != ?', $sql);
    self::assertSame([null], $params);
  }

  public function testEmptyInAndNotInCompileToBooleanConstants(): void
  {
    [$builder] = $this->builder();
    [$sql, $params] = $builder
      ->whereIn('id', [])
      ->whereNotIn('code', [])
      ->toSql();

    self::assertStringContainsString('WHERE 0 = 1 AND 1 = 1', $sql);
    self::assertSame([], $params);
  }

  public function testIdentifiersAreQuotedAndValuesRemainBound(): void
  {
    [$builder] = $this->builder();
    [$sql, $params] = $builder
      ->select('id', 'test_records.name')
      ->where('active', '<=>', 1)
      ->orderByDesc('name')
      ->toSql();

    self::assertSame(
      'SELECT `id`, `test_records`.`name` FROM `test_database`.`test_records` ' .
      'WHERE `active` <=> ? ORDER BY `name` DESC',
      $sql
    );
    self::assertSame([1], $params);
  }

  public function testUnsupportedOperatorIsRejectedBeforeExecution(): void
  {
    [$builder, $driver] = $this->builder();
    try {
      $builder->where('id', '= 1 OR 1=1 --', 7);
      self::fail('Unsupported operator was accepted.');
    } catch (\InvalidArgumentException) {
      self::assertSame([], $driver->fetches_all);
    }
  }

  public function testRawSelectExpressionIsRejected(): void
  {
    [$builder] = $this->builder();
    $this->expectException(\InvalidArgumentException::class);
    $builder->select('COUNT(*) AS cnt');
  }

  /** @dataProvider negativePaginationProvider */
  public function testNegativePaginationIsRejected(string $method): void
  {
    [$builder] = $this->builder();
    $this->expectException(\InvalidArgumentException::class);
    $builder->$method(-1);
  }

  public static function negativePaginationProvider(): array
  {
    return [['limit'], ['offset'], ['take']];
  }

  /** @return array{QueryBuilder, ScriptedMySQLiConnection, SQLDatabase} */
  private function builder(): array
  {
    $driver = new ScriptedMySQLiConnection();
    $conn = SQLDatabase::fromConnection($driver);
    return [
      new QueryBuilder($conn, 'test_database', 'test_records', TestRecord::class),
      $driver,
      $conn,
    ];
  }
}
