<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop;

/**
 * One named outbox from the configuration; get it with AlertLoop::outbox().
 */
final class Outbox
{
    /**
     * @internal
     * @param \Closure(array<mixed>): int $push
     */
    public function __construct(private readonly \Closure $push)
    {
    }

    /**
     * Saves the payload in MySQL and returns its id. The handler gets it
     * after the response is sent to the visitor (in CLI at once); failed
     * attempts are retried by the cron run.
     *
     * @param array<mixed> $payload anything json_encode() accepts, in UTF-8
     * @throws \Throwable when the payload is not saved: the site must not report success
     */
    public function push(array $payload): int
    {
        return ($this->push)($payload);
    }
}
