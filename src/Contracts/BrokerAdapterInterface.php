<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

interface BrokerAdapterInterface
{
    public function publisher(): PublisherInterface;

    public function subscriptionEndpoint(): SubscriptionEndpointInterface;
}
