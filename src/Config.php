<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop;

use Golovanov\AlertLoop\Channel\Channel;
use Golovanov\AlertLoop\Channel\EmailChannel;
use Golovanov\AlertLoop\Channel\MaxChannel;
use Golovanov\AlertLoop\Channel\TelegramChannel;

/**
 * Validated configuration array.
 *
 * @internal
 */
final class Config
{
    /**
     * @param array{host: string, port: int, name: string, user: string, password: string} $db
     * @param array<string, Channel> $channels
     * @param list<array{string, string}> $alertsTo
     * @param list<array{string, string}> $fallback
     * @param array<string, array{handler: \Closure, on_failure: ?\Closure, retry_window: int}> $outboxes
     */
    private function __construct(
        public readonly array $db,
        public readonly string $tablePrefix,
        public readonly string $source,
        public readonly string $language,
        public readonly \DateTimeZone $timezone,
        public readonly array $channels,
        public readonly array $alertsTo,
        public readonly array $fallback,
        public readonly int $remindEvery,
        public readonly array $outboxes,
    ) {
    }

    /**
     * @param string $path PHP file that returns the configuration array
     * @return array<mixed>
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            throw new ConfigException('alertloop config: file not found: ' . $path);
        }
        $config = require $path;
        if (!is_array($config)) {
            throw new ConfigException('alertloop config: ' . $path . ' must return an array');
        }

        return $config;
    }

    /**
     * @param array<mixed> $config
     */
    public static function fromArray(array $config): self
    {
        self::known($config, ['db', 'table_prefix', 'source', 'language', 'timezone', 'channels', 'alerts', 'outbox']);
        $prefix = self::string($config, 'table_prefix', 'al_');
        if (preg_match('/^[A-Za-z0-9_]{0,32}$/', $prefix) !== 1) {
            throw self::error("'table_prefix' may contain only letters, digits and _ (up to 32)");
        }
        $language = self::string($config, 'language', 'en');
        if (!in_array($language, ['en', 'ru'], true)) {
            throw self::error("'language' must be 'en' or 'ru'");
        }
        $timezoneName = self::string($config, 'timezone', date_default_timezone_get());
        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Exception) {
            throw self::error("unknown 'timezone': " . $timezoneName);
        }

        $channels = [];
        foreach (self::array($config, 'channels') as $name => $channel) {
            $channels[(string) $name] = self::channel((string) $name, is_array($channel) ? $channel : []);
        }
        $alerts = self::array($config, 'alerts');
        self::known($alerts, ['to', 'fallback', 'remind_every'], 'alerts.');
        $alertsTo = self::targets($alerts, 'to', $channels);
        if ($alertsTo === []) {
            throw self::error("'alerts.to' is required");
        }
        $remindEvery = self::int($alerts, 'remind_every', 60, 'alerts.');
        if ($remindEvery < 0) {
            throw self::error("'alerts.remind_every' must be 0 or more minutes");
        }

        $db = self::array($config, 'db');
        self::known($db, ['host', 'port', 'name', 'user', 'password'], 'db.');

        return new self(
            [
                'host' => self::required($db, 'host', 'db.'),
                'port' => self::int($db, 'port', 3306, 'db.'),
                'name' => self::required($db, 'name', 'db.'),
                'user' => self::required($db, 'user', 'db.'),
                'password' => self::string($db, 'password', '', 'db.'),
            ],
            $prefix,
            self::required($config, 'source'),
            $language,
            $timezone,
            $channels,
            $alertsTo,
            self::targets($alerts, 'fallback', $channels),
            $remindEvery,
            self::outboxes(self::array($config, 'outbox')),
        );
    }

    /**
     * @param array<mixed> $config
     * @return array<string, array{handler: \Closure, on_failure: ?\Closure, retry_window: int}>
     */
    private static function outboxes(array $config): array
    {
        $outboxes = [];
        foreach ($config as $name => $outbox) {
            $name = (string) $name;
            $path = 'outbox.' . $name . '.';
            // The incident key is "outbox.<name>", at most 191 characters.
            if ($name === '' || mb_strlen($name, 'UTF-8') > 184) {
                throw self::error("'outbox' names must be 1 to 184 characters");
            }
            $outbox = is_array($outbox) ? $outbox : [];
            self::known($outbox, ['handler', 'on_failure', 'retry_window'], $path);
            $handler = $outbox['handler'] ?? null;
            if (!is_callable($handler)) {
                throw self::error("'{$path}handler' must be callable");
            }
            $onFailure = $outbox['on_failure'] ?? null;
            if ($onFailure !== null && !is_callable($onFailure)) {
                throw self::error("'{$path}on_failure' must be callable");
            }
            $window = self::int($outbox, 'retry_window', 24 * 60, $path);
            if ($window < 1) {
                throw self::error("'{$path}retry_window' must be 1 or more minutes");
            }
            $outboxes[$name] = [
                'handler' => \Closure::fromCallable($handler),
                'on_failure' => $onFailure === null ? null : \Closure::fromCallable($onFailure),
                'retry_window' => $window,
            ];
        }

        return $outboxes;
    }

    /**
     * @param array<mixed> $config
     */
    private static function channel(string $name, array $config): Channel
    {
        $path = 'channels.' . $name . '.';
        $type = self::required($config, 'type', $path);
        $keys = match ($type) {
            'telegram' => ['token', 'api_base'],
            'max' => ['token', 'ca_file'],
            'email' => ['from', 'transport', 'host', 'port', 'user', 'password'],
            default => throw self::error("'{$path}type' must be telegram, max or email"),
        };
        self::known($config, ['type', ...$keys], $path);

        return match ($type) {
            'telegram' => new TelegramChannel(
                self::required($config, 'token', $path),
                self::string($config, 'api_base', 'https://api.telegram.org', $path),
            ),
            'max' => new MaxChannel(
                self::required($config, 'token', $path),
                self::string($config, 'ca_file', '', $path),
            ),
            default => self::email($config, $path),
        };
    }

    /**
     * @param array<mixed> $config
     */
    private static function email(array $config, string $path): EmailChannel
    {
        $from = self::required($config, 'from', $path);
        $transport = self::string($config, 'transport', 'mail', $path);
        if ($transport === 'mail') {
            return new EmailChannel($from);
        }
        if ($transport !== 'smtp') {
            throw self::error("'{$path}transport' must be mail or smtp");
        }
        $user = self::string($config, 'user', '', $path);

        return new EmailChannel(
            $from,
            'smtp',
            self::required($config, 'host', $path),
            self::int($config, 'port', 465, $path),
            $user === '' ? null : $user,
            self::string($config, 'password', '', $path),
        );
    }

    /**
     * @param array<mixed> $alerts
     * @param array<string, Channel> $channels
     * @return list<array{string, string}>
     */
    private static function targets(array $alerts, string $key, array $channels): array
    {
        $targets = [];
        foreach (self::array($alerts, $key, 'alerts.') as $i => $target) {
            $channel = is_array($target) ? ($target[0] ?? null) : null;
            $address = is_array($target) ? ($target[1] ?? null) : null;
            if (!is_string($channel) || !is_scalar($address) || (string) $address === '') {
                throw self::error("'alerts.{$key}.{$i}' must be [channel, address]");
            }
            if (!isset($channels[$channel])) {
                throw self::error("'alerts.{$key}.{$i}' refers to unknown channel '{$channel}'");
            }
            $targets[] = [$channel, (string) $address];
        }

        return $targets;
    }

    /**
     * A key the library does not know is an error: a misspelt key would
     * silently leave the default in place.
     *
     * @param array<mixed> $config
     * @param list<string> $keys
     */
    private static function known(array $config, array $keys, string $path = ''): void
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, $keys, true)) {
                throw self::error("unknown key '{$path}{$key}'");
            }
        }
    }

    /**
     * @param array<mixed> $config
     */
    private static function required(array $config, string $key, string $path = ''): string
    {
        $value = self::string($config, $key, '', $path);
        if ($value === '') {
            throw self::error("'{$path}{$key}' is required");
        }

        return $value;
    }

    /**
     * @param array<mixed> $config
     */
    private static function string(array $config, string $key, string $default, string $path = ''): string
    {
        $value = $config[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_string($value) && !is_int($value)) {
            throw self::error("'{$path}{$key}' must be a string");
        }

        return (string) $value;
    }

    /**
     * @param array<mixed> $config
     */
    private static function int(array $config, string $key, int $default, string $path = ''): int
    {
        $value = $config[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_int($value)) {
            throw self::error("'{$path}{$key}' must be an integer");
        }

        return $value;
    }

    /**
     * @param array<mixed> $config
     * @return array<mixed>
     */
    private static function array(array $config, string $key, string $path = ''): array
    {
        $value = $config[$key] ?? [];
        if (!is_array($value)) {
            throw self::error("'{$path}{$key}' must be an array");
        }

        return $value;
    }

    private static function error(string $message): ConfigException
    {
        return new ConfigException('alertloop config: ' . $message);
    }
}
