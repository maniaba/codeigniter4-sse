<?php

declare(strict_types=1);

namespace Tests\Support\Adapter;

use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterFactoryInterface;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Factory\BrokerBuildContext;

final class BasicBrokerAdapterFactory implements BrokerAdapterFactoryInterface
{
    public static int $created                     = 0;
    public static ?BrokerAdapterInterface $adapter = null;

    public static function reset(): void
    {
        self::$created = 0;
        self::$adapter = null;
    }

    public function create(Sse $config, BrokerBuildContext $context): BrokerAdapterInterface
    {
        self::$created++;

        return self::$adapter ?? new BasicBrokerAdapter();
    }
}
