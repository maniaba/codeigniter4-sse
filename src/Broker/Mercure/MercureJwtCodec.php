<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Mercure;

use JsonException;
use Maniaba\CodeIgniterSse\Broker\Mercure\Exception\MercureConfigurationException;

final class MercureJwtCodec
{
    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    public function unsigned(array $header, array $claims): string
    {
        return $this->encodeJsonObject($header, 'header')
            . '.' . $this->encodeJsonObject($claims, 'payload');
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    public function split(string $jwt, string $label = 'JWT'): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new MercureConfigurationException(
                sprintf('Mercure %s must be a compact JWT with three segments.', $label),
            );
        }

        return [$parts[0], $parts[1], $parts[2]];
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeJsonObjectSegment(string $segment, string $label): array
    {
        $decoded = base64_decode(
            strtr($segment . str_repeat('=', (4 - strlen($segment) % 4) % 4), '-_', '+/'),
            true,
        );

        if ($decoded === false) {
            throw new MercureConfigurationException(sprintf('Mercure JWT %s is not valid base64url.', $label));
        }

        try {
            $value = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MercureConfigurationException(
                sprintf('Mercure JWT %s is not valid JSON.', $label),
                0,
                $exception,
            );
        }

        if (! is_array($value)) {
            throw new MercureConfigurationException(sprintf('Mercure JWT %s must be a JSON object.', $label));
        }

        return $value;
    }

    public function encodeBytes(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $value
     */
    public function encodeJsonObject(array $value, string $label): string
    {
        try {
            return $this->encodeBytes(
                json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
        } catch (JsonException $exception) {
            throw new MercureConfigurationException(
                sprintf('Mercure JWT %s could not be encoded.', $label),
                0,
                $exception,
            );
        }
    }
}
