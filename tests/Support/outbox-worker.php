<?php

// One of two processes that work the same outbox at once (OutboxTest). Goes
// straight to the queue, past the run lock, so the two compete for each item.
// Writes "<item> <pid>" for every item its handler gets; with
// ALERTLOOP_TEST_EXIT the handler then ends the process, as exit() in site code does.

declare(strict_types=1);

use Golovanov\AlertLoop\Config;
use Golovanov\AlertLoop\Database;
use Golovanov\AlertLoop\Incidents;
use Golovanov\AlertLoop\Notices;
use Golovanov\AlertLoop\Notifier;
use Golovanov\AlertLoop\OutboxQueue;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$handled = (string) getenv('ALERTLOOP_TEST_HANDLED');
/** @var array<mixed> $settings */
$settings = json_decode((string) getenv('ALERTLOOP_TEST_CONFIG'), true, flags: JSON_THROW_ON_ERROR);
$settings['outbox'] = ['lead' => ['handler' => static function (array $payload) use ($handled): void {
    usleep(30_000);
    $n = $payload['n'] ?? null;
    file_put_contents($handled, (is_int($n) ? $n : 0) . ' ' . getmypid() . "\n", FILE_APPEND | LOCK_EX);
    if (getenv('ALERTLOOP_TEST_EXIT') !== false) {
        exit(0);
    }
}]];
$config = Config::fromArray($settings);
$pdo = Database::connect($config->db);
$queue = new OutboxQueue(
    $pdo,
    $config->tablePrefix,
    $config->outboxes,
    new Incidents($pdo, $config->tablePrefix),
    new Notices($config->source, $config->language, $config->timezone),
    new Notifier($config->channels, $config->alertsTo, $config->fallback),
);

time_sleep_until((float) getenv('ALERTLOOP_TEST_START'));
$queue->process(microtime(true) + 30);
