<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Tests;

use Golovanov\AlertLoop\AlertLoop;
use Golovanov\AlertLoop\Database;
use Golovanov\AlertLoop\Incidents;
use Golovanov\AlertLoop\Notices;
use Golovanov\AlertLoop\Severity;
use Golovanov\AlertLoop\Tests\Support\DatabaseTestCase;
use Golovanov\AlertLoop\Tests\Support\FakeApi;
use PHPUnit\Framework\Attributes\DataProvider;

final class IncidentsTest extends DatabaseTestCase
{
    public function testFireAlertsOnceAndCountsRepeats(): void
    {
        $al = new AlertLoop($this->config());

        $al->fire('crm.down', Severity::Error, 'CRM does not respond', ['queue' => 3]);
        $al->fire('crm.down', Severity::Error, 'CRM does not respond');

        $requests = FakeApi::requests();
        self::assertCount(1, $requests);
        self::assertSame('/botmain/sendMessage', $requests[0]['uri']);
        self::assertSame('100', $requests[0]['body']['chat_id']);
        self::assertSame(
            "<b>example.com: CRM does not respond</b>\nError. Opened "
            . gmdate('Y-m-d H:i') . " UTC\n\n<b>queue</b>: 3",
            $requests[0]['body']['text'],
        );
        self::assertSame(2, (int) $this->scalar('SELECT occurrences FROM alt_incidents'));
    }

    public function testResolveReportsDowntimeOnceAndNextFireOpensNewIncident(): void
    {
        $al = new AlertLoop($this->config());
        $al->fire('crm.down', Severity::Error, 'CRM does not respond');
        $al->fire('crm.down', Severity::Error, 'CRM does not respond');
        $this->age('opened_at', 42);

        $al->resolve('crm.down');
        $al->resolve('crm.down');

        $requests = FakeApi::requests();
        self::assertCount(2, $requests);
        self::assertStringContainsString('Resolved, downtime 42 min. Repeats: 1', $this->text($requests[1]));

        $al->fire('crm.down', Severity::Error, 'CRM does not respond');
        self::assertCount(3, FakeApi::requests());
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM alt_incidents'));
    }

    public function testRunRemindsOncePerInterval(): void
    {
        $al = new AlertLoop($this->config());
        $al->fire('crm.down', Severity::Error, 'CRM does not respond');
        $al->run();
        self::assertCount(1, FakeApi::requests());

        $this->age('opened_at', 61);
        $this->age('notified_at', 61);
        $al->run();
        $al->run();

        $requests = FakeApi::requests();
        self::assertCount(2, $requests);
        self::assertStringContainsString('Error, still open since', $this->text($requests[1]));
        self::assertStringContainsString('(1 h 1 min)', $this->text($requests[1]));
    }

    public function testNoRemindersWhenDisabled(): void
    {
        $al = new AlertLoop($this->config(['alerts' => ['to' => [['tg', '100']], 'remind_every' => 0]]));
        $al->fire('crm.down', Severity::Error, 'CRM does not respond');
        $this->age('notified_at', 600);

        $al->run();

        self::assertCount(1, FakeApi::requests());
    }

    public function testRunResolvesIncidentAfterSilence(): void
    {
        $al = new AlertLoop($this->config());
        $al->fire('disk.low', Severity::Warning, 'Disk 92% full', resolveAfter: 30);
        $al->run();
        self::assertSame('firing', $this->scalar('SELECT status FROM alt_incidents'));

        $this->age('last_seen_at', 31);
        $al->run();

        $requests = FakeApi::requests();
        self::assertCount(2, $requests);
        self::assertStringContainsString('Resolved: no repeats for 30 min', $this->text($requests[1]));
        self::assertSame('resolved', $this->scalar('SELECT status FROM alt_incidents'));
    }

    public function testFallbackGetsTheAlertWhenAnAddressRefuses(): void
    {
        $al = new AlertLoop($this->config(['alerts' => [
            'to' => [['tg_down', '100']],
            'fallback' => [['tg_spare', '900']],
        ]]));

        $al->fire('crm.down', Severity::Error, 'CRM does not respond');

        $requests = FakeApi::requests();
        self::assertSame(['/botstatus-500/sendMessage', '/botspare/sendMessage'], array_column($requests, 'uri'));
        self::assertNull($this->scalar('SELECT pending_notice FROM alt_incidents'));
    }

    public function testRunResendsNoticeThatNobodyAccepted(): void
    {
        $down = new AlertLoop($this->config(['alerts' => ['to' => [['tg_down', '100']]]]));
        $down->fire('crm.down', Severity::Error, 'CRM does not respond');
        self::assertSame('opened', $this->scalar('SELECT pending_notice FROM alt_incidents'));

        FakeApi::reset();
        $al = new AlertLoop($this->config());
        $al->run();
        self::assertCount(0, FakeApi::requests(), 'a mark younger than a minute may still be in flight');

        $this->age('notified_at', 1);
        $al->run();

        $requests = FakeApi::requests();
        self::assertCount(1, $requests);
        self::assertStringContainsString('Error. Opened', $this->text($requests[0]));
        self::assertNull($this->scalar('SELECT pending_notice FROM alt_incidents'));
    }

    public function testNoticeCutOffAfterTheIncidentIsWrittenIsSentByRun(): void
    {
        // The process dies between writing the incident and sending: only the rows are left.
        $incidents = new Incidents($this->pdo(), self::PREFIX);
        $incidents->fire('crm.down', Severity::Error, 'CRM does not respond', '', [], null);
        $this->age('notified_at', 1);
        $al = new AlertLoop($this->config());
        $al->run();

        $incidents->resolve('crm.down');
        $this->age('notified_at', 1);
        $al->run();

        $requests = FakeApi::requests();
        self::assertCount(2, $requests);
        self::assertStringContainsString('Error. Opened', $this->text($requests[0]));
        self::assertStringContainsString('Resolved, downtime', $this->text($requests[1]));
        self::assertNull($this->scalar('SELECT pending_notice FROM alt_incidents'));
    }

    public function testStaleReminderAttemptKeepsTheRecoveryMark(): void
    {
        (new AlertLoop($this->config()))->fire('crm.down', Severity::Error, 'CRM does not respond');
        $this->age('notified_at', 61);
        $incidents = new Incidents($this->pdo(), self::PREFIX);
        [$due] = $incidents->dueReminders(60);

        // While run sends the reminder, the site resolves and nobody takes the recovery notice.
        (new AlertLoop($this->config(['alerts' => ['to' => [['tg_down', '100']]]])))->resolve('crm.down');
        $incidents->noticeSent($due, Notices::REMINDER, false);

        self::assertSame('resolved', $this->scalar('SELECT pending_notice FROM alt_incidents'));
    }

    public function testRunResendsReminderThatNobodyAccepted(): void
    {
        (new AlertLoop($this->config()))->fire('crm.down', Severity::Error, 'CRM does not respond');
        $this->age('notified_at', 61);
        (new AlertLoop($this->config(['alerts' => ['to' => [['tg_down', '100']]]])))->run();
        self::assertSame('reminder', $this->scalar('SELECT pending_notice FROM alt_incidents'));

        FakeApi::reset();
        $this->age('notified_at', 1);
        (new AlertLoop($this->config()))->run();

        self::assertStringContainsString('Error, still open since', $this->text(FakeApi::requests()[0]));
        self::assertNull($this->scalar('SELECT pending_notice FROM alt_incidents'));
    }

    public function testDatabaseErrorsDoNotReachTheSite(): void
    {
        $this->pdo()->exec('DROP TABLE alt_incidents');
        $log = $this->errorLog(function (): void {
            $al = new AlertLoop($this->config());
            $al->fire('crm.down', Severity::Error, 'CRM does not respond');
            $al->resolve('crm.down');
        });

        self::assertStringContainsString("alertloop: fire('crm.down') failed: SQLSTATE[42S02]", $log);
        self::assertStringContainsString("alertloop: resolve('crm.down') failed: SQLSTATE[42S02]", $log);
        self::assertCount(0, FakeApi::requests());
    }

    /**
     * @return iterable<string, array{\Closure(AlertLoop): void}>
     */
    public static function invalidCalls(): iterable
    {
        yield 'key too long' => [fn (AlertLoop $al) => $al->resolve(str_repeat('я', 192))];
        yield 'negative resolveAfter' => [fn (AlertLoop $al) => $al->fire('k', Severity::Error, 'm', resolveAfter: -1)];
    }

    /**
     * @param \Closure(AlertLoop): void $call
     */
    #[DataProvider('invalidCalls')]
    public function testInvalidArgumentsThrowBeforeTheDatabase(\Closure $call): void
    {
        $this->pdo()->exec('DROP TABLE alt_incidents');

        $this->expectException(\InvalidArgumentException::class);
        $call(new AlertLoop($this->config()));
    }

    public function testRunDeletesResolvedIncidentsAfter30DaysAndRecordsItself(): void
    {
        $al = new AlertLoop($this->config());
        $al->fire('old', Severity::Error, 'Old');
        $al->resolve('old');
        $this->age('resolved_at', 31 * 24 * 60);
        $al->fire('recent', Severity::Error, 'Recent');
        $al->resolve('recent');

        self::assertTrue($al->run());

        self::assertSame('recent', $this->scalar('SELECT GROUP_CONCAT(dedupe_key) FROM alt_incidents'));
        self::assertNotNull($this->scalar('SELECT finished_at FROM alt_runs WHERE id = 1'));
    }

    public function testRunSkipsWhileAnotherRunHoldsTheLock(): void
    {
        $other = Database::connect(self::db());
        $other->query("SELECT GET_LOCK('" . self::db()['name'] . ".alt_run', 0)");

        self::assertFalse((new AlertLoop($this->config()))->run());
        self::assertFalse($this->scalar('SELECT started_at FROM alt_runs'));
    }

    public function testRussianTextsUseConfiguredTimezone(): void
    {
        $al = new AlertLoop($this->config(['language' => 'ru', 'timezone' => 'Europe/Moscow']));

        $al->fire('crm.down', Severity::Critical, 'CRM недоступна');

        $moscow = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('d.m.Y H:i');
        self::assertStringContainsString("Критично. Открыт {$moscow} MSK", $this->text(FakeApi::requests()[0]));
    }

    /**
     * @param \Closure(): void $work
     * @return string what $work wrote to the PHP error log
     */
    private function errorLog(\Closure $work): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'alt');
        $previous = (string) ini_set('error_log', $file);
        try {
            $work();
        } finally {
            ini_set('error_log', $previous);
        }
        $log = (string) file_get_contents($file);
        unlink($file);

        return $log;
    }

    /**
     * @param array{body: array<string, mixed>} $request
     */
    private function text(array $request): string
    {
        $text = $request['body']['text'] ?? '';

        return is_string($text) ? $text : '';
    }
}
