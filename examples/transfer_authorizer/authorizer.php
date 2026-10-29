<?php

declare(strict_types=1);

// The authorizer process (key B), a front controller for PHP's built-in
// server. Point your tunnel at the same port:
//
//   php -S 127.0.0.1:4599 authorizer.php
//
// It answers every request: POST the webhook to any path.

use Manza\Client;
use Manza\Examples\TransferAuthorizer\App;
use Manza\Examples\TransferAuthorizer\Ledger;

require __DIR__ . '/bootstrap.php';

$missing = array_values(array_filter(
    ['MANZA_AUTHORIZER_API_KEY', 'MANZA_AUTHORIZER_SECRET'],
    static fn (string $name): bool => (string) getenv($name) === '',
));
if ($missing !== []) {
    $message = sprintf('Set %s in %s', implode(' and ', $missing), __DIR__ . '/.env');
    App::stderrLogger()('error', $message);
    http_response_code(500);
    header('Content-Type: text/plain');
    echo $message;

    return;
}

// Steps 2-5 (ledger check, signing, authorize) run after the response is
// sent: the delivery has a timeout.
$jobs = [];
$app = new App(
    client: new Client(apiKey: (string) getenv('MANZA_AUTHORIZER_API_KEY')),
    secret: (string) getenv('MANZA_AUTHORIZER_SECRET'),
    ledger: new Ledger(__DIR__ . '/ledger.json'),
    executor: static function (\Closure $job) use (&$jobs): void {
        $jobs[] = $job;
    },
    allowNewPayees: !in_array(strtolower(trim((string) (getenv('ALLOW_NEW_PAYEES') ?: 'true'))), ['false', '0', 'no'], true),
);

// The raw body, never re-encoded JSON; $_SERVER carries HTTP_X_MANZA_*.
$status = $app->handle((string) file_get_contents('php://input'), $_SERVER);

// Answer first: Content-Length and Connection: close let Manza's client
// finish reading while this script carries on.
$body = $status === 200 ? 'ok' : 'unauthorized';
ignore_user_abort(true);
http_response_code($status);
header('Content-Type: text/plain');
header('Content-Length: ' . strlen($body));
header('Connection: close');
echo $body;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request(); // PHP-FPM
} else {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

foreach ($jobs as $job) {
    $job();
}
