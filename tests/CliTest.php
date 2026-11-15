<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Tests;

use Golovanov\AlertLoop\Tests\Support\DatabaseTestCase;
use Golovanov\AlertLoop\Tests\Support\FakeApi;

final class CliTest extends DatabaseTestCase
{
    public function testRunWithConfigOption(): void
    {
        [$code, $stderr] = $this->cliWithConfig($this->config(), 'run');

        self::assertSame('', $stderr);
        self::assertSame(0, $code);
        self::assertNotNull($this->scalar('SELECT finished_at FROM alt_runs WHERE id = 1'));
    }

    public function testCheckFailsOnARejectedTokenAndAMissingRun(): void
    {
        $this->pdo()->exec('DROP TABLE alt_schema, alt_incidents, alt_outbox, alt_runs');
        $config = $this->config();
        unset($config['channels']['tg_down']);
        $config['channels']['tg_bad'] = ['type' => 'telegram', 'token' => 'status-401', 'api_base' => FakeApi::url()];

        [$code, , $stdout] = $this->cliWithConfig($config, 'check');

        self::assertSame(1, $code);
        self::assertStringContainsString("OK    channel 'tg': bot @fake_bot\n", $stdout);
        self::assertStringContainsString("FAIL  channel 'tg_bad': " . FakeApi::url() . ": HTTP 401: fake 401\n", $stdout);
        self::assertStringContainsString("FAIL  cron: alertloop run has never run\n", $stdout);
        self::assertStringEndsWith("Result: 2 FAIL\n", $stdout);
        self::assertFalse($this->scalar('SELECT finished_at FROM alt_runs'), 'check creates the tables');
    }

    public function testCheckSendReachesEveryAlertAddress(): void
    {
        $config = $this->config(['alerts' => ['to' => [['tg', '100']], 'fallback' => [['tg_spare', '900']]]]);
        unset($config['channels']['tg_down']);
        $this->cliWithConfig($config, 'run');

        [$code, , $stdout] = $this->cliWithConfig($config, 'check', '--send');

        self::assertSame(0, $code, $stdout);
        $sent = array_values(array_filter(
            FakeApi::requests(),
            static fn (array $request): bool => str_ends_with($request['uri'], '/sendMessage'),
        ));
        self::assertSame(['100', '900'], array_column(array_column($sent, 'body'), 'chat_id'));
        self::assertSame('<b>example.com: Test message from alertloop check</b>', $sent[0]['body']['text']);
    }

    public function testDefaultConfigIsNextToVendor(): void
    {
        [$code, $stderr] = $this->cli('run');

        self::assertSame(1, $code);
        self::assertSame(
            'alertloop: alertloop config: file not found: ' . dirname(__DIR__) . DIRECTORY_SEPARATOR . "alertloop.php\n",
            $stderr,
        );
    }

    public function testUnknownCommandPrintsUsage(): void
    {
        [$code, $stderr] = $this->cli('send');

        self::assertSame(2, $code);
        self::assertStringStartsWith('Usage: alertloop run', $stderr);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{int, string, string}
     */
    private function cliWithConfig(array $config, string ...$args): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'alertloop');
        file_put_contents($file, '<?php return ' . var_export($config, true) . ';');
        try {
            return $this->cli(...[...$args, '--config=' . $file]);
        } finally {
            unlink($file);
        }
    }

    /**
     * @return array{int, string, string} exit code, stderr, stdout
     */
    private function cli(string ...$args): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/alertloop', ...array_values($args)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stderr, $stdout];
    }
}
