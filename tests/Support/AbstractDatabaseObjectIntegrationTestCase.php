<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Support;

use TimeFrontiers\Database\Schema\TableSchema;
use TimeFrontiers\Tests\Fixtures\IntegrationRecord;

abstract class AbstractDatabaseObjectIntegrationTestCase extends AbstractIntegrationTestCase
{
  public function testCrudHydrationAndUnchangedUpdate(): void
  {
    $record = $this->newRecord('CRUD-' . \bin2hex(\random_bytes(4)));
    self::assertTrue($record->save());
    self::assertIsInt($record->id);

    $schema = new TableSchema($this->database, $this->config->database, $this->table);
    self::assertSame('id', $schema->getPrimaryKey());

    $hydrated = IntegrationRecord::findById($record->id);
    self::assertInstanceOf(IntegrationRecord::class, $hydrated);
    self::assertSame($this->database, $hydrated->conn());

    $hydrated->name = 'Updated';
    self::assertTrue($hydrated->save());
    self::assertTrue($hydrated->save(), 'An unchanged update must remain successful.');
    self::assertTrue($hydrated->delete());
    self::assertFalse(IntegrationRecord::findById($record->id));
  }

  public function testDuplicateKeyFailureIsAvailableOnTheModel(): void
  {
    $code = 'DUP-' . \bin2hex(\random_bytes(4));
    self::assertTrue($this->newRecord($code)->save());

    $duplicate = $this->newRecord($code, 'Duplicate');
    self::assertFalse($duplicate->save());
    self::assertTrue($duplicate->hasErrors('_create'));
    self::assertNotEmpty($duplicate->firstError('_create'));
    self::assertStringNotContainsString($code, $duplicate->firstError('_create'));
    if ($this->config->password !== '') {
      self::assertStringNotContainsString($this->config->password, $duplicate->firstError('_create'));
    }
  }
}
