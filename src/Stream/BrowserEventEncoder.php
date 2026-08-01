<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Stream;

use JsonException;
use Maniaba\CodeIgniterSse\Event\BrokerMessage;
use Maniaba\CodeIgniterSse\Exception\InvalidEventPayloadException;

final class BrowserEventEncoder
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    public function encode(BrokerMessage $message): string
    {
        try {
            return json_encode($message, self::JSON_FLAGS);
        } catch (JsonException $exception) {
            throw new InvalidEventPayloadException('The SSE browser payload could not be encoded.', 0, $exception);
        }
    }
}
