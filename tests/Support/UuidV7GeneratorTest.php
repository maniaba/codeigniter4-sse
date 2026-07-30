<?php

declare(strict_types=1);

namespace Tests\Support;

use Maniaba\CodeIgniterSse\Support\UuidV7Generator;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class UuidV7GeneratorTest extends TestCase
{
    public function testGeneratedIdIsAnRfcVariantUuidV7(): void
    {
        $id = (new UuidV7Generator())->generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }
}
