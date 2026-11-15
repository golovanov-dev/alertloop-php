<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop;

/**
 * The library's own MySQL connection and schema. Every statement here runs on
 * both MySQL 5.7 and 8.4.
 *
 * @internal
 */
final class Database
{
    public const SCHEMA_VERSION = 1;

    private const SQL_MODE = 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_IN_DATE,NO_ZERO_DATE,'
        . 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string} $db
     */
    public static function connect(array $db): \PDO
    {
        $pdo = new \PDO(
            'mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';dbname=' . $db['name'] . ';charset=utf8mb4',
            $db['user'],
            $db['password'],
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_TIMEOUT => 5,
            ],
        );
        $pdo->exec("SET SESSION sql_mode = '" . self::SQL_MODE . "'");

        return $pdo;
    }

    /**
     * Creates missing tables and records the schema version. Safe to run on
     * every start and from two processes at once.
     */
    public static function migrate(\PDO $pdo, string $prefix): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}schema (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            version INT UNSIGNED NOT NULL
        ) " . self::TABLE_OPTIONS);

        $statement = $pdo->prepare("SELECT MAX(version) FROM {$prefix}schema");
        $statement->execute();
        $version = (int) $statement->fetchColumn();
        if ($version >= self::SCHEMA_VERSION) {
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}incidents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            dedupe_key VARCHAR(191) NOT NULL,
            open_key VARCHAR(191) NULL,
            status VARCHAR(16) NOT NULL,
            severity VARCHAR(16) NOT NULL,
            title TEXT NOT NULL,
            text TEXT NOT NULL,
            fields LONGTEXT NOT NULL,
            occurrences INT UNSIGNED NOT NULL DEFAULT 1,
            opened_at DATETIME(3) NOT NULL,
            last_seen_at DATETIME(3) NOT NULL,
            notified_at DATETIME(3) NULL,
            resolved_at DATETIME(3) NULL,
            resolve_after INT UNSIGNED NULL,
            outbox_queued INT UNSIGNED NULL,
            outbox_failed INT UNSIGNED NOT NULL DEFAULT 0,
            pending_notice VARCHAR(16) NULL,
            UNIQUE KEY uniq_open_key (open_key),
            KEY idx_status (status),
            KEY idx_resolved_at (resolved_at)
        ) " . self::TABLE_OPTIONS);

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}outbox (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            payload LONGTEXT NOT NULL,
            status VARCHAR(16) NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(3) NOT NULL,
            next_at DATETIME(3) NOT NULL,
            deadline_at DATETIME(3) NOT NULL,
            claim_token VARCHAR(64) NULL,
            claimed_until DATETIME(3) NULL,
            last_error TEXT NULL,
            failed_at DATETIME(3) NULL,
            finished_at DATETIME(3) NULL,
            reported_at DATETIME(3) NULL,
            KEY idx_status_next (status, next_at),
            KEY idx_claim_token (claim_token),
            KEY idx_finished_at (finished_at)
        ) " . self::TABLE_OPTIONS);

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}runs (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            started_at DATETIME(3) NOT NULL,
            finished_at DATETIME(3) NULL
        ) " . self::TABLE_OPTIONS);

        $pdo->prepare(
            "INSERT INTO {$prefix}schema (id, version) VALUES (1, ?)
             ON DUPLICATE KEY UPDATE version = GREATEST(version, ?)"
        )->execute([self::SCHEMA_VERSION, self::SCHEMA_VERSION]);
    }
}
