<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Tests\Channel;

use Golovanov\AlertLoop\Channel\Message;
use Golovanov\AlertLoop\Channel\TelegramChannel;
use Golovanov\AlertLoop\Severity;
use Golovanov\AlertLoop\Tests\Support\FakeApi;
use PHPUnit\Framework\TestCase;

final class TelegramChannelTest extends TestCase
{
    protected function setUp(): void
    {
        FakeApi::reset();
    }

    public function testSendsEscapedHtmlToTheChat(): void
    {
        $message = new Message(Severity::Error, 'Order <b> & co', 'line "one"', ['a<b' => '1 > 0']);

        $result = (new TelegramChannel('123:abc', FakeApi::url() . '/'))->send('-1001234567890', $message);

        self::assertTrue($result->ok);
        $request = FakeApi::requests()[0];
        self::assertSame('/bot123:abc/sendMessage', $request['uri']);
        self::assertSame([
            'chat_id' => '-1001234567890',
            'text' => "<b>Order &lt;b&gt; &amp; co</b>\nline \"one\"\n\n<b>a&lt;b</b>: 1 &gt; 0",
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $request['body']);
    }

    public function testTooManyRequestsIsRetryableWithRetryAfter(): void
    {
        $result = $this->send('status-429');

        self::assertFalse($result->ok);
        self::assertFalse($result->permanent);
        self::assertSame(7, $result->retryAfter);
    }

    public function testForbiddenIsPermanent(): void
    {
        $result = $this->send('status-403');

        self::assertTrue($result->permanent);
        self::assertSame('HTTP 403: fake 403', $result->error);
    }

    public function testServerErrorIsRetryable(): void
    {
        $result = $this->send('status-502');

        self::assertFalse($result->ok);
        self::assertFalse($result->permanent);
        self::assertNull($result->retryAfter);
    }

    public function testUnreachableApiIsRetryable(): void
    {
        $channel = new TelegramChannel('token', 'http://127.0.0.1:' . FakeApi::closedPort());

        $result = $channel->send('1', new Message(Severity::Info, 'Hello'));

        self::assertFalse($result->ok);
        self::assertFalse($result->permanent);
        self::assertStringStartsWith('curl error 7:', $result->error);
    }

    private function send(string $token): \Golovanov\AlertLoop\Channel\Result
    {
        return (new TelegramChannel($token, FakeApi::url()))->send('1', new Message(Severity::Info, 'Hello'));
    }
}
