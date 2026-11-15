<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Tests;

use Golovanov\AlertLoop\Database;
use Golovanov\AlertLoop\Tests\Support\DatabaseTestCase;

final class DatabaseTest extends DatabaseTestCase
{
    public function testMigrationIsRepeatableAndCreatesUtf8mb4Tables(): void
    {
        Database::migrate($this->pdo(), self::PREFIX);

        $statement = $this->pdo()->prepare(
            "SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'alt\\_%' ORDER BY TABLE_NAME"
        );
        $statement->execute();
        $tables = $statement->fetchAll(\PDO::FETCH_KEY_PAIR);

        self::assertSame([
            'alt_incidents' => 'utf8mb4_unicode_ci',
            'alt_outbox' => 'utf8mb4_unicode_ci',
            'alt_runs' => 'utf8mb4_unicode_ci',
            'alt_schema' => 'utf8mb4_unicode_ci',
        ], $tables);
        self::assertSame(Database::SCHEMA_VERSION, (int) $this->scalar('SELECT version FROM alt_schema'));
    }

    public function testSessionIsStrict(): void
    {
        $mode = (string) $this->scalar('SELECT @@SESSION.sql_mode');

        self::assertStringContainsString('STRICT_TRANS_TABLES', $mode);
        self::assertStringContainsString('ONLY_FULL_GROUP_BY', $mode);
    }
}
