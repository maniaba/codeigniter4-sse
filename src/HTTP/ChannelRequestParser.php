<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\HTTP;

use Maniaba\CodeIgniterSse\Exception\InvalidChannelRequestException;
use Maniaba\CodeIgniterSse\Support\Channel;
use Maniaba\CodeIgniterSse\Support\ChannelPattern;

final readonly class ChannelRequestParser
{
    public function __construct(
        private int $maximumChannels = 20,
        private bool $allowPatterns = false,
    ) {
        if ($maximumChannels < 1) {
            throw new InvalidChannelRequestException('At least one requested channel must be allowed.');
        }
    }

    /**
     * @param array<array-key, mixed>|string|null $input
     *
     * @return list<string>
     */
    public function parse(array|string|null $input): array
    {
        if ($input === null || $input === '') {
            throw new InvalidChannelRequestException('The channels query parameter is required.');
        }

        $values = is_array($input) ? $input : [$input];
        $parts  = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidChannelRequestException('The channels query parameter must contain strings.');
            }

            foreach (explode(',', $value) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $parts[] = $part;
                }
            }
        }

        $parts = array_values(array_unique($parts));

        if ($parts === []) {
            throw new InvalidChannelRequestException('At least one channel is required.');
        }

        if (count($parts) > $this->maximumChannels) {
            throw new InvalidChannelRequestException(
                sprintf('A connection may subscribe to at most %d channels.', $this->maximumChannels),
            );
        }

        foreach ($parts as $part) {
            if ($this->allowPatterns && strpbrk($part, '*?[') !== false) {
                new ChannelPattern($part);

                continue;
            }

            Channel::from($part);
        }

        return $parts;
    }
}
