<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop;

/**
 * Thrown by an outbox handler when retrying is pointless, for example the
 * receiver rejected the data. The item becomes dead at once.
 */
class PermanentFailure extends \RuntimeException
{
}
