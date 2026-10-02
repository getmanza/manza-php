<?php

declare(strict_types=1);

namespace Manza\Exception;

/**
 * Thrown when the client can't be built (e.g. missing API key).
 */
final class ConfigurationException extends \InvalidArgumentException
{
}
