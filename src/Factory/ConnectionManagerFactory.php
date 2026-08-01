<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Factory;

use LogicException;
use Maniaba\CodeIgniterSse\Config\Services as SseServices;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\SubscriberAwareBrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;
use Maniaba\CodeIgniterSse\Stream\SseConnectionOptions;

final class ConnectionManagerFactory
{
    public function create(Sse $config): SseConnectionManager
    {
        $serializer = new JsonEventSerializer();
        $adapter    = SseServices::sseBrokerAdapter($config, false);

        if (! $adapter instanceof SubscriberAwareBrokerAdapterInterface) {
            throw new LogicException('The configured SSE broker does not provide a PHP subscriber.');
        }

        return new SseConnectionManager(
            $adapter->subscriber(),
            $serializer,
            new EventFactory(),
            SseConnectionOptions::fromConfig($config),
        );
    }
}
