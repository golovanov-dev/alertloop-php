<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop\Channel;

use Golovanov\AlertLoop\Severity;

/**
 * What a channel delivers. Channels render title, text and fields; key and
 * status ('firing' or 'resolved') describe the incident the message belongs
 * to, if any.
 */
final class Message
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        public readonly Severity $severity,
        public readonly string $title,
        public readonly string $text = '',
        public readonly array $fields = [],
        public readonly ?string $key = null,
        public readonly ?string $status = null,
    ) {
    }
}
