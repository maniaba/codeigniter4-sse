<?php

declare(strict_types=1);

namespace Tests\Broker\Mercure;

use Maniaba\CodeIgniterSse\Broker\Mercure\MercureJwtFactory;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class MercureJwtFactoryTest extends TestCase
{
    public function testCreatesSignedMercureClaimsWithExpiration(): void
    {
        $token = (new MercureJwtFactory())->create(
            ['subscribe' => ['urn:sse:users.42']],
            'test-secret-that-is-long-enough',
            'HS256',
            600,
            1_700_000_000,
        );
        [$encodedHeader, $encodedClaims, $encodedSignature] = explode('.', $token);
        $header                                             = $this->decode($encodedHeader);
        $claims                                             = $this->decode($encodedClaims);
        $expectedSignature                                  = hash_hmac(
            'sha256',
            $encodedHeader . '.' . $encodedClaims,
            'test-secret-that-is-long-enough',
            true,
        );

        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $header);
        $this->assertSame(1_700_000_000, $claims['iat']);
        $this->assertSame(1_700_000_600, $claims['exp']);
        $this->assertSame(
            ['subscribe' => ['urn:sse:users.42']],
            $claims['mercure'],
        );
        $this->assertSame(
            rtrim(strtr(base64_encode($expectedSignature), '+/', '-_'), '='),
            $encodedSignature,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $value): array
    {
        $padding = strlen($value) % 4;

        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $json = base64_decode(strtr($value, '-_', '+/'), true);
        $this->assertIsString($json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
