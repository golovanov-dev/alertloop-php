<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Tests\Support;

use Golovanov\AlertLoop\Database;
use PHPUnit\Framework\TestCase;

/**
 * Tests against a real MySQL. Connection from ALERTLOOP_TEST_DB_* variables;
 * tables use the alt_ prefix and are recreated for every test.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected const PREFIX = 'alt_';

    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        foreach (['schema', 'incidents', 'outbox', 'runs'] as $table) {
            $this->pdo()->exec('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
        }
        Database::migrate($this->pdo(), self::PREFIX);
        FakeApi::reset();
    }

    /**
     * @return array{host: string, port: int, name: string, user: string, password: string}
     */
    protected static function db(): array
    {
        return [
            'host' => self::env('ALERTLOOP_TEST_DB_HOST', '127.0.0.1'),
            'port' => (int) self::env('ALERTLOOP_TEST_DB_PORT', '3306'),
            'name' => self::env('ALERTLOOP_TEST_DB_NAME', 'alertloop_test'),
            'user' => self::env('ALERTLOOP_TEST_DB_USER', 'root'),
            'password' => self::env('ALERTLOOP_TEST_DB_PASSWORD', ''),
        ];
    }

    protected function pdo(): \PDO
    {
        return $this->pdo ??= Database::connect(self::db());
    }

    /**
     * Channels on the fake API: tg accepts, tg_down answers 500, tg_spare accepts.
     *
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    protected function config(array $override = []): array
    {
        $api = FakeApi::url();

        return array_replace([
            'db' => self::db(),
            'table_prefix' => self::PREFIX,
            'source' => 'example.com',
            'language' => 'en',
            'timezone' => 'UTC',
            'channels' => [
                'tg' => ['type' => 'telegram', 'token' => 'main', 'api_base' => $api],
                'tg_down' => ['type' => 'telegram', 'token' => 'status-500', 'api_base' => $api],
                'tg_spare' => ['type' => 'telegram', 'token' => 'spare', 'api_base' => $api],
            ],
            'alerts' => ['to' => [['tg', '100']]],
        ], $override);
    }

    /**
     * Moves a time column of every row back, as if $minutes had passed.
     */
    protected function age(string $column, int $minutes, string $table = 'incidents'): void
    {
        $this->pdo()->exec(
            'UPDATE ' . self::PREFIX . "{$table} SET {$column} = {$column} - INTERVAL {$minutes} MINUTE"
        );
    }

    protected function scalar(string $sql): mixed
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute();

        return $statement->fetchColumn();
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false ? $default : $value;
    }
}
