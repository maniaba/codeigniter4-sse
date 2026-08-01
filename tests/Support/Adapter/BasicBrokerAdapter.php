<?php

declare(strict_types=1);

namespace Tests\Support\Adapter;

use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberAwareBrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriberInterface;
use Maniaba\CodeIgniterSse\Contracts\SubscriptionEndpointInterface;
use Tests\Support\RecordingPublisher;
use Tests\Support\RecordingSubscriber;

final class BasicBrokerAdapter implements SubscriberAwareBrokerAdapterInterface
{
    public function __construct(
        private readonly PublisherInterface $publisher = new RecordingPublisher(),
        private readonly SubscriberInterface $subscriber = new RecordingSubscriber(),
        private readonly SubscriptionEndpointInterface $endpoint = new BasicSubscriptionEndpoint(),
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
