<?php

declare(strict_types=1);

namespace Tests\Broker\Mercure;

use Maniaba\CodeIgniterSse\Broker\Mercure\Exception\MercureConfigurationException;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureJwtCodec;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class MercureJwtCodecTest extends TestCase
{
    public function testEncodesAndDecodesJsonObjectSegments(): void
    {
        $codec    = new MercureJwtCodec();
        $unsigned = $codec->unsigned(
            ['alg' => 'HS256', 'typ' => 'JWT'],
            ['exp' => 1_700_000_000, 'mercure' => ['publish' => ['urn:sse:{channel}']]],
        );

        [$header, $claims, $signature] = $codec->split($unsigned . '.signature');

        $this->assertSame('signature', $signature);
        $this->assertSame(
            ['alg' => 'HS256', 'typ' => 'JWT'],
            $codec->decodeJsonObjectSegment($header, 'header'),
        );
        $this->assertSame(
            ['exp' => 1_700_000_000, 'mercure' => ['publish' => ['urn:sse:{channel}']]],
            $codec->decodeJsonObjectSegment($claims, 'payload'),
        );
    }

    public function testRejectsNonCompactJwt(): void
    {
        $this->expectException(MercureConfigurationException::class);

        (new MercureJwtCodec())->split('not-a-jwt');
    }
}
