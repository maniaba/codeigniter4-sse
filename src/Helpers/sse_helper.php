<?php

declare(strict_types=1);

use Maniaba\CodeIgniterSse\Sse;

if (! function_exists('sse')) {
    function sse(): Sse
    {
        $service = service('sse');

        if (! $service instanceof Sse) {
            throw new LogicException('The sse service must be an instance of ' . Sse::class . '.');
        }

        return $service;
    }
}
