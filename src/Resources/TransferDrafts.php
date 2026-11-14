<?php

declare(strict_types=1);

namespace Manza\Resources;

/**
 * @deprecated Use {@see Payments} (`$client->payments`). Kept in 1.x
 *   and 2.0: `$client->transferDrafts` has the same methods and calls
 *   `/api/payments` too.
 */
final class TransferDrafts extends Payments
{
}
