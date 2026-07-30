<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Config;

use Maniaba\CodeIgniterSse\Collectors\SseEvents;

final class Registrar
{
    /**
     * @return array{collectors: list<class-string>}
     */
    public static function Toolbar(): array
    {
        return [
            'collectors' => [
                SseEvents::class,
            ],
        ];
    }
}
