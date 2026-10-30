<?php

declare(strict_types=1);

// Checks every setup step before you create a transfer, and says how to fix
// the ones that fail:
//
//   php preflight.php          # for a 10.00 transfer
//   php preflight.php 250.00
//
// ✓ passed, ✗ failed (exit status 1), ! cannot be checked through the API.

use Manza\Examples\TransferAuthorizer\Preflight;

require __DIR__ . '/bootstrap.php';

exit((new Preflight(env: getenv(), rawAmount: $argv[1] ?? '10.00'))->run() ? 0 : 1);
