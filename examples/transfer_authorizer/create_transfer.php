<?php

declare(strict_types=1);

// The creator process (key A). Creates a transfer draft, writes it to the
// ledger from the API's response, and says where the API routed it.
//
//   php create_transfer.php          # 10.00
//   php create_transfer.php 250.00

use Manza\Client;
use Manza\Examples\TransferAuthorizer\Ledger;
use Manza\Examples\TransferAuthorizer\Routing;
use Manza\Exception\ApiException;
use Manza\Exception\ConnectionException;
use Manza\TransferAuthorization;

require __DIR__ . '/bootstrap.php';

$amount = $argv[1] ?? '10.00';
$clientReference = 'example-' . bin2hex(random_bytes(6));
$ledger = new Ledger(__DIR__ . '/ledger.json');
$creator = new Client(apiKey: (string) getenv('MANZA_CREATOR_API_KEY'));

try {
    $draft = $creator->payments->create([
        'account_id' => (string) getenv('MANZA_ACCOUNT_ID'),
        'beneficiary_id' => (string) getenv('MANZA_BENEFICIARY_ID'),
        'amount' => $amount,
        'payment_reference' => 'Transfer authorizer example',
        'client_reference' => $clientReference,
    ])->body;
} catch (ApiException|ConnectionException $e) {
    // A timeout may still have created the draft: look for this client_reference
    // in the app before rerunning, or the rerun creates a second transfer.
    fwrite(STDERR, sprintf(
        "create failed for client_reference %s: %s: %s (type %s, request_id %s)\n",
        $clientReference,
        $e::class,
        $e->getMessage(),
        json_encode($e instanceof ApiException ? $e->type : null),
        json_encode($e instanceof ApiException ? $e->requestId : null),
    ));
    exit(1);
}

$destination = $draft['destination_account_id'] ?? null;
try {
    $payee = TransferAuthorization::payeeFor(
        externalAccountId: $destination === null ? ($draft['external_account_id'] ?? null) : null,
        destinationAccountId: $destination,
    );
} catch (\InvalidArgumentException) {
    fwrite(STDERR, "payment {$draft['id']} names neither an external_account_id nor a destination_account_id, so it was not "
        . "written to the ledger: if it reaches the authorizer, the authorizer declines it\n");
    exit(1);
}

try {
    $ledger->record(
        $clientReference,
        paymentId: $draft['id'],
        amount: $draft['amount'],
        currencyCode: $draft['currency_code'],
        accountId: $draft['account_id'],
        payee: $payee,
    );
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf(
        "payment %s (client_reference %s) was created but not written to the ledger (%s: %s): "
            . "if it reaches the authorizer, the authorizer declines it\n",
        $draft['id'],
        $clientReference,
        $e::class,
        $e->getMessage(),
    ));
    exit(1);
}
echo "created payment {$draft['id']}: {$draft['amount']} {$draft['currency_code']} to {$payee} (client_reference {$clientReference})\n";

echo Routing::describe($draft['authorization'] ?? null), "\n";
