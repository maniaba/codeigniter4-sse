<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Mercure;

use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterFactoryInterface;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Factory\BrokerBuildContext;

final readonly class MercureBrokerAdapterFactory implements BrokerAdapterFactoryInterface
{
    public function __construct(
        private ?MercureConfigFactory $configs = null,
    ) {
    }

    public function create(Sse $config, BrokerBuildContext $context): BrokerAdapterInterface
    {
        $configs = $this->configs ?? new MercureConfigFactory();
        $mercure = $configs->create($config);

        return new MercureBrokerAdapter(
            $mercure,
            new MercurePublisher($mercure, $context->serializer),
            new MercureSubscriptionEndpoint($config, configs: $configs),
        );
    }
}
