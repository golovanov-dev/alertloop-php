<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Tests;

use Golovanov\AlertLoop\AlertLoop;
use Golovanov\AlertLoop\Config;
use Golovanov\AlertLoop\Incidents;
use Golovanov\AlertLoop\Notices;
use Golovanov\AlertLoop\Notifier;
use Golovanov\AlertLoop\OutboxQueue;
use Golovanov\AlertLoop\PermanentFailure;
use Golovanov\AlertLoop\Tests\Support\DatabaseTestCase;
use Golovanov\AlertLoop\Tests\Support\FakeApi;

final class OutboxTest extends DatabaseTestCase
{
    /** @var list<array<mixed>> payloads the handler got */
    private array $handled = [];
    /** @var list<array{array<mixed>, string}> on_failure calls */
    private array $failures = [];
    /** @var \Throwable|null what the handler throws; null — it succeeds */
    private ?\Throwable $crm = null;

    public function testPushInCliDeliversAtOnce(): void
    {
        $id = $this->alertLoop()->outbox('lead')->push(['name' => 'Ann']);

        self::assertSame([['name' => 'Ann']], $this->handled);
        self::assertSame('sent', $this->scalar("SELECT status FROM alt_outbox WHERE id = {$id}"));
        self::assertCount(0, FakeApi::requests());
    }

    public function testPushThrowsWhenTheItemIsNotSaved(): void
    {
        $this->pdo()->exec('DROP TABLE alt_outbox');

        $this->expectException(\PDOException::class);
        $this->alertLoop()->outbox('lead')->push(['name' => 'Ann']);
    }

    public function testUnknownOutboxIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->alertLoop()->outbox('crm.deal');
    }

    public function testRunTakesOverAnItemWhoseProcessDiedAndTheLateAttemptDoesNothing(): void
    {
        // The request saved the item and died before its attempt: only the claimed row is left.
        [$id, $token] = $this->queue()->insert('lead', ['name' => 'Ann']);
        $al = $this->alertLoop();
        $al->run();
        self::assertSame([], $this->handled, 'the claim of the request holds for two minutes');

        $this->age('claimed_until', 2, 'outbox');
        $al->run();
        self::assertSame([['name' => 'Ann']], $this->handled);
        self::assertSame('sent', $this->scalar('SELECT status FROM alt_outbox'));

        // The request was only slow, not dead, and now makes its attempt with the old claim.
        $this->queue()->attempt($id, $token);
        self::assertCount(1, $this->handled);
    }

    public function testTwoWorkersNeverTakeTheSameItem(): void
    {
        $queue = $this->queue();
        for ($n = 1; $n <= 30; $n++) {
            $queue->insert('lead', ['n' => $n]);
        }
        $this->pdo()->exec('UPDATE alt_outbox SET claim_token = NULL, claimed_until = NULL');

        $lines = $this->workers(2);

        $items = array_map(static fn (string $line): int => (int) explode(' ', $line)[0], $lines);
        sort($items);
        self::assertSame(range(1, 30), $items);
        self::assertCount(2, array_unique(array_map(static fn (string $line): string => explode(' ', $line)[1], $lines)));
        self::assertSame(30, (int) $this->scalar("SELECT COUNT(*) FROM alt_outbox WHERE status = 'sent'"));
    }

    public function testHandlerThatEndsTheProcessCountsAsAnAttemptAndIsNotRetriedAfterTheDeadline(): void
    {
        $this->queue()->insert('lead', ['n' => 1]);
        $this->pdo()->exec('UPDATE alt_outbox SET claim_token = NULL, claimed_until = NULL');

        self::assertCount(1, $this->workers(1, ['ALERTLOOP_TEST_EXIT' => '1']));
        self::assertSame('1', (string) $this->scalar("SELECT attempts FROM alt_outbox WHERE status = 'pending'"));

        $this->age('next_at', 24 * 60, 'outbox');
        $this->age('deadline_at', 24 * 60, 'outbox');
        $this->age('claimed_until', 2, 'outbox');
        $this->alertLoop()->run();

        self::assertSame([], $this->handled);
        self::assertSame('dead', $this->scalar('SELECT status FROM alt_outbox'));
        self::assertSame([[['n' => 1], 'the attempt did not finish']], $this->failures);
        $requests = FakeApi::requests();
        self::assertCount(1, $requests);
        self::assertSame("<b>example.com: Outbox lead: item #1 is not delivered</b>\n"
            . 'Attempts: 1. Last error: the attempt did not finish', $this->text($requests[0]));
    }

    public function testFirstFailureCallsOnFailureOnceAndOpensOneIncident(): void
    {
        $this->crm = new \RuntimeException('CRM answers 503');
        $al = $this->alertLoop();
        $al->outbox('lead')->push(['name' => 'Ann']);
        $al->outbox('lead')->push(['name' => 'Bob']);

        $al->run();
        $this->age('next_at', 1, 'outbox');
        $al->run();

        self::assertSame([[['name' => 'Ann'], 'CRM answers 503'], [['name' => 'Bob'], 'CRM answers 503']], $this->failures);
        self::assertCount(4, $this->handled);
        $requests = FakeApi::requests();
        self::assertCount(1, $requests);
        self::assertSame("<b>example.com: Outbox lead: delivery fails</b>\nError. Opened "
            . gmdate('Y-m-d H:i') . " UTC. In queue: 1\nCRM answers 503", $this->text($requests[0]));
        // The second failure of an item waits twice as long as the first.
        self::assertEqualsWithDelta(120, (int) $this->scalar(
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(3), next_at) FROM alt_outbox WHERE id = 1'
        ), 5);
    }

    public function testFirstDeliveryResolvesTheIncidentWithTheQueueAndTheDeadItems(): void
    {
        $al = $this->alertLoop();
        $this->crm = new \RuntimeException('CRM answers 503');
        $al->outbox('lead')->push(['name' => 'Ann']);
        $al->outbox('lead')->push(['name' => 'Bob']);
        $this->crm = new PermanentFailure('duplicate lead');
        $al->outbox('lead')->push(['name' => 'Cid']);
        $this->age('opened_at', 42);

        $this->crm = null;
        $this->age('next_at', 1, 'outbox');
        $al->run();

        $texts = array_map($this->text(...), FakeApi::requests());
        self::assertCount(3, $texts);
        self::assertStringContainsString('Outbox lead: delivery fails', $texts[0]);
        self::assertStringContainsString('item #3 is not delivered', $texts[1]);
        // Ann's delivery resolves the incident while Bob still waits in the queue.
        self::assertSame("<b>example.com: Outbox lead: delivery restored</b>\n"
            . 'Restored, downtime 42 min. In queue: 1, not delivered: 1', $texts[2]);
        self::assertSame('resolved', $this->scalar('SELECT status FROM alt_incidents'));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM alt_outbox WHERE status = 'pending'"));
    }

    public function testRetryAfterTheDeadlineIsNotMadeAndTheItemDies(): void
    {
        $this->crm = new \RuntimeException('CRM answers 503');
        $al = $this->alertLoop();
        $id = $al->outbox('lead')->push(['name' => 'Ann', 'phone' => '+10000000000']);

        // cron stood still past the deadline
        $this->age('next_at', 24 * 60, 'outbox');
        $this->age('deadline_at', 24 * 60, 'outbox');
        $al->run();
        $al->run();

        self::assertCount(1, $this->handled);
        self::assertSame('dead', $this->scalar('SELECT status FROM alt_outbox'));
        $texts = array_map($this->text(...), FakeApi::requests());
        self::assertCount(2, $texts);
        self::assertSame("<b>example.com: Outbox lead: item #{$id} is not delivered</b>\n"
            . 'Attempts: 1. Last error: CRM answers 503', $texts[1]);
        self::assertStringNotContainsString('Ann', implode("\n", $texts));
        self::assertCount(1, $this->failures);
    }

    public function testPermanentFailureDiesAtOnceWithoutIncident(): void
    {
        $this->crm = new PermanentFailure('duplicate lead');

        $this->alertLoop()->outbox('lead')->push(['name' => 'Ann']);

        self::assertSame('dead', $this->scalar('SELECT status FROM alt_outbox'));
        self::assertCount(1, $this->failures);
        $requests = FakeApi::requests();
        self::assertCount(1, $requests);
        self::assertStringContainsString('Attempts: 1. Last error: duplicate lead', $this->text($requests[0]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM alt_incidents'));
    }

    public function testRunResendsTheDeadReportThatNobodyAccepted(): void
    {
        $this->crm = new PermanentFailure('duplicate lead');
        $this->alertLoop(['to' => [['tg_down', '100']]])->outbox('lead')->push(['name' => 'Ann']);
        self::assertNull($this->scalar('SELECT reported_at FROM alt_outbox'));

        FakeApi::reset();
        $al = $this->alertLoop();
        $al->run();
        self::assertCount(0, FakeApi::requests(), 'a report younger than a minute may still be in flight');

        $this->age('finished_at', 1, 'outbox');
        $al->run();
        $al->run();

        self::assertCount(1, FakeApi::requests());
        self::assertNotNull($this->scalar('SELECT reported_at FROM alt_outbox'));
    }

    public function testRunDeletesSentAfter7DaysAndDeadAfter30(): void
    {
        $al = $this->alertLoop();
        $al->outbox('lead')->push(['n' => 1]);
        $al->outbox('lead')->push(['n' => 2]);
        $this->crm = new PermanentFailure('rejected');
        $al->outbox('lead')->push(['n' => 3]);
        $al->outbox('lead')->push(['n' => 4]);
        $this->pdo()->exec('UPDATE alt_outbox SET finished_at = finished_at - INTERVAL 8 DAY WHERE id IN (1, 3)');
        $this->pdo()->exec('UPDATE alt_outbox SET finished_at = finished_at - INTERVAL 31 DAY WHERE id = 4');

        $al->run();

        self::assertSame('2,3', $this->scalar('SELECT GROUP_CONCAT(id ORDER BY id) FROM alt_outbox'));
    }

    /**
     * Runs outbox-worker.php in $count processes at once.
     *
     * @param array<string, string> $env
     * @return list<string> what the handlers wrote: "<item> <pid>"
     */
    private function workers(int $count, array $env = []): array
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'alt');
        $env = array_merge(getenv(), $env, [
            'ALERTLOOP_TEST_CONFIG' => json_encode($this->config(), JSON_THROW_ON_ERROR),
            'ALERTLOOP_TEST_HANDLED' => $log,
            'ALERTLOOP_TEST_START' => (string) (microtime(true) + 1),
        ]);

        $workers = [];
        for ($i = 0; $i < $count; $i++) {
            $workers[] = proc_open(
                [PHP_BINARY, __DIR__ . '/Support/outbox-worker.php'],
                [1 => ['file', $log . '.out', 'a'], 2 => ['file', $log . '.out', 'a']],
                $pipes,
                null,
                $env,
            );
        }
        foreach ($workers as $worker) {
            self::assertIsResource($worker);
            self::assertSame(0, proc_close($worker), (string) @file_get_contents($log . '.out'));
        }
        $lines = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        unlink($log);
        @unlink($log . '.out');

        return $lines;
    }

    /**
     * @param array<string, mixed>|null $alerts
     */
    private function alertLoop(?array $alerts = null): AlertLoop
    {
        return new AlertLoop($this->outboxConfig($alerts));
    }

    private function queue(): OutboxQueue
    {
        $config = Config::fromArray($this->outboxConfig());

        return new OutboxQueue(
            $this->pdo(),
            self::PREFIX,
            $config->outboxes,
            new Incidents($this->pdo(), self::PREFIX),
            new Notices($config->source, $config->language, $config->timezone),
            new Notifier($config->channels, $config->alertsTo, $config->fallback),
        );
    }

    /**
     * @param array<string, mixed>|null $alerts
     * @return array<string, mixed>
     */
    private function outboxConfig(?array $alerts = null): array
    {
        return $this->config(array_filter([
            'alerts' => $alerts,
            'outbox' => ['lead' => [
                'handler' => function (array $payload): void {
                    $this->handled[] = $payload;
                    if ($this->crm !== null) {
                        throw $this->crm;
                    }
                },
                'on_failure' => function (array $payload, string $error): void {
                    $this->failures[] = [$payload, $error];
                },
            ]],
        ]));
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
