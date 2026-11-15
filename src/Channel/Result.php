<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Channel;

/**
 * Outcome of one send: accepted, worth retrying, or rejected for good.
 */
final class Result
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $permanent,
        public readonly string $error,
        public readonly ?int $retryAfter,
    ) {
    }

    public static function ok(): self
    {
        return new self(true, false, '', null);
    }

    /**
     * @param int|null $retryAfter seconds the receiver asked to wait, if it said so
     */
    public static function retryable(string $error, ?int $retryAfter = null): self
    {
        return new self(false, false, $error, $retryAfter);
    }

    public static function permanent(string $error): self
    {
        return new self(false, true, $error, null);
    }
}
