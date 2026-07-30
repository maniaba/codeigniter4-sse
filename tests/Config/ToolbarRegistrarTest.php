<?php

declare(strict_types=1);

namespace Tests\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Toolbar;
use Maniaba\CodeIgniterSse\Collectors\SseEvents;

/**
 * @internal
 */
final class ToolbarRegistrarTest extends CIUnitTestCase
{
    public function testToolbarCollectorIsRegisteredThroughCodeIgniterRegistrarDiscovery(): void
    {
        $toolbar = config(Toolbar::class);

        $this->assertContains(SseEvents::class, $toolbar->collectors);
    }
}
