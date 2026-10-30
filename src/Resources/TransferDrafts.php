<?php

declare(strict_types=1);

namespace Manza\Resources;

/**
 * @deprecated Use {@see Payments} (`$client->payments`). Kept for all of
 *   1.x: `$client->transferDrafts` has the same methods and calls
 *   `/api/payments` too.
 */
final class TransferDrafts extends Payments
{
}
