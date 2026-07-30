<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Support;

use Maniaba\CodeIgniterSse\Contracts\EventIdGeneratorInterface;

final class UuidV7Generator implements EventIdGeneratorInterface
{
    public function generate(): string
    {
        $milliseconds = (int) floor(microtime(true) * 1000);
        $hex          = sprintf('%012x', $milliseconds) . bin2hex(random_bytes(10));

        // RFC 9562: version 7 and the RFC 4122 variant.
        $hex[12] = '7';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
