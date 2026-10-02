<?php

declare(strict_types=1);

namespace Zazu\Tests;

use PHPUnit\Framework\TestCase;
use Zazu\Client;
use Zazu\Exception\ConfigurationException;
use Zazu\Page;

/**
 * Mirror of zazu-go's client_unit_test.go.
 */
final class ClientTest extends TestCase
{
    public function testNewRequiresApiKey(): void
    {
        $previous = getenv('ZAZU_API_KEY');
        putenv('ZAZU_API_KEY');

        try {
            $this->expectException(ConfigurationException::class);
            new Client();
        } finally {
            if ($previous !== false) {
                putenv("ZAZU_API_KEY={$previous}");
            }
        }
    }

    public function testDefaultBaseUrlIsProductionMorocco(): void
    {
        $this->assertSame('https://ma.manza.finance', Client::DEFAULT_BASE_URL);
    }

    public function testExposesPayeeTrustRequests(): void
    {
        $client = new Client(apiKey: 'test', baseUrl: 'http://127.0.0.1:1');

        $this->assertInstanceOf(\Zazu\Resources\PayeeTrustRequests::class, $client->payeeTrustRequests);
    }

    public function testListLimitValidation(): void
    {
        $client = new Client(apiKey: 'test', baseUrl: 'http://127.0.0.1:1');

        $this->expectException(\InvalidArgumentException::class);
        $client->beneficiaries->list(limit: Page::MAX_PER_PAGE + 1);
    }
}
