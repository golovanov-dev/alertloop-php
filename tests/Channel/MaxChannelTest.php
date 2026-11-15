<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Tests\Channel;

use Golovanov\AlertLoop\Channel\MaxChannel;
use Golovanov\AlertLoop\Channel\Message;
use Golovanov\AlertLoop\Severity;
use Golovanov\AlertLoop\Tests\Support\FakeApi;
use PHPUnit\Framework\TestCase;

final class MaxChannelTest extends TestCase
{
    protected function setUp(): void
    {
        FakeApi::reset();
    }

    public function testSendsHtmlWithBareTokenHeader(): void
    {
        $channel = new MaxChannel('max-token', null, FakeApi::url());

        $result = $channel->send('-71234567', new Message(Severity::Warning, 'Disk', 'full'));

        self::assertTrue($result->ok);
        $request = FakeApi::requests()[0];
        self::assertSame('/messages?chat_id=-71234567', $request['uri']);
        self::assertSame('max-token', $request['headers']['Authorization']);
        self::assertSame(['text' => "<b>Disk</b>\nfull", 'format' => 'html'], $request['body']);
    }

    public function testTooManyRequestsTakesRetryAfterHeader(): void
    {
        $result = (new MaxChannel('status-429', null, FakeApi::url()))->send('1', new Message(Severity::Info, 'Hi'));

        self::assertFalse($result->permanent);
        self::assertSame(7, $result->retryAfter);
    }

    public function testNotFoundIsPermanent(): void
    {
        $result = (new MaxChannel('status-404', null, FakeApi::url()))->send('1', new Message(Severity::Info, 'Hi'));

        self::assertTrue($result->permanent);
        self::assertSame('HTTP 404: fake 404', $result->error);
    }

    public function testCheckAsksForTheBotWithTheToken(): void
    {
        self::assertSame('fake_bot', (new MaxChannel('max-token', null, FakeApi::url()))->check());

        $request = FakeApi::requests()[0];
        self::assertSame('/me', $request['uri']);
        self::assertSame('max-token', $request['headers']['Authorization']);
    }
}
