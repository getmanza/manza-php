<?php

declare(strict_types=1);

namespace Manza\Examples\TransferAuthorizer;

/**
 * Says where the API routed a new draft, from its `authorization`:
 * pending means the challenge went to the authorizer; refused, with a
 * `refusal_reason`, means it went to your in-app approvers.
 */
final class Routing
{
    /**
     * The reasons a draft can be refused when it is created. The transfer
     * authorization docs' troubleshooting table has the fix for each.
     */
    public const REFUSAL_REASONS = [
        'untrusted_payee' => "the payee's bank details are not trusted, and \"Approve transfers to new payees\" is off "
            . 'or their trust was revoked or they were edited',
        'unrestricted_api_key' => 'the payee is new and key A has no IP allowlist',
        'over_new_payee_limit' => 'the payee is new and the amount is over the limit per transfer to a new payee',
        'over_transfer_limit' => 'the amount is over the limit per transfer',
        'over_daily_limit' => "this amount plus the last 24 hours' authorized total is over the limit per 24 hours",
        'currency_mismatch' => "the account's currency is not the enrolled currency",
        'suspended' => 'transfer authorization is suspended, or its webhook endpoint is disabled',
        'secret_expired' => 'the signing secret has expired',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>|null $authorization
     */
    public static function describe(?array $authorization): string
    {
        if ($authorization === null) {
            return 'no transfer authorizer was enrolled (or the feature is off): sent to your approvers';
        }
        if (($authorization['status'] ?? null) === 'pending') {
            return 'challenge sent, waiting for the authorizer: watch the authorizer terminal';
        }

        $reason = $authorization['refusal_reason'] ?? null;
        $words = self::REFUSAL_REASONS[$reason] ?? "see the transfer authorization docs' troubleshooting table";

        return sprintf(
            'sent to your approvers, not the authorizer: %s (%s), %s',
            $authorization['status'] ?? '',
            $reason ?? '',
            $words,
        );
    }
}
