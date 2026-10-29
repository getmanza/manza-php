<?php

declare(strict_types=1);

// Shared by the three scripts: the SDK (and this example's classes, an
// autoload-dev entry) from the repository's vendor/, then .env.
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(fopen('php://stderr', 'w'), "Run `composer install` in the repository root first.\n");
    exit(1);
}
require $autoload;

Manza\Examples\TransferAuthorizer\Env::load(__DIR__ . '/.env');
