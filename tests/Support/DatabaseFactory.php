<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Support;

use TimeFrontiers\SQLDatabase;

final class DatabaseFactory
{
  public static function mysqli(IntegrationConfig $config): SQLDatabase
  {
    return new SQLDatabase(
      $config->host,
      $config->user,
      $config->password,
      $config->database,
      true,
      (string)$config->port
    );
  }

  public static function pdo(IntegrationConfig $config): SQLDatabase
  {
    return SQLDatabase::pdo(
      'mysql',
      $config->host,
      $config->port,
      $config->database,
      $config->user,
      $config->password,
      [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
    );
  }
}

