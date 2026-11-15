<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop;

/**
 * vendor/bin/alertloop. run is silent on success, so cron mails only problems.
 *
 * @internal
 */
final class Cli
{
    private const USAGE = "Usage: alertloop run [--config=/path/to/alertloop.php]\n"
        . "       alertloop check [--send] [--config=/path/to/alertloop.php]\n";

    /**
     * @param list<string> $argv
     * @param resource $stdout
     * @param resource $stderr
     */
    public static function main(array $argv, $stdout, $stderr): int
    {
        $command = null;
        $configPath = null;
        $send = false;
        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '--config=')) {
                $configPath = substr($arg, strlen('--config='));
            } elseif ($arg === '--send') {
                $send = true;
            } elseif ($command === null && !str_starts_with($arg, '-')) {
                $command = $arg;
            } else {
                $command = '';
            }
        }
        if ($command !== 'check' && ($command !== 'run' || $send)) {
            fwrite($stderr, self::USAGE);

            return 2;
        }

        try {
            $path = $configPath ?? self::defaultConfigPath();
            if ($command === 'check') {
                return (new Check(Config::fromArray(Config::load($path)), $stdout))->run($send);
            }
            AlertLoop::fromFile($path)->run();
        } catch (\Throwable $e) {
            fwrite($stderr, 'alertloop: ' . $e->getMessage() . "\n");

            return 1;
        }

        return 0;
    }

    /**
     * alertloop.php in the directory that holds vendor/.
     */
    private static function defaultConfigPath(): string
    {
        $classLoader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();

        return dirname((string) $classLoader, 3) . DIRECTORY_SEPARATOR . 'alertloop.php';
    }
}
