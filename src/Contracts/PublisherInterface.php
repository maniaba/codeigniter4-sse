<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

interface PublisherInterface
{
    public function publish(string $channel, EventInterface $event): void;
}
