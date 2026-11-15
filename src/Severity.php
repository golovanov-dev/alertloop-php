<?php

declare(strict_types=1);

namespace Golovanov\AlertLoop;

/**
 * Incident severity, same values as the AlertLoop server.
 */
enum Severity: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
}
