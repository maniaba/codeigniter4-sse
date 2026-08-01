<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Mercure;

use Maniaba\CodeIgniterSse\Broker\Mercure\Exception\MercureConfigurationException;
use Random\RandomException;

final class MercureJwtFactory
{
    private const MINIMUM_KEY_BYTES = 32;
    private const HASH_ALGORITHMS   = [
        'HS256' => 'sha256',
        'HS384' => 'sha384',
        'HS512' => 'sha512',
    ];
    private const ISSUER             = 'maniaba/codeigniter4-sse';
    private const AUDIENCE           = 'mercure';
    private const PUBLISHER_SUBJECT  = 'mercure-publisher';
    private const SUBSCRIBER_SUBJECT = 'mercure-subscriber';

    public function __construct(
        private readonly ?MercureJwtCodec $codec = null,
    ) {
    }

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
        $codec    = $this->codec ?? new MercureJwtCodec();
        $unsigned = $codec->unsigned(['alg' => $algorithm, 'typ' => 'JWT'], [
            'iss'     => self::ISSUER,
            'aud'     => self::AUDIENCE,
            'sub'     => self::subject($mercure),
            'jti'     => self::jwtId(),
            'iat'     => $issuedAt,
            'exp'     => $issuedAt + $ttl,
            'mercure' => $mercure,
        ]);
        $signature = hash_hmac($hash, $unsigned, $key, true);

        return $unsigned . '.' . $codec->encodeBytes($signature);
    }

    /**
     * @param array{publish?: list<string>, subscribe?: list<string>} $mercure
     */
    private static function subject(array $mercure): string
    {
        if (isset($mercure['publish']) && ! isset($mercure['subscribe'])) {
            return self::PUBLISHER_SUBJECT;
        }

        if (isset($mercure['subscribe']) && ! isset($mercure['publish'])) {
            return self::SUBSCRIBER_SUBJECT;
        }

        return 'mercure-token';
    }

    private static function jwtId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (RandomException $exception) {
            throw new MercureConfigurationException(
                'The Mercure JWT ID could not be generated.',
                0,
                $exception,
            );
        }
    }
}
