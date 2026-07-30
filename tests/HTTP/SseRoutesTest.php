<?php

declare(strict_types=1);

namespace Tests\HTTP;

use CodeIgniter\Config\Services as FrameworkServices;
use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Test\CIUnitTestCase;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\HTTP\SseController;
use Maniaba\CodeIgniterSse\HTTP\SseRoutes;

/**
 * @internal
 */
final class SseRoutesTest extends CIUnitTestCase
{
    public function testPackageRouteIsDiscoveredWithAbsoluteControllerNamespace(): void
    {
        $routes = single_service('routes');
        $this->assertInstanceOf(RouteCollection::class, $routes);

        $routes->loadRoutes();
        $getRoutes = $routes->getRoutes('GET');

        $this->assertArrayHasKey('sse', $getRoutes);
        $this->assertSame(
            '\\' . SseController::class . '::stream',
            $getRoutes['sse'],
        );
    }

    public function testRouteArrayCanRegisterApplicationRoutes(): void
    {
        $config        = new Sse();
        $config->route = [
            'path'       => 'live/events',
            'name'       => 'custom.sse.stream',
            'controller' => SseController::class,
            'method'     => 'stream',
            'filters'    => ['auth', 'sse-limit'],
        ];
        $routes = FrameworkServices::routes(false);

        SseRoutes::register($routes, $config);

        $this->assertSame(
            '\\' . SseController::class . '::stream',
            $routes->getRoutes('GET')['live/events'],
        );
    }

    public function testDisabledRouteArrayDisablesPackageRouteRegistration(): void
    {
        $config        = new Sse();
        $config->route = [
            'enabled' => false,
            'path'    => 'disabled-sse',
        ];
        $routes = FrameworkServices::routes(false);

        SseRoutes::register($routes, $config);

        $this->assertArrayNotHasKey('disabled-sse', $routes->getRoutes('GET'));
    }
}
