<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\HTTP;

use Maniaba\CodeIgniterSse\Contracts\ChannelSelectorValidatorInterface;
use Maniaba\CodeIgniterSse\Exception\InvalidChannelRequestException;
use Maniaba\CodeIgniterSse\Support\ChannelNameValidator;

final readonly class ChannelRequestParser
{
    private const MAXIMUM_CHANNEL_BYTES = 200;

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
        $bytes  = 0;

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidChannelRequestException('The channels query parameter must contain strings.');
            }

            $bytes += strlen($value);

            if ($bytes > $this->maximumInputBytes()) {
                throw new InvalidChannelRequestException(
                    sprintf('The channels query parameter must not exceed %d bytes.', $this->maximumInputBytes()),
                );
            }

            $this->parseValue($value, $parts);
        }

        $parts = array_keys($parts);

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

    /**
     * @param array<string, true> $parts
     */
    private function parseValue(string $value, array &$parts): void
    {
        $length = strlen($value);
        $start  = 0;

        for ($offset = 0; $offset <= $length; $offset++) {
            if ($offset !== $length && $value[$offset] !== ',') {
                continue;
            }

            $part = trim(substr($value, $start, $offset - $start));

            if ($part !== '') {
                $parts[$part] = true;
            }

            $start = $offset + 1;
        }
    }

    private function maximumInputBytes(): int
    {
        return $this->maximumChannels * self::MAXIMUM_CHANNEL_BYTES
            + max(0, $this->maximumChannels - 1);
    }
}
