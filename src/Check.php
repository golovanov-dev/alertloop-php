<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop;

use Golovanov\AlertLoop\Channel\Channel;
use Golovanov\AlertLoop\Channel\EmailChannel;
use Golovanov\AlertLoop\Channel\MaxChannel;
use Golovanov\AlertLoop\Channel\TelegramChannel;

/**
 * vendor/bin/alertloop check: one line per check, OK / WARN / FAIL / SKIP.
 *
 * @internal
 */
final class Check
{
    private const EXTENSIONS = ['curl', 'json', 'mbstring', 'pdo_mysql'];
    /** A run older than this means cron does not start it every minute. */
    private const STALE_RUN_SECONDS = 180;

    private int $failures = 0;

    /**
     * @param resource $out
     */
    public function __construct(private readonly Config $config, private $out)
    {
    }

    /**
     * @param bool $send also send a test message to every alert address
     * @return int exit code: 1 when any check failed
     */
    public function run(bool $send): int
    {
        $this->php();
        $pdo = $this->mysql();
        if ($pdo !== null) {
            try {
                $this->cron($pdo);
            } catch (\PDOException $e) {
                $this->line('FAIL', 'cron', $e->getMessage());
            }
        }
        foreach ($this->config->channels as $name => $channel) {
            $this->channel($name, $channel);
        }
        if ($send) {
            $this->send();
        }
        fwrite($this->out, "\n" . ($this->failures === 0 ? 'Result: OK' : "Result: {$this->failures} FAIL") . "\n");

        return $this->failures === 0 ? 0 : 1;
    }

    private function php(): void
    {
        $this->line('OK', 'php', PHP_VERSION . ' (' . PHP_SAPI . ')');
        $missing = array_values(array_filter(self::EXTENSIONS, static fn (string $e): bool => !extension_loaded($e)));
        if ($missing === []) {
            $this->line('OK', 'php', 'extensions ' . implode(', ', self::EXTENSIONS));
        } else {
            $this->line('FAIL', 'php', 'missing extensions: ' . implode(', ', $missing));
        }
        $this->line('SKIP', 'php', 'fastcgi_finish_request / litespeed_finish_request: visible only from a web request');
    }

    private function mysql(): ?\PDO
    {
        $db = $this->config->db;
        $where = $db['user'] . '@' . $db['host'] . ':' . $db['port'] . '/' . $db['name'];
        try {
            $pdo = Database::connect($db);
        } catch (\PDOException $e) {
            $this->line('FAIL', 'mysql', 'cannot connect as ' . $where . ': ' . $e->getMessage());

            return null;
        }
        try {
            $version = (string) $this->value($pdo, 'SELECT VERSION()');
            $supported = str_starts_with($version, '5.7.') || str_starts_with($version, '8.4.');
            $this->line($supported ? 'OK' : 'WARN', 'mysql', 'connected as ' . $where . ', MySQL ' . $version
                . ($supported ? '' : '; tested are 5.7 and 8.4'));

            $lock = substr($db['name'] . '.' . $this->config->tablePrefix . 'check', 0, 64);
            $locked = (int) $this->value($pdo, 'SELECT GET_LOCK(?, 0)', [$lock]);
            $this->value($pdo, 'SELECT RELEASE_LOCK(?)', [$lock]);
            $this->line($locked === 1 ? 'OK' : 'FAIL', 'mysql', 'GET_LOCK ' . ($locked === 1 ? 'works' : 'returned ' . $locked));

            Database::migrate($pdo, $this->config->tablePrefix);
            $this->line('OK', 'mysql', 'tables ' . $this->config->tablePrefix . '* are up to date');
        } catch (\PDOException $e) {
            $this->line('FAIL', 'mysql', $e->getMessage());

            return null;
        }

        return $pdo;
    }

    private function cron(\PDO $pdo): void
    {
        $age = $this->value(
            $pdo,
            'SELECT TIMESTAMPDIFF(SECOND, started_at, UTC_TIMESTAMP(3)) FROM ' . $this->config->tablePrefix . 'runs WHERE id = 1',
        );
        if ($age === false) {
            $this->line('FAIL', 'cron', 'alertloop run has never run');
        } elseif ((int) $age > self::STALE_RUN_SECONDS) {
            $this->line('FAIL', 'cron', 'last alertloop run started ' . intdiv((int) $age, 60) . ' min ago; cron must start it every minute');
        } else {
            $this->line('OK', 'cron', 'last alertloop run started ' . (int) $age . ' s ago');
        }
    }

    private function channel(string $name, Channel $channel): void
    {
        $label = "channel '{$name}'";
        if (!$channel instanceof TelegramChannel && !$channel instanceof MaxChannel && !$channel instanceof EmailChannel) {
            return;
        }
        try {
            $detail = $channel->check();
        } catch (\RuntimeException $e) {
            $this->line('FAIL', $label, $e->getMessage());

            return;
        }
        $this->line('OK', $label, $channel instanceof EmailChannel ? $detail : 'bot @' . $detail);
    }

    private function send(): void
    {
        $message = (new Notices($this->config->source, $this->config->language, $this->config->timezone))->test();
        foreach ([...$this->config->alertsTo, ...$this->config->fallback] as [$name, $address]) {
            $result = $this->config->channels[$name]->send($address, $message);
            $label = "send '{$name}' {$address}";
            if ($result->ok) {
                $this->line('OK', $label, 'test message accepted');
            } else {
                $this->line('FAIL', $label, $result->error);
            }
        }
    }

    /**
     * @param list<string> $params
     */
    private function value(\PDO $pdo, string $sql, array $params = []): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    private function line(string $status, string $what, string $detail): void
    {
        if ($status === 'FAIL') {
            $this->failures++;
        }
        fwrite($this->out, str_pad($status, 6) . $what . ': ' . preg_replace('/\s+/', ' ', $detail) . "\n");
    }
}
