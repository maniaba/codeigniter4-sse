<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Mercure;

use Maniaba\CodeIgniterSse\Support\Channel;

final readonly class MercureTopicMapper
{
    public function __construct(
        private string $prefix,
    ) {
    }

    public function map(string $channel): string
    {
        return $this->prefix . Channel::from($channel)->value();
    }

    /**
     * @param list<string> $channels
     *
     * @return list<string>
     */
    public function mapAll(array $channels): array
    {
        return array_map($this->map(...), $channels);
    }
}
