<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop;

/**
 * Entry point: incidents and outboxes for the site, and the cron run.
 */
final class AlertLoop
{
    private const RUN_SECONDS = 50;
    private const KEEP_RESOLVED_DAYS = 30;
    private const MAX_KEY_LENGTH = 191;

    private readonly Config $config;
    private readonly Notices $notices;
    private readonly Notifier $notifier;
    private readonly AfterResponse $afterResponse;
    private ?\PDO $pdo = null;
    private ?Incidents $incidents = null;
    private ?OutboxQueue $queue = null;

    /**
     * @param array<mixed> $config see README for the keys
     */
    public function __construct(array $config)
    {
        $this->config = Config::fromArray($config);
        $this->notices = new Notices($this->config->source, $this->config->language, $this->config->timezone);
        $this->notifier = new Notifier($this->config->channels, $this->config->alertsTo, $this->config->fallback);
        $this->afterResponse = new AfterResponse();
    }

    /**
     * @param string $path PHP file that returns the configuration array
     */
    public static function fromFile(string $path): self
    {
        return new self(Config::load($path));
    }

    /**
     * Opens an incident and alerts: in a web request after the response, in
     * CLI at once. While it is open, repeated calls with the same key only
     * count the repeat. Database and channel errors go to the PHP error log
     * and never reach the caller.
     *
     * @param array<string, scalar|null> $fields shown under the message
     * @param int|null $resolveAfter minutes without a repeat after which the incident resolves itself
     * @throws \InvalidArgumentException key longer than 191 characters, or negative $resolveAfter
     */
    public function fire(
        string $key,
        Severity $severity,
        string $message,
        array $fields = [],
        ?int $resolveAfter = null,
    ): void {
        self::checkKey($key);
        if ($resolveAfter !== null && $resolveAfter < 0) {
            throw new \InvalidArgumentException("alertloop: fire('{$key}'): resolveAfter must not be negative");
        }
        $strings = [];
        foreach ($fields as $name => $value) {
            $strings[(string) $name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }
        $what = "fire('{$key}')";
        try {
            $incident = $this->incidents()->fire($key, $severity, $message, '', $strings, $resolveAfter);
            if ($incident !== null) {
                $this->afterResponse->defer($what, fn () => $this->notify($incident, Notices::OPENED));
            }
        } catch (\Throwable $e) {
            self::log($what, $e);
        }
    }

    /**
     * Resolves the open incident with this key and reports recovery, the same
     * way fire() alerts. Does nothing when no incident with this key is open.
     *
     * @throws \InvalidArgumentException key longer than 191 characters
     */
    public function resolve(string $key): void
    {
        self::checkKey($key);
        $what = "resolve('{$key}')";
        try {
            $incident = $this->incidents()->resolve($key);
            if ($incident !== null) {
                $this->afterResponse->defer($what, fn () => $this->notify($incident, Notices::RESOLVED));
            }
        } catch (\Throwable $e) {
            self::log($what, $e);
        }
    }

    /**
     * The outbox with this name from the 'outbox' configuration key.
     *
     * @throws \InvalidArgumentException no such outbox in the configuration
     */
    public function outbox(string $name): Outbox
    {
        if (!isset($this->config->outboxes[$name])) {
            throw new \InvalidArgumentException("alertloop: outbox '{$name}' is not configured");
        }

        return new Outbox(fn (array $payload): int => $this->push($name, $payload));
    }

    /**
     * Cron work: resend undelivered notices, resolve silent incidents, remind
     * about open ones, retry outbox items, delete old rows. Stops taking new
     * work after 50 seconds.
     *
     * @return bool false when another run holds the lock and this one did nothing
     */
    public function run(): bool
    {
        $pdo = $this->db();
        $lock = substr($this->config->db['name'] . '.' . $this->config->tablePrefix . 'run', 0, 64);
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$lock]);
        if ((int) $statement->fetchColumn() !== 1) {
            return false;
        }

        try {
            $this->work($pdo);
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }

        return true;
    }

    private function work(\PDO $pdo): void
    {
        $deadline = microtime(true) + self::RUN_SECONDS;
        $prefix = $this->config->tablePrefix;
        Database::migrate($pdo, $prefix);
        $pdo->exec(
            "INSERT INTO {$prefix}runs (id, started_at, finished_at) VALUES (1, UTC_TIMESTAMP(3), NULL)
             ON DUPLICATE KEY UPDATE started_at = UTC_TIMESTAMP(3), finished_at = NULL"
        );
        $incidents = $this->incidents();

        foreach ($incidents->pendingNotices() as $incident) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $this->notify($incident, (string) $incident->pendingNotice);
        }
        foreach ($incidents->resolveSilent() as $incident) {
            $this->notify($incident, Notices::SILENT);
        }
        if ($this->config->remindEvery > 0) {
            foreach ($incidents->dueReminders($this->config->remindEvery) as $incident) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                $this->notify($incident, Notices::REMINDER);
            }
        }
        $this->queue()->process($deadline);

        $incidents->deleteResolvedBefore(self::KEEP_RESOLVED_DAYS);
        $this->queue()->deleteFinished();
        $pdo->exec("UPDATE {$prefix}runs SET finished_at = UTC_TIMESTAMP(3) WHERE id = 1");
    }

    /**
     * @param array<mixed> $payload
     */
    private function push(string $name, array $payload): int
    {
        [$id, $token] = $this->queue()->insert($name, $payload);
        $what = "outbox('{$name}') #{$id}";
        $this->afterResponse->defer($what, function () use ($id, $token, $what): void {
            // Saved already: from here on errors are logged, not thrown at the site.
            try {
                $this->queue()->attempt($id, $token);
            } catch (\Throwable $e) {
                self::log($what, $e);
            }
        });

        return $id;
    }

    private function notify(Incident $incident, string $notice): void
    {
        $message = $this->notices->message($incident, $notice, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->incidents()->noticeSent($incident, $notice, $this->notifier->deliver($message));
    }

    private static function checkKey(string $key): void
    {
        if (mb_strlen($key, 'UTF-8') > self::MAX_KEY_LENGTH) {
            throw new \InvalidArgumentException(
                'alertloop: incident key is longer than ' . self::MAX_KEY_LENGTH . ' characters: ' . $key
            );
        }
    }

    private static function log(string $what, \Throwable $e): void
    {
        error_log("alertloop: {$what} failed: " . $e->getMessage());
    }

    private function db(): \PDO
    {
        return $this->pdo ??= Database::connect($this->config->db);
    }

    private function incidents(): Incidents
    {
        return $this->incidents ??= new Incidents($this->db(), $this->config->tablePrefix);
    }

    private function queue(): OutboxQueue
    {
        return $this->queue ??= new OutboxQueue(
            $this->db(),
            $this->config->tablePrefix,
            $this->config->outboxes,
            $this->incidents(),
            $this->notices,
            $this->notifier,
        );
    }
}
