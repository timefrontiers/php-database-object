<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Integration\MySQLi;

use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Tests\Support\{
  AbstractDatabaseObjectIntegrationTestCase,
  DatabaseFactory,
  IntegrationConfig
};

final class DatabaseObjectTest extends AbstractDatabaseObjectIntegrationTestCase
{
  protected function createDatabase(IntegrationConfig $config): SQLDatabase
  {
    return DatabaseFactory::mysqli($config);
  }
}

