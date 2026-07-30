<?php

declare(strict_types=1);

namespace Tests\HTTP;

use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Test\CIUnitTestCase;
use Maniaba\CodeIgniterSse\HTTP\SseController;

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
}
