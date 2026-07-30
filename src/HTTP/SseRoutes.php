<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\HTTP;

use CodeIgniter\Router\RouteCollection;
use Maniaba\CodeIgniterSse\Config\Sse;

final class SseRoutes
{
    public static function register(RouteCollection $routes, ?Sse $config = null): void
    {
        $config ??= Sse::discover();
        $config->validate();

        if (! $config->routeEnabled) {
            return;
        }

        $route   = trim($config->route, " /\t\n\r\0\x0B");
        $options = ['as' => $config->routeName];

        if ($config->routeFilters !== []) {
            $options['filter'] = implode('|', $config->routeFilters);
        }

        $routes->get(
            $route,
            '\\' . SseController::class . '::stream',
            $options,
        );
    }
}
