<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Factory;

use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;
use Maniaba\CodeIgniterSse\Stream\SseConnectionManager;

final class ConnectionManagerFactory
{
    public function create(Sse $config): SseConnectionManager
    {
        $serializer = new JsonEventSerializer();

        return new SseConnectionManager(
            (new BrokerFactory($serializer))->subscriber($config),
            $serializer,
            new EventFactory(),
            $config->heartbeatInterval,
            $config->maxConnectionSeconds,
            $config->retryMilliseconds,
            $config->emitConnectedEvent,
        );
    }
}
