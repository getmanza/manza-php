<?php

declare(strict_types=1);

namespace Manza;

/**
 * Verifies incoming webhook deliveries. Pure functions — no HTTP.
 *
 * Every delivery carries `X-Manza-Signature` (hex HMAC-SHA256 of
 * "<timestamp>.<raw body>" under the endpoint's signing secret) and
 * `X-Manza-Timestamp` (Unix seconds, fresh on every attempt). The legacy
 * `X-Zazu-*` names carry the same values.
 *
 * Pass the **raw request body**, never re-encoded JSON:
 *
 *     $event = Webhook::constructEvent(
 *         payload: file_get_contents('php://input'), headers: $_SERVER, secret: $signingSecret,
 *     );
 *     $event['event']; // "payment.authorization_requested"
 */
final class Webhook
{
    /** Seconds a delivery's timestamp may differ from now. */
    public const DEFAULT_TOLERANCE = 300;

    private function __construct()
    {
    }

    /**
     * Hex HMAC-SHA256 of "<timestamp>.<payload>". For tests and local tools.
     */
    public static function sign(string $payload, string|int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }

    /**
     * True when the signature matches (constant time) and the timestamp is
     * within `$tolerance` seconds of `$now` (default: the current time). A
     * null `$tolerance` skips the timestamp check: only for replaying a
     * captured delivery in a local tool or test, never on a live endpoint.
     * Never throws on bad input (a missing header, an empty secret); returns
     * false.
     */
    public static function verify(
        ?string $payload,
        ?string $signature,
        string|int|null $timestamp,
        ?string $secret,
        ?int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): bool {
        return self::signatureMatches($payload, $signature, $timestamp, $secret)
            && self::timestampFresh($timestamp, $tolerance, $now);
    }

    /**
     * Reads X-Manza-Signature / X-Manza-Timestamp from `$headers` (names in
     * any case, the `$_SERVER` `HTTP_X_MANZA_*` form too, values a string or
     * a list as PSR-7 and Symfony give them; X-Zazu-* as a fallback),
     * verifies them, and returns the decoded JSON object as an associative
     * array.
     *
     * @param array<array-key, mixed> $headers
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when a header is missing, the
     *   signature does not match, the timestamp is outside tolerance, or the
     *   body is not a JSON object
     */
    public static function constructEvent(
        string $payload,
        array $headers,
        string $secret,
        ?int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): array {
        $normalized = self::normalizeHeaders($headers);
        $signature = self::header($normalized, 'signature');
        $timestamp = self::header($normalized, 'timestamp');

        if (!self::signatureMatches($payload, $signature, $timestamp, $secret)) {
            throw new \InvalidArgumentException('invalid webhook signature');
        }
        if (!self::timestampFresh($timestamp, $tolerance, $now)) {
            throw new \InvalidArgumentException('webhook timestamp outside tolerance');
        }

        try {
            $decoded = json_decode($payload, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('webhook payload is not JSON');
        }
        // Decoded as objects first: as arrays, `{}` and `[]` would look the same.
        if (!$decoded instanceof \stdClass) {
            throw new \InvalidArgumentException('webhook payload is not a JSON object');
        }

        return json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function signatureMatches(
        ?string $payload,
        ?string $signature,
        string|int|null $timestamp,
        ?string $secret,
    ): bool {
        if ($payload === null || $signature === null || $secret === null || $secret === '') {
            return false;
        }
        if (self::parseTimestamp($timestamp) === null) {
            return false;
        }

        return hash_equals(self::sign($payload, $timestamp, $secret), $signature);
    }

    private static function timestampFresh(string|int|null $timestamp, ?int $tolerance, ?int $now): bool
    {
        if ($tolerance === null) {
            return true;
        }

        $seconds = self::parseTimestamp($timestamp);

        return $seconds !== null && abs(($now ?? time()) - $seconds) <= $tolerance;
    }

    private static function parseTimestamp(string|int|null $timestamp): ?int
    {
        if (\is_int($timestamp)) {
            return $timestamp;
        }
        if ($timestamp === null || preg_match('/\A[+-]?\d+\z/', $timestamp) !== 1) {
            return null;
        }

        return (int) $timestamp;
    }

    /**
     * Lowercases names, drops a leading `HTTP_` and turns `_` into `-`, so
     * `HTTP_X_MANZA_SIGNATURE` and `X-Manza-Signature` both become
     * `x-manza-signature`. A list value keeps its first entry.
     *
     * @param array<array-key, mixed> $headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (\is_array($value)) {
                $value = reset($value);
            }
            if (!\is_string($value) && !\is_int($value)) {
                continue;
            }
            $key = strtolower(str_replace('_', '-', (string) preg_replace('/\AHTTP_/i', '', (string) $name)));
            $normalized[$key] = (string) $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, string> $normalized
     */
    private static function header(array $normalized, string $suffix): string
    {
        // X-Zazu-* only when X-Manza-* is absent: a present but empty
        // X-Manza-* header is missing, as in every other SDK.
        $value = $normalized["x-manza-{$suffix}"] ?? $normalized["x-zazu-{$suffix}"] ?? '';
        if ($value !== '') {
            return $value;
        }

        throw new \InvalidArgumentException('missing X-Manza-' . ucfirst($suffix) . ' header');
    }
}
