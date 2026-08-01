<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Config;

use CodeIgniter\Config\BaseService;
use LogicException;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Factory\BrokerFactory;
use Maniaba\CodeIgniterSse\Sse as SseManager;

class Services extends BaseService
{
    public static function sse(
        ?PublisherInterface $publisher = null,
        ?EventFactory $events = null,
        bool $getShared = true,
    ): SseManager {
        if ($getShared) {
            $service = static::getSharedInstance('sse', $publisher, $events);

            if (! $service instanceof SseManager) {
                throw new LogicException('The shared sse service must be an instance of ' . SseManager::class . '.');
            }

            return $service;
        }

        $publisher ??= service('ssePublisher');

        if (! $publisher instanceof PublisherInterface) {
            throw new LogicException('The ssePublisher service must implement ' . PublisherInterface::class . '.');
        }

        return new SseManager(
            $publisher,
            $events ?? new EventFactory(),
        );
    }

    public static function ssePublisher(
        ?Sse $config = null,
        bool $getShared = true,
    ): PublisherInterface {
        if ($getShared) {
            $service = static::getSharedInstance('ssePublisher', $config);

            if (! $service instanceof PublisherInterface) {
                throw new LogicException('The shared ssePublisher service must implement ' . PublisherInterface::class . '.');
            }

            return $service;
        }

        $config ??= Sse::discover();
        $config->validate();

        return (new BrokerFactory())->publisherFromAdapter(
            $config,
            static::sseBrokerAdapter($config, $getShared),
        );
    }

    public static function sseBrokerAdapter(
        ?Sse $config = null,
        bool $getShared = true,
    ): BrokerAdapterInterface {
        if ($getShared) {
            $service = static::getSharedInstance('sseBrokerAdapter', $config);

            if (! $service instanceof BrokerAdapterInterface) {
                throw new LogicException(
                    'The shared sseBrokerAdapter service must implement ' . BrokerAdapterInterface::class . '.',
                );
            }

            return $service;
        }

        $config ??= Sse::discover();
        $config->validate();

        return (new BrokerFactory())->adapter($config);
    }
}
