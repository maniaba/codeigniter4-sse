<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Mercure;

final readonly class MercureSubscription
{
    /**
     * @param list<string> $topics
     */
    public function __construct(
        public string $hubUrl,
        public array $topics,
        public ?string $token,
        public ?int $expiresAt,
    ) {
    }
}
