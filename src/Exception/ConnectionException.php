<?php

declare(strict_types=1);

namespace Manza\Exception;

/**
 * Wraps transport-level failures (timeouts, DNS, connection refused).
 */
final class ConnectionException extends \RuntimeException
{
}
