<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\HTTP;

use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorInterface;
use Maniaba\CodeIgniterSse\Exception\InvalidChannelRequestException;
use Maniaba\CodeIgniterSse\Support\ChannelNameValidator;

final readonly class ChannelRequestParser
{
    public function __construct(
        private int $maximumChannels = 20,
        private ?ChannelSelectorValidatorInterface $validator = null,
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

        $validator = $this->validator ?? new ChannelNameValidator();

        foreach ($parts as $part) {
            $validator->assertValid($part);
        }

        return $parts;
    }
}
