<?php

declare(strict_types=1);

namespace Manza\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads VCR YAML cassettes (recorded by manza-ruby) and replays them as a
 * Guzzle handler, so identical interactions replay against this SDK. The
 * contract is enforced cross-language: every SDK that consumes the
 * cassette tarball must replay the exact request shape.
 *
 * Matching is method + path+query + semantic JSON body (the host is
 * ignored — cassettes were recorded against ma.manza.dev). Unmatched
 * requests throw, failing the test.
 *
 * The machine-authorization cassettes (`transfer_drafts/authorize*`)
 * match the body with `signature` removed: the recorded signature is an
 * HMAC over a real nonce and secret that replay cannot reproduce. Load
 * those through {@see CassetteReplayHandler::clientIgnoringSignature()}.
 */
final class CassetteReplayHandler
{
    /** @var list<array{method: string, uri: string, requestBody: string, status: int, responseBody: string, ignoreSignature: bool}> */
    private array $interactions = [];

    private function __construct(private readonly bool $ignoreSignature)
    {
    }

    /**
     * Builds a Guzzle client that serves the named cassettes
     * (e.g. "payment_links/list").
     */
    public static function client(string ...$names): GuzzleClient
    {
        return self::build(false, $names);
    }

    /**
     * Like {@see client()}, but the request body is compared with its
     * `signature` key removed. Only for the authorize cassettes.
     */
    public static function clientIgnoringSignature(string ...$names): GuzzleClient
    {
        return self::build(true, $names);
    }

    /**
     * @param list<string> $names
     */
    private static function build(bool $ignoreSignature, array $names): GuzzleClient
    {
        $handler = new self($ignoreSignature);
        foreach ($names as $name) {
            $handler->loadCassette($name);
        }

        return new GuzzleClient(['handler' => HandlerStack::create($handler)]);
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $actualBody = (string) $request->getBody();

        foreach ($this->interactions as $interaction) {
            if ($this->matches($interaction, $request, $actualBody)) {
                return Create::promiseFor(new Psr7Response(
                    $interaction['status'],
                    ['Content-Type' => 'application/json; charset=utf-8'],
                    $interaction['responseBody'],
                ));
            }
        }

        throw new \RuntimeException(sprintf(
            'no cassette interaction matches %s %s (body %s)',
            $request->getMethod(),
            $request->getUri()->getPath()
                . ($request->getUri()->getQuery() !== '' ? '?' . $request->getUri()->getQuery() : ''),
            var_export($actualBody, true),
        ));
    }

    private function loadCassette(string $name): void
    {
        $path = __DIR__ . '/fixtures/cassettes/' . $name . '.yml';
        if (!is_file($path)) {
            throw new \RuntimeException(
                "read cassette {$path}: file not found (run scripts/fetch-cassettes.sh first)",
            );
        }

        $cassette = Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS);

        foreach ($cassette['http_interactions'] ?? [] as $interaction) {
            $this->interactions[] = [
                'method' => (string) ($interaction['request']['method'] ?? ''),
                'uri' => (string) ($interaction['request']['uri'] ?? ''),
                'requestBody' => self::bodyString($interaction['request']['body'] ?? []),
                'status' => (int) ($interaction['response']['status']['code'] ?? 200),
                'responseBody' => self::bodyString($interaction['response']['body'] ?? []),
                'ignoreSignature' => $this->ignoreSignature,
            ];
        }
    }

    /**
     * Handles VCR's body encodings. Ruby's Psych writes non-UTF-8 bodies as
     * base64 with the PRIMARY `!binary` tag (not the canonical `!!binary`),
     * which symfony/yaml surfaces as a TaggedValue — decode it ourselves.
     */
    private static function bodyString(mixed $body): string
    {
        $value = \is_array($body) ? ($body['string'] ?? '') : '';

        if ($value instanceof TaggedValue && $value->getTag() === 'binary') {
            $decoded = base64_decode(preg_replace('/\s+/', '', (string) $value->getValue()) ?? '', true);
            if ($decoded === false) {
                throw new \RuntimeException('decode !binary cassette body failed');
            }

            return $decoded;
        }

        return (string) $value;
    }

    /**
     * @param array{method: string, uri: string, requestBody: string, status: int, responseBody: string, ignoreSignature: bool} $interaction
     */
    private function matches(array $interaction, RequestInterface $request, string $actualBody): bool
    {
        if ($interaction['method'] !== '' && strcasecmp($interaction['method'], $request->getMethod()) !== 0) {
            return false;
        }

        $recorded = parse_url($interaction['uri']);
        if ($recorded === false) {
            return false;
        }
        if (($recorded['path'] ?? '') !== $request->getUri()->getPath()) {
            return false;
        }

        parse_str($recorded['query'] ?? '', $recordedQuery);
        parse_str($request->getUri()->getQuery(), $actualQuery);
        if (self::normalize($recordedQuery) !== self::normalize($actualQuery)) {
            return false;
        }

        if ($interaction['ignoreSignature']) {
            return self::jsonEqual(
                self::withoutSignature($interaction['requestBody']),
                self::withoutSignature($actualBody),
            );
        }

        return self::jsonEqual($interaction['requestBody'], $actualBody);
    }

    /**
     * Re-encodes a JSON object body without its `signature` key; any
     * other body is returned untouched.
     */
    private static function withoutSignature(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!\is_array($decoded) || array_is_list($decoded)) {
            return $body;
        }
        unset($decoded['signature']);

        return json_encode($decoded, \JSON_THROW_ON_ERROR);
    }

    /**
     * Compares two bodies semantically when both parse as JSON, and
     * byte-for-byte otherwise (empty matches empty).
     */
    private static function jsonEqual(string $recorded, string $actual): bool
    {
        if ($recorded === $actual) {
            return true;
        }

        try {
            $a = json_decode($recorded, true, 512, \JSON_THROW_ON_ERROR);
            $b = json_decode($actual, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return self::normalize($a) === self::normalize($b);
    }

    /**
     * Recursively sorts string-keyed arrays so key order never affects
     * equality (JSON arrays keep their order — list indices stay put).
     */
    private static function normalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $normalized = array_map(static fn (mixed $v): mixed => self::normalize($v), $value);
        if (!array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }
}
