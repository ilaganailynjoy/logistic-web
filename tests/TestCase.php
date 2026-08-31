<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    /**
     * Bootstrap the MySQL test schema (idempotent) BEFORE Laravel begins the
     * per-test transaction (DDL would otherwise commit mid-transaction). The
     * presence check runs on every setUp so the schema self-heals even when a
     * sibling suite (e.g. RefreshDatabase) wipes the shared test DB mid-run.
     */
    protected function setUp(): void
    {
        $this->loadSchemaViaRawPdo();

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

        if ($this->schemaPresent($pdo)) {
            return;
        }

        $schemaFile = dirname(__DIR__) . '/database/changes/invoizdb_schema.sql';

        if (! file_exists($schemaFile)) {
            $this->fail('Schema dump missing: ' . $schemaFile);
        }

        $sql = file_get_contents($schemaFile);

        // Rebuild cleanly: some test suites (e.g. RefreshDatabase) run
        // `migrate:fresh` which wipes the shared test database and recreates
        // only framework tables. Drop whatever remains so the full canonical
        // schema (users, riders, logistics_centers, deliveries, ...) is
        // restored deterministically via the single source of truth.
        preg_match_all('/CREATE TABLE\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $matches);
        $tables = array_unique($matches[1] ?? []);
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }

        $statements = array_filter(array_map('trim', explode(";\n", $sql)));

        foreach ($statements as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }

    private function schemaPresent(\PDO $pdo): bool
    {
        // Guard on a representative custom table. A framework `users` table
        // (or one recreated by a partial migration) is not sufficient — the
        // Logistics/Rider schema depends on riders, logistics_centers, etc.
        foreach (['riders', 'logistics_centers', 'deliveries'] as $table) {
            if (! $pdo->query("SHOW TABLES LIKE \"{$table}\"")->fetch()) {
                return false;
            }
        }

        return true;
    }
}