<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Integration\MySQLi;

use PHPUnit\Framework\Attributes\Group;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Tests\Support\{
  AbstractTransactionIntegrationTestCase,
  DatabaseFactory,
  IntegrationConfig
};

#[Group('transaction')]
final class TransactionBoundConnectionTest extends AbstractTransactionIntegrationTestCase
{
  protected function createDatabase(IntegrationConfig $config): SQLDatabase
  {
    return DatabaseFactory::mysqli($config);
  }
}
