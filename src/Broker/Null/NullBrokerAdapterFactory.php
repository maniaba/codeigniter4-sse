<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Null;

use Maniaba\CodeIgniterSse\Broker\Local\LocalBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterFactoryInterface;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Factory\BrokerBuildContext;

final readonly class NullBrokerAdapterFactory implements BrokerAdapterFactoryInterface
{
    public function create(Sse $config, BrokerBuildContext $context): BrokerAdapterInterface
    {
        return (new LocalBrokerAdapterFactory(NullBroker::class))->create($config, $context);
    }
}
