<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

use Maniaba\CodeIgniterSse\Support\Channel;

interface PublishableEventInterface
{
    public function channel(): Channel|string;

    public function event(): EventInterface|string;

    /**
     * @return array<string, mixed>
     */
    public function data(): array;
}
