<?php

// A site page for WebRequestTest, served by `php -S`: opens a session, fires
// an incident, pushes an outbox item and prints how many calls the fake API
// got while the page ran. The outbox handler reports the session status.

declare(strict_types=1);

use Golovanov\AlertLoop\AlertLoop;
use Golovanov\AlertLoop\Severity;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$api = (string) getenv('ALERTLOOP_TEST_API');
/** @var array<mixed> $config */
$config = json_decode((string) getenv('ALERTLOOP_TEST_CONFIG'), true, flags: JSON_THROW_ON_ERROR);
$config['outbox'] = ['lead' => ['handler' => static function (array $payload) use ($api): void {
    $name = $payload['name'] ?? null;
    file_get_contents($api . '/lead?session=' . session_status() . '&name=' . rawurlencode(is_string($name) ? $name : ''));
}]];

session_start();
$al = new AlertLoop($config);
$al->fire('crm.down', Severity::Error, 'CRM does not respond');
$al->outbox('lead')->push(['name' => 'Ann']);

echo count(file((string) getenv('ALERTLOOP_FAKE_API_LOG'), FILE_SKIP_EMPTY_LINES) ?: []);
