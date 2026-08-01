<?php

declare(strict_types=1);

namespace Tests\Support\Adapter;

use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;

final class PublisherOnly implements PublisherInterface
{
    public function publish(string $channel, EventInterface $event): void
    {
    }
}
