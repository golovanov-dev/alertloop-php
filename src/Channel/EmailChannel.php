<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Channel;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * E-mail through PHPMailer, either PHP mail() or SMTP. Port 465 uses implicit
 * TLS; other ports switch to STARTTLS when the server offers it.
 */
class EmailChannel implements Channel
{
    /**
     * @param 'mail'|'smtp' $transport
     */
    public function __construct(
        private readonly string $from,
        private readonly string $transport = 'mail',
        private readonly string $host = '',
        private readonly int $port = 465,
        private readonly ?string $user = null,
        private readonly ?string $password = null,
    ) {
    }

    public function send(string $to, Message $message): Result
    {
        try {
            $mailer = $this->createMailer();
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->setFrom($this->from);
            $mailer->addAddress($to);
            $mailer->isHTML(false);
            $mailer->Subject = $message->title;
            $body = Render::plainBody($message);
            $mailer->Body = $body === '' ? $message->title : $body;
            $mailer->send();
        } catch (MailerException $e) {
            return Result::retryable($e->getMessage());
        }

        return Result::ok();
    }

    /**
     * mail(): that the function is enabled. SMTP: connects and logs in
     * without sending.
     *
     * @internal used by alertloop check
     * @return string what was checked
     * @throws \RuntimeException with the reason
     */
    public function check(): string
    {
        if ($this->transport !== 'smtp') {
            if (!function_exists('mail')) {
                throw new \RuntimeException('mail() is disabled');
            }

            return 'mail() is enabled';
        }
        $server = 'SMTP ' . $this->host . ':' . $this->port;
        try {
            $mailer = $this->createMailer();
            $mailer->smtpConnect();
            $mailer->smtpClose();
        } catch (MailerException $e) {
            throw new \RuntimeException($server . ': ' . $e->getMessage(), 0, $e);
        }

        return $server . ' accepts the connection' . ($this->user === null ? '' : ' and the login');
    }

    protected function createMailer(): PHPMailer
    {
        $mailer = new PHPMailer(true);
        if ($this->transport !== 'smtp') {
            $mailer->isMail();

            return $mailer;
        }
        $mailer->isSMTP();
        $mailer->Host = $this->host;
        $mailer->Port = $this->port;
        $mailer->Timeout = 10;
        if ($this->port === 465) {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }
        if ($this->user !== null) {
            $mailer->SMTPAuth = true;
            $mailer->Username = $this->user;
            $mailer->Password = (string) $this->password;
        }

        return $mailer;
    }
}
