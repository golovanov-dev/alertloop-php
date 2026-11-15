<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop;

/**
 * Incident rows. A firing incident holds its key in open_key, which is unique,
 * so concurrent fire() calls for one key open exactly one incident.
 *
 * Opening and closing set pending_notice in the same statement, and only a
 * delivered notice clears it, so a notice interrupted on the way is resent by
 * run.
 *
 * @internal
 */
final class Incidents
{
    private const DEADLOCK = 1213;

    private readonly string $table;
    private readonly string $outboxTable;

    public function __construct(private readonly \PDO $pdo, string $prefix)
    {
        $this->table = $prefix . 'incidents';
        $this->outboxTable = $prefix . 'outbox';
    }

    /**
     * Opens an incident, or counts a repeat of the open one.
     *
     * @param array<string, string> $fields
     * @return Incident|null the new incident; null when it was already open
     */
    public function fire(
        string $key,
        Severity $severity,
        string $title,
        string $text,
        array $fields,
        ?int $resolveAfter,
    ): ?Incident {
        $statement = $this->write(
            "INSERT INTO {$this->table}
                (dedupe_key, open_key, status, severity, title, text, fields, occurrences,
                 opened_at, last_seen_at, notified_at, resolve_after, pending_notice)
             VALUES (?, ?, 'firing', ?, ?, ?, ?, 1, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), ?, ?)
             ON DUPLICATE KEY UPDATE occurrences = occurrences + 1, last_seen_at = UTC_TIMESTAMP(3)",
            [
                $key,
                $key,
                $severity->value,
                $title,
                $text,
                json_encode((object) $fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $resolveAfter,
                Notices::OPENED,
            ],
        );

        // 1 affected row: inserted; 2: the duplicate-key update ran.
        return $statement->rowCount() === 1 ? $this->find((int) $this->pdo->lastInsertId()) : null;
    }

    /**
     * @return Incident|null the incident this call resolved; null when nothing was open
     */
    public function resolve(string $key): ?Incident
    {
        $statement = $this->pdo->prepare("SELECT id FROM {$this->table} WHERE open_key = ?");
        $statement->execute([$key]);
        $id = $statement->fetchColumn();

        return $id === false ? null : $this->close((int) $id, Notices::RESOLVED, '');
    }

    /**
     * Opens the incident of an outbox, as fire() does, and records how many
     * of its items are in the queue.
     *
     * @return Incident|null the new incident; null when it was already open
     */
    public function openOutbox(string $name, string $title, string $error): ?Incident
    {
        $incident = $this->fire('outbox.' . $name, Severity::Error, $title, $error, [], null);
        if ($incident === null) {
            return null;
        }
        $this->pdo->prepare(
            "UPDATE {$this->table}
             SET outbox_queued = (SELECT COUNT(*) FROM {$this->outboxTable} WHERE name = ? AND status = 'pending')
             WHERE id = ?"
        )->execute([$name, $incident->id]);

        return $this->find($incident->id);
    }

    /**
     * Resolves the open incident of an outbox and records how many items are
     * still in the queue and how many became dead while it was open.
     *
     * @return Incident|null the incident this call resolved; null when nothing was open
     */
    public function resolveOutbox(string $name): ?Incident
    {
        $statement = $this->pdo->prepare("SELECT id, opened_at FROM {$this->table} WHERE open_key = ?");
        $statement->execute(['outbox.' . $name]);
        /** @var array{id: int|string, opened_at: string}|false $incident */
        $incident = $statement->fetch();
        if ($incident === false) {
            return null;
        }
        $statement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(status = 'pending'), 0) AS queued,
                    COALESCE(SUM(status = 'dead' AND finished_at >= ?), 0) AS failed
             FROM {$this->outboxTable} WHERE name = ?"
        );
        $statement->execute([$incident['opened_at'], $name]);
        /** @var array{queued: int|string, failed: int|string} $counts */
        $counts = $statement->fetch();

        return $this->close(
            (int) $incident['id'],
            Notices::DELIVERED,
            '',
            (int) $counts['queued'],
            (int) $counts['failed'],
        );
    }

    /**
     * Resolves firing incidents that saw no repeat for their resolve_after minutes.
     *
     * @return list<Incident>
     */
    public function resolveSilent(): array
    {
        $silent = ' AND resolve_after IS NOT NULL AND last_seen_at <= UTC_TIMESTAMP(3) - INTERVAL resolve_after MINUTE';
        $statement = $this->pdo->prepare("SELECT id FROM {$this->table} WHERE status = 'firing'{$silent} ORDER BY id");
        $statement->execute();
        $resolved = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            // The condition is checked again: a fire() since the SELECT keeps the incident open.
            $incident = $this->close((int) $id, Notices::SILENT, $silent);
            if ($incident !== null) {
                $resolved[] = $incident;
            }
        }

        return $resolved;
    }

    /**
     * Firing incidents whose last notice is older than $minutes.
     *
     * @return list<Incident>
     */
    public function dueReminders(int $minutes): array
    {
        return $this->load(
            "SELECT * FROM {$this->table}
             WHERE status = 'firing' AND pending_notice IS NULL
               AND notified_at <= UTC_TIMESTAMP(3) - INTERVAL ? MINUTE
             ORDER BY id",
            [$minutes],
        );
    }

    /**
     * Incidents with a notice that no address accepted. A mark younger than a
     * minute is skipped: the request that set it may still be sending.
     *
     * @return list<Incident>
     */
    public function pendingNotices(): array
    {
        return $this->load(
            "SELECT * FROM {$this->table}
             WHERE pending_notice IS NOT NULL AND notified_at <= UTC_TIMESTAMP(3) - INTERVAL 1 MINUTE
             ORDER BY id",
            [],
        );
    }

    /**
     * Records a notice attempt: delivered clears the pending mark, undelivered
     * leaves or sets it. Only when the row still has the status and the mark
     * $incident was loaded with, so a stale attempt does not overwrite a mark
     * set meanwhile by a resolve.
     */
    public function noticeSent(Incident $incident, string $notice, bool $delivered): void
    {
        $firing = $notice === Notices::OPENED || $notice === Notices::REMINDER;
        $this->pdo->prepare(
            "UPDATE {$this->table} SET notified_at = UTC_TIMESTAMP(3), pending_notice = ?
             WHERE id = ? AND status = ? AND pending_notice <=> ?"
        )->execute([
            $delivered ? null : $notice,
            $incident->id,
            $firing ? 'firing' : 'resolved',
            $incident->pendingNotice,
        ]);
    }

    public function deleteResolvedBefore(int $days): void
    {
        $this->pdo->prepare(
            "DELETE FROM {$this->table}
             WHERE status = 'resolved' AND resolved_at < UTC_TIMESTAMP(3) - INTERVAL ? DAY"
        )->execute([$days]);
    }

    /**
     * Resolves a firing incident and marks its notice as pending.
     */
    private function close(int $id, string $notice, string $condition, ?int $queued = null, int $failed = 0): ?Incident
    {
        $statement = $this->write(
            "UPDATE {$this->table}
             SET status = 'resolved', open_key = NULL, resolved_at = UTC_TIMESTAMP(3),
                 notified_at = UTC_TIMESTAMP(3), pending_notice = ?,
                 outbox_queued = COALESCE(?, outbox_queued), outbox_failed = ?
             WHERE id = ? AND status = 'firing'{$condition}",
            [$notice, $queued, $failed, $id],
        );

        return $statement->rowCount() === 1 ? $this->find($id) : null;
    }

    /**
     * Runs a write, and once more after a deadlock: concurrent fire() and
     * resolve() of one key lock open_key and the row in opposite order, and
     * MySQL rolls the losing statement back whole.
     *
     * @param list<int|string|null> $params
     */
    private function write(string $sql, array $params): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        try {
            $statement->execute($params);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== self::DEADLOCK) {
                throw $e;
            }
            $statement->execute($params);
        }

        return $statement;
    }

    private function find(int $id): Incident
    {
        return $this->load("SELECT * FROM {$this->table} WHERE id = ?", [$id])[0]
            ?? throw new \RuntimeException("alertloop: incident {$id} not found");
    }

    /**
     * @param list<int> $params
     * @return list<Incident>
     */
    private function load(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $incidents = [];
        foreach ($statement->fetchAll() as $row) {
            /** @var array<string, mixed> $row */
            $incidents[] = Incident::fromRow($row);
        }

        return $incidents;
    }
}
