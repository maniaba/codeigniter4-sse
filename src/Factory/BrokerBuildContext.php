<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Factory;

use Maniaba\CodeIgniterSse\Contracts\SerializerInterface;
use Maniaba\CodeIgniterSse\Event\EventFactory;

final readonly class BrokerBuildContext
{
    public function __construct(
        public SerializerInterface $serializer,
        public EventFactory $events,
    ) {
    }
}
