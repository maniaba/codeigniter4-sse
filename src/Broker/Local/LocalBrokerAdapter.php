<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Local;

use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberAwareBrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriptionEndpointInterface;

final readonly class LocalBrokerAdapter implements SubscriberAwareBrokerAdapterInterface
{
    public function __construct(
        private PublisherInterface $publisher,
        private SubscriberInterface $subscriber,
        private SubscriptionEndpointInterface $endpoint,
    ) {
    }

    public function publisher(): PublisherInterface
    {
        return $this->publisher;
    }

    public function subscriber(): SubscriberInterface
    {
        return $this->subscriber;
    }

    public function subscriptionEndpoint(): SubscriptionEndpointInterface
    {
        return $this->endpoint;
    }
}
