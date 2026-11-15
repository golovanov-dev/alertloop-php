<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop;

/**
 * Outbox rows and delivery attempts. Whoever attempts an item first claims it
 * with a random token for two minutes; every write about the attempt checks
 * the token, so a process that lost its claim changes nothing.
 *
 * @internal
 */
final class OutboxQueue
{
    private const LEASE_MINUTES = 2;
    /** Minutes to wait after the 1st, 2nd, … failed attempt; the last one repeats. */
    private const PAUSES = [1, 2, 4, 8, 16, 30];
    private const KEEP_SENT_DAYS = 7;
    private const KEEP_DEAD_DAYS = 30;
    private const MAX_ERROR_LENGTH = 1000;
    /** last_error while an attempt runs: stays when the process ends inside the handler. */
    private const UNFINISHED = 'the attempt did not finish';

    private readonly string $table;

    /**
     * @param array<string, array{handler: \Closure, on_failure: ?\Closure, retry_window: int}> $outboxes
     */
    public function __construct(
        private readonly \PDO $pdo,
        string $prefix,
        private readonly array $outboxes,
        private readonly Incidents $incidents,
        private readonly Notices $notices,
        private readonly Notifier $notifier,
    ) {
        $this->table = $prefix . 'outbox';
    }

    /**
     * Saves a pending item already claimed by the caller.
     *
     * @param array<mixed> $payload
     * @return array{int, string} id and claim token
     */
    public function insert(string $name, array $payload): array
    {
        $token = bin2hex(random_bytes(16));
        $this->pdo->prepare(
            "INSERT INTO {$this->table}
                (name, payload, status, attempts, created_at, next_at, deadline_at, claim_token, claimed_until)
             VALUES (?, ?, 'pending', 0, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), UTC_TIMESTAMP(3) + INTERVAL ? MINUTE,
                     ?, UTC_TIMESTAMP(3) + INTERVAL " . self::LEASE_MINUTES . ' MINUTE)'
        )->execute([
            $name,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $this->outboxes[$name]['retry_window'],
            $token,
        ]);

        return [(int) $this->pdo->lastInsertId(), $token];
    }

    /**
     * Cron part: reports dead items nobody was told about, then attempts due
     * items one by one in id order until $deadline (microtime).
     */
    public function process(float $deadline): void
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'dead' AND reported_at IS NULL AND finished_at <= UTC_TIMESTAMP(3) - INTERVAL 1 MINUTE
             ORDER BY id"
        );
        $statement->execute();
        foreach ($statement->fetchAll() as $item) {
            if (microtime(true) >= $deadline) {
                return;
            }
            /** @var array<string, mixed> $item */
            $this->reportDead($item);
        }

        if ($this->outboxes === []) {
            return;
        }
        $names = array_keys($this->outboxes);
        $claim = $this->pdo->prepare(
            "UPDATE {$this->table}
             SET claim_token = ?, claimed_until = UTC_TIMESTAMP(3) + INTERVAL " . self::LEASE_MINUTES . " MINUTE
             WHERE status = 'pending' AND next_at <= UTC_TIMESTAMP(3)
               AND (claimed_until IS NULL OR claimed_until < UTC_TIMESTAMP(3))
               AND name IN (" . implode(', ', array_fill(0, count($names), '?')) . ')
             ORDER BY id LIMIT 1'
        );
        $find = $this->pdo->prepare("SELECT id FROM {$this->table} WHERE claim_token = ?");
        while (microtime(true) < $deadline) {
            $token = bin2hex(random_bytes(16));
            $claim->execute([$token, ...$names]);
            if ($claim->rowCount() !== 1) {
                return;
            }
            $find->execute([$token]);
            $this->attempt((int) $find->fetchColumn(), $token);
        }
    }

    /**
     * Runs the handler for an item claimed with $token. Does nothing when the
     * claim was taken over or has run out. The attempt is counted before the
     * handler runs, so a handler that ends the process is not retried forever;
     * an item past its deadline is not attempted but becomes dead.
     */
    public function attempt(int $id, string $token): void
    {
        $statement = $this->pdo->prepare(
            "SELECT *, deadline_at <= UTC_TIMESTAMP(3) AS expired FROM {$this->table}
             WHERE id = ? AND claim_token = ? AND status = 'pending' AND claimed_until > UTC_TIMESTAMP(3)"
        );
        $statement->execute([$id, $token]);
        /** @var array<string, mixed>|false $item */
        $item = $statement->fetch();
        if ($item === false) {
            return;
        }
        $name = self::str($item['name']);
        /** @var array<mixed> $payload */
        $payload = json_decode(self::str($item['payload']), true, flags: JSON_THROW_ON_ERROR);
        if ((int) self::str($item['expired']) === 1) {
            $this->failed($item, $payload, self::str($item['last_error']), true);

            return;
        }

        $pause = self::PAUSES[min((int) self::str($item['attempts']), count(self::PAUSES) - 1)];
        $statement = $this->pdo->prepare(
            "UPDATE {$this->table}
             SET attempts = attempts + 1, last_error = ?, next_at = UTC_TIMESTAMP(3) + INTERVAL {$pause} MINUTE
             WHERE id = ? AND claim_token = ? AND claimed_until > UTC_TIMESTAMP(3)"
        );
        $statement->execute([self::UNFINISHED, $id, $token]);
        if ($statement->rowCount() !== 1) {
            return;
        }

        try {
            ($this->outboxes[$name]['handler'])($payload);
        } catch (PermanentFailure $e) {
            $this->failed($item, $payload, self::describe($e), true);

            return;
        } catch (\Throwable $e) {
            $this->failed($item, $payload, self::describe($e), false);

            return;
        }

        $statement = $this->pdo->prepare(
            "UPDATE {$this->table}
             SET status = 'sent', last_error = NULL, finished_at = UTC_TIMESTAMP(3),
                 claim_token = NULL, claimed_until = NULL
             WHERE id = ? AND claim_token = ?"
        );
        $statement->execute([$id, $token]);
        if ($statement->rowCount() !== 1) {
            return;
        }
        $incident = $this->incidents->resolveOutbox($name);
        if ($incident !== null) {
            $this->notify($incident, Notices::DELIVERED);
        }
    }

    public function deleteFinished(): void
    {
        $this->pdo->prepare(
            "DELETE FROM {$this->table}
             WHERE (status = 'sent' AND finished_at < UTC_TIMESTAMP(3) - INTERVAL ? DAY)
                OR (status = 'dead' AND finished_at < UTC_TIMESTAMP(3) - INTERVAL ? DAY)"
        )->execute([self::KEEP_SENT_DAYS, self::KEEP_DEAD_DAYS]);
    }

    /**
     * Records a failed attempt. On the item's first failure calls on_failure
     * and, unless the failure is permanent, opens the outbox incident.
     *
     * @param array<string, mixed> $item
     * @param array<mixed> $payload
     */
    private function failed(array $item, array $payload, string $error, bool $permanent): void
    {
        $id = (int) self::str($item['id']);
        $name = self::str($item['name']);
        // next_at was set when the attempt started; a retry that would start after the deadline is not made.
        $status = $permanent ? "'dead'" : "IF(next_at > deadline_at, 'dead', 'pending')";
        $statement = $this->pdo->prepare(
            "UPDATE {$this->table}
             SET status = {$status}, last_error = ?,
                 failed_at = COALESCE(failed_at, UTC_TIMESTAMP(3)),
                 finished_at = IF(status = 'dead', UTC_TIMESTAMP(3), NULL),
                 claim_token = NULL, claimed_until = NULL
             WHERE id = ? AND claim_token = ?"
        );
        $statement->execute([$error, $id, $item['claim_token']]);
        if ($statement->rowCount() !== 1) {
            return;
        }

        if ($item['failed_at'] === null) {
            $onFailure = $this->outboxes[$name]['on_failure'];
            if ($onFailure !== null) {
                try {
                    $onFailure($payload, $error);
                } catch (\Throwable $hookError) {
                    error_log("alertloop: outbox('{$name}') #{$id} on_failure failed: " . $hookError->getMessage());
                }
            }
            if (!$permanent) {
                $incident = $this->incidents->openOutbox($name, $this->notices->outboxFailing($name), $error);
                if ($incident !== null) {
                    $this->notify($incident, Notices::OPENED);
                }
            }
        }

        $statement = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = ? AND status = 'dead'");
        $statement->execute([$id]);
        /** @var array<string, mixed>|false $dead */
        $dead = $statement->fetch();
        if ($dead !== false) {
            $this->reportDead($dead);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function reportDead(array $item): void
    {
        $id = (int) self::str($item['id']);
        $message = $this->notices->deadItem(
            self::str($item['name']),
            $id,
            (int) self::str($item['attempts']),
            self::str($item['last_error']),
        );
        if ($this->notifier->deliver($message)) {
            $this->pdo->prepare("UPDATE {$this->table} SET reported_at = UTC_TIMESTAMP(3) WHERE id = ?")->execute([$id]);
        }
    }

    private function notify(Incident $incident, string $notice): void
    {
        $message = $this->notices->message($incident, $notice, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->incidents->noticeSent($incident, $notice, $this->notifier->deliver($message));
    }

    /**
     * The exception message as last_error: valid UTF-8, at most 1000 characters.
     */
    private static function describe(\Throwable $e): string
    {
        $error = mb_substr(mb_scrub($e->getMessage(), 'UTF-8'), 0, self::MAX_ERROR_LENGTH, 'UTF-8');

        return $error === '' ? $e::class : $error;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
