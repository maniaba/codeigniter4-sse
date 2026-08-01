<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Factory\BrokerBuildContext;

interface BrokerAdapterFactoryInterface
{
    public function create(Sse $config, BrokerBuildContext $context): BrokerAdapterInterface;
}
