<?php

declare(strict_types=1);

namespace Zazu;

/**
 * Signs a machine-authorization challenge for an API-created transfer
 * draft. Pure functions — no HTTP.
 *
 * The `payment.authorization_requested` webhook delivers the
 * authorization id and a one-time nonce. Build the signature input
 * from your *own* record of the transfer (not the webhook's
 * `signature_input`, which is there only to compare against), sign
 * it with the authorizer endpoint's signing secret, and pass the
 * result to {@see Resources\TransferDrafts::authorize()}:
 *
 *     $input = TransferAuthorization::signatureInput(
 *         paymentId: $draft['id'], nonce: $nonce, amount: $draft['amount'],
 *         currencyCode: $draft['currency_code'], accountId: $draft['account_id'],
 *         payee: TransferAuthorization::payeeFor(externalAccountId: $draft['external_account_id']),
 *         clientReference: $draft['client_reference'],
 *     );
 *     $signature = TransferAuthorization::sign($signingSecret, $input);
 *     $authorizer->transferDrafts->authorize($draft['id'], $authorizationId, $signature);
 */
final class TransferAuthorization
{
    public const SIGNATURE_VERSION = 'manza.transfer-authorization.v1';

    private function __construct()
    {
    }

    /**
     * `$amount` must be the API's decimal string verbatim (e.g. "2500.0").
     * `$clientReference` is empty when the transfer has none.
     *
     * @throws \InvalidArgumentException when $amount is not a string
     */
    public static function signatureInput(
        string $paymentId,
        string $nonce,
        mixed $amount,
        string $currencyCode,
        string $accountId,
        string $payee,
        ?string $clientReference = null,
    ): string {
        if (!\is_string($amount)) {
            throw new \InvalidArgumentException(
                sprintf("amount must be the API's decimal string (got %s)", get_debug_type($amount)),
            );
        }

        return implode('|', [
            self::SIGNATURE_VERSION,
            $paymentId,
            $nonce,
            $amount,
            $currencyCode,
            $accountId,
            $payee,
            $clientReference ?? '',
        ]);
    }

    /**
     * Lowercase hex HMAC-SHA256 of the signature input under the
     * authorizer endpoint's signing secret.
     */
    public static function sign(string $secret, string $signatureInput): string
    {
        return hash_hmac('sha256', $signatureInput, $secret);
    }

    /**
     * The payee token: `ext:<id>` for a beneficiary's bank account,
     * `own:<id>` for one of the entity's own accounts. Pass exactly one.
     *
     * @throws \InvalidArgumentException unless exactly one id is given
     */
    public static function payeeFor(?string $externalAccountId = null, ?string $destinationAccountId = null): string
    {
        if (($externalAccountId === null) === ($destinationAccountId === null)) {
            throw new \InvalidArgumentException('pass exactly one of externalAccountId or destinationAccountId');
        }

        return $destinationAccountId !== null ? "own:{$destinationAccountId}" : "ext:{$externalAccountId}";
    }
}
