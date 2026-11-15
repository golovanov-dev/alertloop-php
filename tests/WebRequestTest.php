<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Tests;

use Golovanov\AlertLoop\Tests\Support\DatabaseTestCase;
use Golovanov\AlertLoop\Tests\Support\FakeApi;
use Golovanov\AlertLoop\Tests\Support\PhpServer;

final class WebRequestTest extends DatabaseTestCase
{
    public function testAlertAndFirstOutboxAttemptWaitUntilTheRequestEnds(): void
    {
        $page = PhpServer::start(__DIR__ . '/Support/web-page.php', [
            'ALERTLOOP_TEST_API' => FakeApi::url(),
            'ALERTLOOP_TEST_CONFIG' => json_encode($this->config(), JSON_THROW_ON_ERROR),
            'ALERTLOOP_FAKE_API_LOG' => FakeApi::logFile(),
        ]);

        $callsDuringRequest = file_get_contents($page . '/');

        self::assertSame('0', $callsDuringRequest);
        self::assertSame(
            ['/botmain/sendMessage', '/lead?session=' . PHP_SESSION_NONE . '&name=Ann'],
            array_column(FakeApi::requests(), 'uri'),
            'after the request: the alert, then the attempt, with the session already closed',
        );
        self::assertSame('sent', $this->scalar('SELECT status FROM alt_outbox'));
    }
}
