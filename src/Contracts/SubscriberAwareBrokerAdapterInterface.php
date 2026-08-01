<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

interface SubscriberAwareBrokerAdapterInterface extends BrokerAdapterInterface
{
    public function subscriber(): SubscriberInterface;
}
