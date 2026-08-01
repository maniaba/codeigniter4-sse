<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Mercure;

use JsonException;
use Maniaba\CodeIgniterSse\Broker\Mercure\Exception\MercureConfigurationException;

final class MercureJwtFactory
{
    private const MINIMUM_KEY_BYTES = 32;
    private const HASH_ALGORITHMS   = [
        'HS256' => 'sha256',
        'HS384' => 'sha384',
        'HS512' => 'sha512',
    ];

    /**
     * @param array{publish?: list<string>, subscribe?: list<string>} $mercure
     */
    public function create(
        array $mercure,
        string $key,
        string $algorithm = 'HS256',
        int $ttl = 300,
        ?int $issuedAt = null,
    ): string {
        if (strlen($key) < self::MINIMUM_KEY_BYTES) {
            throw new MercureConfigurationException(
                sprintf('The Mercure JWT signing key must be at least %d bytes.', self::MINIMUM_KEY_BYTES),
            );
        }

        $hash = self::HASH_ALGORITHMS[$algorithm] ?? null;

        if ($hash === null) {
            throw new MercureConfigurationException(
                sprintf('Unsupported Mercure JWT algorithm "%s".', $algorithm),
            );
        }

        if ($ttl < 1) {
            throw new MercureConfigurationException('The Mercure JWT lifetime must be positive.');
        }

        $issuedAt ??= time();
        $header = $this->encode(['alg' => $algorithm, 'typ' => 'JWT']);
        $claims = $this->encode([
            'iat'     => $issuedAt,
            'exp'     => $issuedAt + $ttl,
            'mercure' => $mercure,
        ]);
        $unsigned  = $header . '.' . $claims;
        $signature = hash_hmac($hash, $unsigned, $key, true);

        return $unsigned . '.' . self::base64UrlEncode($signature);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function encode(array $value): string
    {
        try {
            return self::base64UrlEncode(
                json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
        } catch (JsonException $exception) {
            throw new MercureConfigurationException(
                'The Mercure JWT claims could not be encoded.',
                0,
                $exception,
            );
        }
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
