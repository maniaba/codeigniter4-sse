<?php

declare(strict_types=1);

namespace Tests\Broker\Mercure;

use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Factory\MercureSubscriptionFactory;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class MercureSubscriptionFactoryTest extends TestCase
{
    public function testSubscriberTokenIsRestrictedToRequestedTopics(): void
    {
        $config          = new Sse();
        $config->broker  = 'mercure';
        $config->mercure = [
            'publicHubUrl'       => 'https://example.test/.well-known/mercure',
            'topicPrefix'        => 'urn:example:sse:',
            'publisherKey'       => 'publisher-test-secret-at-least-32-bytes',
            'subscriberKey'      => 'subscriber-test-secret-at-least-32-bytes',
            'subscriberTokenTtl' => 600,
        ];

        $subscription = (new MercureSubscriptionFactory())->create(
            $config,
            ['users.42', 'projects.7'],
            1_700_000_000,
        );

        $this->assertSame(
            ['urn:example:sse:users.42', 'urn:example:sse:projects.7'],
            $subscription->topics,
        );
        $this->assertSame(1_700_000_600, $subscription->expiresAt);
        $this->assertNotNull($subscription->token);

        $parts = explode('.', $subscription->token);
        $this->assertCount(3, $parts);
        $claims = $this->decode($parts[1]);

        $this->assertSame(
            ['subscribe' => $subscription->topics],
            $claims['mercure'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $value): array
    {
        $padding = strlen($value) % 4;

        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $json = base64_decode(strtr($value, '-_', '+/'), true);
        $this->assertIsString($json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
