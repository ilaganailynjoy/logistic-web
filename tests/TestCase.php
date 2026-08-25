<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    private static bool $schemaLoaded = false;

    /**
     * Bootstrap the MySQL test schema once, BEFORE Laravel begins the
     * per-test transaction (DDL would otherwise commit mid-transaction).
     */
    protected function setUp(): void
    {
        if (! self::$schemaLoaded) {
            $this->loadSchemaViaRawPdo();
            self::$schemaLoaded = true;
        }

        parent::setUp();
    }

    protected function loadSchemaViaRawPdo(): void
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $database = getenv('DB_DATABASE') ?: 'invoizdb_test';
        $username = getenv('DB_USERNAME') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';

        $pdo = new \PDO(
            "mysql:host={$host};port={$port};dbname={$database}",
            $username,
            $password
        );

        $hasUsers = $pdo->query('SHOW TABLES LIKE "users"')->fetch();

        if ($hasUsers) {
            return;
        }

        $schemaFile = dirname(__DIR__) . '/database/changes/invoizdb_schema.sql';

        if (! file_exists($schemaFile)) {
            $this->fail('Schema dump missing: ' . $schemaFile);
        }

        $sql = file_get_contents($schemaFile);
        $statements = array_filter(array_map('trim', explode(";\n", $sql)));

        foreach ($statements as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }
}