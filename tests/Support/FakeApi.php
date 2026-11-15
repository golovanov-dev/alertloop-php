<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Tests\Support;

/**
 * A local `php -S` server playing Telegram and MAX, started once per test process.
 * Outbox handlers in tests call it too, so their calls show in requests().
 */
final class FakeApi
{
    private static bool $started = false;
    private static string $url = '';
    private static string $log = '';

    public static function url(): string
    {
        if (!self::$started) {
            self::start();
        }

        return self::$url;
    }

    public static function reset(): void
    {
        self::url();
        file_put_contents(self::$log, '');
    }

    /**
     * @return list<array{uri: string, headers: array<string, string>, body: array<string, mixed>}>
     */
    public static function requests(): array
    {
        $requests = [];
        foreach (file(self::$log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            /** @var array{uri: string, headers: array<string, string>, body: string} $request */
            $request = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $body */
            $body = $request['body'] === '' ? [] : json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
            $requests[] = ['uri' => $request['uri'], 'headers' => $request['headers'], 'body' => $body];
        }

        return $requests;
    }

    /**
     * A local port nobody listens on.
     */
    public static function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('cannot open a local socket');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    public static function logFile(): string
    {
        self::url();

        return self::$log;
    }

    private static function start(): void
    {
        $var = dirname(__DIR__, 2) . '/var';
        if (!is_dir($var)) {
            mkdir($var, 0777, true);
        }
        self::$log = $var . '/fake-api.log';
        file_put_contents(self::$log, '');
        self::$url = PhpServer::start(__DIR__ . '/fake-api.php', ['ALERTLOOP_FAKE_API_LOG' => self::$log]);
        self::$started = true;
    }
}
