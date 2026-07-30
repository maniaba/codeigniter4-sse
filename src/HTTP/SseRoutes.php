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

        $route = $config->route();

        if (! $route['enabled']) {
            return;
        }

        $options = $route['options'];

        if ($route['name'] !== null) {
            $options['as'] = $route['name'];
        }

        if ($route['filters'] !== null && $route['filters'] !== []) {
            $options['filter'] = is_array($route['filters'])
                ? implode('|', $route['filters'])
                : $route['filters'];
        }

        $routes->get(
            trim($route['path'], " /\t\n\r\0\x0B"),
            '\\' . $route['controller'] . '::' . $route['method'],
            $options,
        );
    }
}
