<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop;

use Golovanov\AlertLoop\Channel\Channel;
use Golovanov\AlertLoop\Channel\Message;

/**
 * Sends a message to every alert address; if any of them refuses, to every
 * fallback address as well. Refusals go to the PHP error log.
 *
 * @internal
 */
final class Notifier
{
    /**
     * @param array<string, Channel> $channels
     * @param list<array{string, string}> $to
     * @param list<array{string, string}> $fallback
     */
    public function __construct(
        private readonly array $channels,
        private readonly array $to,
        private readonly array $fallback,
    ) {
    }

    /**
     * @return bool whether at least one address accepted the message
     */
    public function deliver(Message $message): bool
    {
        $accepted = $this->sendAll($this->to, $message);
        if ($accepted < count($this->to)) {
            $accepted += $this->sendAll($this->fallback, $message);
        }

        return $accepted > 0;
    }

    /**
     * @param list<array{string, string}> $targets
     */
    private function sendAll(array $targets, Message $message): int
    {
        $accepted = 0;
        foreach ($targets as [$channel, $address]) {
            $result = $this->channels[$channel]->send($address, $message);
            if ($result->ok) {
                $accepted++;
            } else {
                error_log("alertloop: channel '{$channel}' refused '{$address}': {$result->error}");
            }
        }

        return $accepted;
    }
}
