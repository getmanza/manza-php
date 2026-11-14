<?php

declare(strict_types=1);

namespace Manza\Exception;

/**
 * A 422 whose `type` is `plan_limit_reached`: the entity's plan caps this
 * kind of record and the cap is reached. Retrying does not help until the
 * plan changes or the period rolls over.
 *
 * Its {@see ApiException::$kind} stays `validation`, so a catch on
 * {@see ApiException} still sees it. {@see PlanLimitReachedException::$limitKind}
 * names what is capped (e.g. "invoices"; the API's `kind`, renamed because
 * `$kind` is the SDK's discriminator), {@see PlanLimitReachedException::$current}
 * the count the entity holds and {@see PlanLimitReachedException::$max} the cap.
 */
final class PlanLimitReachedException extends ApiException
{
    /**
     * @param array<string, mixed> $body The full decoded error body
     */
    public function __construct(
        int $status,
        string $message,
        ?string $requestId,
        array $body,
        public readonly ?string $limitKind,
        public readonly ?int $current,
        public readonly ?int $max,
    ) {
        parent::__construct(
            status: $status,
            kind: 'validation',
            type: 'plan_limit_reached',
            message: $message,
            param: null,
            requestId: $requestId,
            retryAfter: null,
            body: $body,
        );
    }
}
