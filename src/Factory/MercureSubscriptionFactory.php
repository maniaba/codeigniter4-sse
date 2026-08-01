<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Factory;

use Maniaba\CodeIgniterSse\Broker\Mercure\MercureConfigFactory;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureJwtFactory;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureSubscription;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureTopicMapper;
use Maniaba\CodeIgniterSse\Config\Sse;

final readonly class MercureSubscriptionFactory
{
    public function __construct(
        private ?MercureConfigFactory $configs = null,
        private ?MercureJwtFactory $tokens = null,
    ) {
    }

    /**
     * @param list<string> $channels
     */
    public function create(
        Sse $config,
        array $channels,
        ?int $issuedAt = null,
    ): MercureSubscription {
        $mercure = ($this->configs ?? new MercureConfigFactory())->create($config);
        $topics  = (new MercureTopicMapper($mercure->topicPrefix))->mapAll($channels);

        if (! $mercure->authorizeSubscribers) {
            return new MercureSubscription($mercure->publicHubUrl, $topics, null, null);
        }

        $issuedAt ??= time();
        $token = ($this->tokens ?? new MercureJwtFactory())->create(
            ['subscribe' => $topics],
            $mercure->subscriberKey ?? '',
            $mercure->subscriberAlgorithm,
            $mercure->subscriberTokenTtl,
            $issuedAt,
        );

        return new MercureSubscription(
            $mercure->publicHubUrl,
            $topics,
            $token,
            $issuedAt + $mercure->subscriberTokenTtl,
        );
    }
}
