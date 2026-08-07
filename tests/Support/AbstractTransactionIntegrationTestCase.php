<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Support;

use PHPUnit\Framework\Attributes\Group;
use TimeFrontiers\Exceptions\TransactionException;
use TimeFrontiers\Tests\Fixtures\IntegrationRecord;

#[Group('transaction')]
abstract class AbstractTransactionIntegrationTestCase extends AbstractIntegrationTestCase
{
  public function testCallerFacadeRemainsBoundAndCallerRollbackRemovesWrite(): void
  {
    $code = 'TX-' . \bin2hex(\random_bytes(4));

    try {
      $this->database->transaction(function ($database) use ($code): void {
        IntegrationRecord::configure($this->config->database, $this->table, $database);
        $record = $this->newRecord($code);

        self::assertSame(1, $database->transactionDepth());
        self::assertTrue($record->save());
        self::assertSame(1, $database->transactionDepth());

        $hydrated = IntegrationRecord::findById($record->id);
        self::assertInstanceOf(IntegrationRecord::class, $hydrated);
        self::assertSame($database, $hydrated->conn());
        self::assertSame(0, $this->observerCount());

        throw new \RuntimeException('Repository rollback request.');
      });
      self::fail('The repository exception should leave the callback.');
    } catch (\RuntimeException $exception) {
      self::assertSame('Repository rollback request.', $exception->getMessage());
    }

    self::assertSame(0, $this->database->transactionDepth());
    self::assertSame(0, $this->observerCount());
  }

  public function testStatementFailureMarksScopeRollbackOnlyWithoutModelOwnership(): void
  {
    $code = 'ROLLBACK-' . \bin2hex(\random_bytes(4));
    self::assertTrue($this->newRecord($code)->save());

    try {
      $this->database->transaction(function ($database) use ($code): void {
        $duplicate = $this->newRecord($code, 'Duplicate');
        self::assertFalse($duplicate->save());
        self::assertSame(1, $database->transactionDepth());
      });
      self::fail('A rollback-only scope should not commit.');
    } catch (TransactionException $exception) {
      self::assertStringContainsString('statement failed', $exception->getMessage());
    }

    self::assertSame(0, $this->database->transactionDepth());
    self::assertSame(1, $this->observerCount());
  }

  public function testNestedTransactionDepthIsNotDisturbedByModelOperations(): void
  {
    $code = 'NESTED-' . \bin2hex(\random_bytes(4));

    $this->database->transaction(function ($database) use ($code): void {
      self::assertSame(1, $database->transactionDepth());

      $database->transaction(function ($nested) use ($code): void {
        self::assertSame(2, $nested->transactionDepth());
        $record = $this->newRecord($code);
        self::assertTrue($record->save());
        self::assertSame(2, $nested->transactionDepth());
      });

      self::assertSame(1, $database->transactionDepth());
    });

    self::assertSame(0, $this->database->transactionDepth());
    self::assertSame(1, $this->observerCount());
  }
}

