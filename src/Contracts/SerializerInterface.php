<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

use Maniaba\CodeIgniterSse\Event\BrokerMessage;

interface SerializerInterface
{
    public function serialize(string $channel, EventInterface $event): string;

    public function deserialize(string $payload): BrokerMessage;
}
