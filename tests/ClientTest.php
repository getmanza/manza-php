<?php

declare(strict_types=1);

namespace Manza\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Manza\Client;
use Manza\Exception\ConfigurationException;
use Manza\Page;

/**
 * Mirror of manza-go's client_unit_test.go.
 */
final class ClientTest extends TestCase
{
    private const ENV_VARS = [
        'MANZA_API_KEY', 'MANZA_BASE_URL', 'MANZA_API_VERSION',
        'ZAZU_API_KEY', 'ZAZU_BASE_URL', 'ZAZU_API_VERSION',
    ];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    /** @var list<string> */
    private array $deprecations = [];

    protected function setUp(): void
    {
        foreach (self::ENV_VARS as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
        Client::resetDeprecationWarnings();
        $this->deprecations = [];
        set_error_handler(function (int $errno, string $errstr): bool {
            $this->deprecations[] = $errstr;

            return true;
        }, \E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    private function captureWith(?string $apiVersion = null, ?string $apiKey = null, ?string $baseUrl = null): RequestInterface
    {
        $captured = [];
        $client = new Client(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            apiVersion: $apiVersion,
            httpClient: new GuzzleClient(['handler' => HandlerStack::create(
                static function (RequestInterface $request) use (&$captured): PromiseInterface {
                    $captured[] = $request;

                    return Create::promiseFor(new Psr7Response(200, [], '{}'));
                },
            )]),
        );
        $client->request('GET', '/entity');

        return $captured[0];
    }

    public function testNewRequiresApiKey(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('MANZA_API_KEY');
        new Client();
    }

    public function testUserAgentIsManzaPhpWithVersion(): void
    {
        $request = $this->captureWith(apiKey: 'k');

        $this->assertSame('manza-php/' . Client::VERSION, $request->getHeaderLine('User-Agent'));
    }

    public function testSendsManzaVersionHeader(): void
    {
        $request = $this->captureWith(apiVersion: '2026-10-01', apiKey: 'k');

        $this->assertSame('2026-10-01', $request->getHeaderLine('Manza-Version'));
        $this->assertFalse($request->hasHeader('Zazu-Version'));
    }

    public function testReadsManzaEnvVars(): void
    {
        putenv('MANZA_API_KEY=mk');
        putenv('MANZA_BASE_URL=https://manza.example/');
        putenv('MANZA_API_VERSION=v-manza');

        $request = $this->captureWith();

        $this->assertSame('Bearer mk', $request->getHeaderLine('Authorization'));
        $this->assertSame('manza.example', $request->getUri()->getHost());
        $this->assertSame('v-manza', $request->getHeaderLine('Manza-Version'));
        $this->assertSame([], $this->deprecations);
    }

    public function testFallsBackToZazuEnvVarsWithDeprecation(): void
    {
        putenv('ZAZU_API_KEY=zk');
        putenv('ZAZU_BASE_URL=https://zazu.example');
        putenv('ZAZU_API_VERSION=v-zazu');

        $request = $this->captureWith();

        $this->assertSame('Bearer zk', $request->getHeaderLine('Authorization'));
        $this->assertSame('zazu.example', $request->getUri()->getHost());
        $this->assertSame('v-zazu', $request->getHeaderLine('Manza-Version'));
        $this->assertCount(3, $this->deprecations);
        $this->assertStringContainsString('ZAZU_API_KEY is deprecated; use MANZA_API_KEY', $this->deprecations[0]);
        $this->assertStringContainsString('ZAZU_BASE_URL is deprecated; use MANZA_BASE_URL', $this->deprecations[1]);
        $this->assertStringContainsString('ZAZU_API_VERSION is deprecated; use MANZA_API_VERSION', $this->deprecations[2]);
    }

    public function testManzaEnvVarWinsOverZazuWithoutWarning(): void
    {
        putenv('MANZA_API_KEY=mk');
        putenv('MANZA_BASE_URL=https://manza.example/');
        putenv('MANZA_API_VERSION=v-manza');
        putenv('ZAZU_API_KEY=zk');
        putenv('ZAZU_BASE_URL=https://zazu.example');
        putenv('ZAZU_API_VERSION=v-zazu');

        $request = $this->captureWith();

        $this->assertSame('Bearer mk', $request->getHeaderLine('Authorization'));
        $this->assertSame('manza.example', $request->getUri()->getHost());
        $this->assertSame('v-manza', $request->getHeaderLine('Manza-Version'));
        $this->assertSame([], $this->deprecations);
    }

    public function testEmptyManzaEnvVarDoesNotFallBackToZazu(): void
    {
        putenv('MANZA_API_KEY=');
        putenv('ZAZU_API_KEY=zk');

        try {
            new Client();
            $this->fail('expected ConfigurationException');
        } catch (ConfigurationException) {
            $this->assertSame([], $this->deprecations);
        }
    }

    public function testDeprecationWarningFiresOncePerVariable(): void
    {
        putenv('ZAZU_API_KEY=zk');

        new Client();
        new Client();
        new Client();

        $this->assertCount(1, $this->deprecations);
    }

    public function testExplicitArgumentsSkipEnvLookupAndWarning(): void
    {
        putenv('ZAZU_API_KEY=zk');

        $request = $this->captureWith(apiKey: 'explicit');

        $this->assertSame('Bearer explicit', $request->getHeaderLine('Authorization'));
        $this->assertSame([], $this->deprecations);
    }

    public function testDefaultBaseUrlIsProductionMorocco(): void
    {
        $this->assertSame('https://ma.manza.finance', Client::DEFAULT_BASE_URL);
    }

    public function testExposesPayeeTrustRequests(): void
    {
        $client = new Client(apiKey: 'test', baseUrl: 'http://127.0.0.1:1');

        $this->assertInstanceOf(\Manza\Resources\PayeeTrustRequests::class, $client->payeeTrustRequests);
    }

    public function testListLimitValidation(): void
    {
        $client = new Client(apiKey: 'test', baseUrl: 'http://127.0.0.1:1');

        $this->expectException(\InvalidArgumentException::class);
        $client->beneficiaries->list(limit: Page::MAX_PER_PAGE + 1);
    }
}
