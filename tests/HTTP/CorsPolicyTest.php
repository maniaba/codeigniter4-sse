<?php

declare(strict_types=1);

namespace Tests\HTTP;

use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Maniaba\CodeIgniterSse\Exception\InvalidOriginException;
use Maniaba\CodeIgniterSse\HTTP\CorsPolicy;

/**
 * @internal
 */
final class CorsPolicyTest extends CIUnitTestCase
{
    public function testAppliesExactCredentialedOriginAndVaryHeader(): void
    {
        $response = single_service('response');
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $policy = new CorsPolicy(['https://app.example.com'], true);

        $policy->assertAllowed('https://app.example.com');
        $result = $policy->apply($response, 'https://app.example.com');

        $this->assertSame('https://app.example.com', $result->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $result->getHeaderLine('Access-Control-Allow-Credentials'));
        $this->assertSame('Origin', $result->getHeaderLine('Vary'));
    }

    public function testAllowsWildcardOnlyWithoutCredentials(): void
    {
        $response = single_service('response');
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $policy = new CorsPolicy(['*'], false);

        $policy->assertAllowed('https://other.example.com');
        $result = $policy->apply($response, 'https://other.example.com');

        $this->assertSame('*', $result->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('', $result->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testRejectsUnknownCrossOriginRequest(): void
    {
        $policy = new CorsPolicy(['https://app.example.com'], true);

        $this->expectException(InvalidOriginException::class);

        $policy->assertAllowed('https://attacker.example.com');
    }
}
