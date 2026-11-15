<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Tests;

use Golovanov\AlertLoop\AlertLoop;
use Golovanov\AlertLoop\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testMissingKeyIsNamed(): void
    {
        $config = $this->valid();
        unset($config['db']['name']);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("alertloop config: 'db.name' is required");
        new AlertLoop($config);
    }

    public function testUnknownKeyIsNamedWithItsPlace(): void
    {
        $config = $this->valid();
        $config['channels']['max'] = ['type' => 'max', 'token' => 'abc', 'api_base' => 'https://mirror.example.net'];

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("alertloop config: unknown key 'channels.max.api_base'");
        new AlertLoop($config);
    }

    public function testAlertAddressMustUseAConfiguredChannel(): void
    {
        $config = $this->valid();
        $config['alerts']['to'][] = ['max', '-7123'];

        $this->expectExceptionMessage("alertloop config: 'alerts.to.1' refers to unknown channel 'max'");
        new AlertLoop($config);
    }

    public function testTablePrefixIsRestrictedToIdentifierCharacters(): void
    {
        $this->expectExceptionMessage("'table_prefix' may contain only letters, digits and _");
        new AlertLoop(['table_prefix' => 'al`; DROP'] + $this->valid());
    }

    public function testOutboxNeedsAHandler(): void
    {
        $this->expectExceptionMessage("alertloop config: 'outbox.lead.handler' must be callable");
        new AlertLoop(['outbox' => ['lead' => ['on_failure' => 'error_log']]] + $this->valid());
    }

    public function testFileMustReturnAnArray(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'alertloop');
        file_put_contents((string) $file, "<?php\nreturn 1;\n");

        try {
            $this->expectExceptionMessage('must return an array');
            AlertLoop::fromFile((string) $file);
        } finally {
            unlink((string) $file);
        }
    }

    /**
     * @return array{db: array<string, mixed>, source: string, channels: array<string, mixed>, alerts: array{to: list<array{string, string}>}}
     */
    private function valid(): array
    {
        return [
            'db' => ['host' => 'localhost', 'name' => 'site', 'user' => 'site', 'password' => 'secret'],
            'source' => 'example.com',
            'channels' => ['tg' => ['type' => 'telegram', 'token' => '123:abc']],
            'alerts' => ['to' => [['tg', '-100123']]],
        ];
    }
}
