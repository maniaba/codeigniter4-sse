<?php

declare(strict_types=1);

namespace Tests\Broker\Mercure;

use Maniaba\CodeIgniterSse\Broker\Mercure\Exception\MercureConfigurationException;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureJwtFactory;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class MercureJwtFactoryTest extends TestCase
{
    private const SIGNING_KEY = 'test-secret-that-is-long-enough!';

    public function testCreatesSignedMercureClaimsWithExpiration(): void
    {
        $token = (new MercureJwtFactory())->create(
            ['subscribe' => ['urn:sse:users.42']],
            self::SIGNING_KEY,
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
            self::SIGNING_KEY,
            true,
        );

        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $header);
        $this->assertSame('maniaba/codeigniter4-sse', $claims['iss']);
        $this->assertSame('mercure', $claims['aud']);
        $this->assertSame('mercure-subscriber', $claims['sub']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $claims['jti']);
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

    public function testCreatesPublisherSubjectForPublisherTokens(): void
    {
        $token = (new MercureJwtFactory())->create(
            ['publish' => ['*']],
            self::SIGNING_KEY,
            issuedAt: 1_700_000_000,
        );
        [, $encodedClaims] = explode('.', $token);
        $claims            = $this->decode($encodedClaims);

        $this->assertSame('mercure-publisher', $claims['sub']);
    }

    public function testCreatesUniqueJwtIds(): void
    {
        $factory = new MercureJwtFactory();
        $first   = $factory->create(
            ['subscribe' => ['urn:sse:users.42']],
            self::SIGNING_KEY,
            issuedAt: 1_700_000_000,
        );
        $second = $factory->create(
            ['subscribe' => ['urn:sse:users.42']],
            self::SIGNING_KEY,
            issuedAt: 1_700_000_000,
        );

        [, $firstClaims]  = explode('.', $first);
        [, $secondClaims] = explode('.', $second);

        $this->assertNotSame(
            $this->decode($firstClaims)['jti'],
            $this->decode($secondClaims)['jti'],
        );
    }

    public function testRejectsSigningKeysShorterThan32Bytes(): void
    {
        $this->expectException(MercureConfigurationException::class);
        $this->expectExceptionMessage('The Mercure JWT signing key must be at least 32 bytes.');

        (new MercureJwtFactory())->create(
            ['subscribe' => ['urn:sse:users.42']],
            str_repeat('a', 31),
        );
    }

    public function testAllowsSigningKeyWith32Bytes(): void
    {
        $token = (new MercureJwtFactory())->create(
            ['subscribe' => ['urn:sse:users.42']],
            str_repeat('a', 32),
            issuedAt: 1_700_000_000,
        );

        $this->assertCount(3, explode('.', $token));
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
