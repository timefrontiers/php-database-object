<?php

declare(strict_types=1);

namespace TimeFrontiers\Tests\Support;

use PHPUnit\Framework\TestCase;
use TimeFrontiers\Database\Schema\TableSchema;
use TimeFrontiers\Internal\SqlIdentifier;
use TimeFrontiers\SQLDatabase;
use TimeFrontiers\Tests\Fixtures\IntegrationRecord;

abstract class AbstractIntegrationTestCase extends TestCase
{
  protected IntegrationConfig $config;
  protected SQLDatabase $database;
  protected SQLDatabase $observer;
  protected string $table;

  abstract protected function createDatabase(IntegrationConfig $config): SQLDatabase;

  protected function setUp(): void
  {
    if (!IntegrationConfig::isConfigured()) {
      self::markTestSkipped('MySQL integration environment is not configured.');
    }

    $this->config = IntegrationConfig::fromEnvironment();
    $this->database = $this->createDatabase($this->config);
    $this->observer = $this->createDatabase($this->config);
    $this->table = 'tf_dbo_' . \strtolower((new \ReflectionClass($this))->getShortName())
      . '_' . \bin2hex(\random_bytes(5));

    $table = SqlIdentifier::quoteTable($this->config->database, $this->table);
    $sql = "CREATE TABLE {$table} (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      `code` VARCHAR(64) NOT NULL,
      `name` VARCHAR(191) NOT NULL,
      `_created` DATETIME NULL,
      `_updated` DATETIME NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_code` (`code`)
    ) ENGINE=InnoDB";

    if ($this->database->execute($sql) === false) {
      throw new \RuntimeException('Failed to create the disposable integration table.');
    }

    IntegrationRecord::configure($this->config->database, $this->table, $this->database);
  }

  protected function tearDown(): void
  {
    if (!isset($this->database, $this->config, $this->table)) {
      return;
    }

    foreach ([$this->database, $this->observer] as $conn) {
      while ($conn->transactionDepth() > 0) {
        $conn->rollBack();
      }
    }

    $table = SqlIdentifier::quoteTable($this->config->database, $this->table);
    $this->database->execute("DROP TABLE IF EXISTS {$table}");
    TableSchema::clearCache($this->config->database, $this->table);
  }

  protected function newRecord(string $code, string $name = 'Example'): IntegrationRecord
  {
    $record = new IntegrationRecord();
    $record->setConnection($this->database);
    $record->code = $code;
    $record->name = $name;
    return $record;
  }

  protected function observerCount(): int
  {
    $table = SqlIdentifier::quoteTable($this->config->database, $this->table);
    $row = $this->observer->fetchOne("SELECT COUNT(*) AS cnt FROM {$table}");
    if ($row === false || !isset($row['cnt'])) {
      throw new \RuntimeException('Observer count query failed.');
    }

    return (int)$row['cnt'];
  }
}

