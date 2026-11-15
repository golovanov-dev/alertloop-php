<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Channel;

/**
 * A delivery channel. The address is passed with every call, so one channel
 * instance serves any number of recipients.
 */
interface Channel
{
    public function send(string $to, Message $message): Result;
}
