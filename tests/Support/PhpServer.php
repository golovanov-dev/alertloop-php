<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Tests\Support;

/**
 * Starts `php -S` with a router script; the server stops with the test process.
 */
final class PhpServer
{
    /**
     * @param array<string, string> $env extra environment for the server
     * @return string base URL
     */
    public static function start(string $router, array $env = []): string
    {
        $var = dirname(__DIR__, 2) . '/var';
        if (!is_dir($var)) {
            mkdir($var, 0777, true);
        }
        $port = FakeApi::closedPort();
        $output = $var . '/' . basename($router, '.php') . '.out';
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [1 => ['file', $output, 'w'], 2 => ['file', $output, 'a']],
            $pipes,
            null,
            array_merge(getenv(), $env),
        );
        if ($process === false) {
            throw new \RuntimeException('cannot start php -S');
        }
        register_shutdown_function(static fn () => proc_terminate($process));

        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return 'http://127.0.0.1:' . $port;
            }
            usleep(50_000);
        }
        throw new \RuntimeException('php -S did not start on port ' . $port);
    }
}
