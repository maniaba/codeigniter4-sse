<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

use Maniaba\CodeIgniterSse\Event\BrokerMessage;

interface SubscriberInterface
{
    /**
     * @param list<string>                 $channels
     * @param callable(BrokerMessage):void $onMessage
     * @param (callable():bool)|null       $shouldStop
     * @param (callable():void)|null       $onIdle
     */
    public function subscribe(
        array $channels,
        callable $onMessage,
        ?callable $shouldStop = null,
        ?callable $onIdle = null,
    ): void;
}
