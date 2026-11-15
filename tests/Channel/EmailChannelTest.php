<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Tests\Channel;

use Golovanov\AlertLoop\Channel\EmailChannel;
use Golovanov\AlertLoop\Channel\Message;
use Golovanov\AlertLoop\Severity;
use Golovanov\AlertLoop\Tests\Support\FakeApi;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

final class EmailChannelTest extends TestCase
{
    public function testComposesPlainTextLetter(): void
    {
        $mailer = new class (true) extends PHPMailer {
            public string $sent = '';

            public function postSend()
            {
                $this->sent = $this->getSentMIMEMessage();

                return true;
            }
        };
        $channel = new class ('alerts@example.com', $mailer) extends EmailChannel {
            public function __construct(string $from, private readonly PHPMailer $mailer)
            {
                parent::__construct($from);
            }

            protected function createMailer(): PHPMailer
            {
                return $this->mailer;
            }
        };

        $result = $channel->send('ops@example.com', new Message(Severity::Error, 'Сайт: CRM', 'Ошибка', ['queue' => '3']));

        self::assertTrue($result->ok);
        self::assertStringContainsString('To: ops@example.com', $mailer->sent);
        self::assertStringContainsString('From: alerts@example.com', $mailer->sent);
        self::assertStringContainsString('Subject: =?utf-8?B?' . base64_encode('Сайт: CRM') . '?=', $mailer->sent);
        self::assertStringContainsString("Ошибка\r\n\r\nqueue: 3", quoted_printable_decode($mailer->sent));
    }

    public function testSmtpFailureIsRetryable(): void
    {
        $channel = new EmailChannel('alerts@example.com', 'smtp', '127.0.0.1', FakeApi::closedPort());

        $result = $channel->send('ops@example.com', new Message(Severity::Error, 'CRM'));

        self::assertFalse($result->ok);
        self::assertFalse($result->permanent);
        self::assertStringContainsString('SMTP', $result->error);
    }

    public function testCheckNamesTheSmtpServerItCannotReach(): void
    {
        $port = FakeApi::closedPort();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("SMTP 127.0.0.1:{$port}: ");
        (new EmailChannel('alerts@example.com', 'smtp', '127.0.0.1', $port))->check();
    }
}
