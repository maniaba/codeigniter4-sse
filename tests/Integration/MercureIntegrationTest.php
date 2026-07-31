<?php

declare(strict_types=1);

namespace Tests\Integration;

use CurlMultiHandle;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercurePublisher;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;
use Maniaba\CodeIgniterSse\Event\SseEvent;
use Maniaba\CodeIgniterSse\Factory\MercureConfigFactory;
use Maniaba\CodeIgniterSse\Factory\MercureSubscriptionFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[Group('integration')]
final class MercureIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('SSE_MERCURE_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set SSE_MERCURE_INTEGRATION=1 to run live Mercure tests.');
        }

        if (! function_exists('curl_multi_init')) {
            $this->markTestSkipped('The live Mercure test requires the PHP cURL extension.');
        }
    }

    public function testPrivatePublisherAndSubscriberExchangeAnEvent(): void
    {
        $suffix       = bin2hex(random_bytes(8));
        $channel      = 'integration.' . $suffix;
        $eventId      = 'integration-' . $suffix;
        $config       = $this->config();
        $mercure      = (new MercureConfigFactory())->create($config);
        $subscription = (new MercureSubscriptionFactory())->create(
            $config,
            [$channel],
        );

        $this->assertNotNull($subscription->token);
        $topic = $subscription->topics[0];
        $url   = $subscription->hubUrl . '?' . http_build_query(['topic' => $topic]);
        $body  = '';
        $ready = false;
        $curl  = curl_init($url);

        if ($curl === false) {
            $this->fail('Unable to initialize the Mercure subscriber.');
        }

        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER     => ['Accept: text/event-stream'],
            CURLOPT_COOKIE         => $mercure->cookieName . '=' . $subscription->token,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_WRITEFUNCTION  => static function ($handle, string $chunk) use (&$body): int {
                $body .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$ready): int {
                if (str_starts_with(strtolower($header), strtolower('content-type: text/event-stream'))) {
                    $ready = true;
                }

                return strlen($header);
            },
        ]);

        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $curl);

        try {
            $this->waitUntil($multi, static function () use (&$ready): bool {
                return $ready;
            });

            $publisher = new MercurePublisher(
                $mercure,
                new JsonEventSerializer(),
            );
            $publisher->publish(
                $channel,
                new SseEvent(
                    'integration.updated',
                    ['ok' => true],
                    $eventId,
                ),
            );

            $this->waitUntil(
                $multi,
                static function () use (&$body): bool {
                    return str_contains($body, 'event: integration.updated');
                },
            );

            $this->assertStringContainsString('id: ' . $eventId, $body);
            $this->assertStringContainsString('"channel":"' . $channel . '"', $body);
            $this->assertStringContainsString('"data":{"ok":true}', $body);
        } finally {
            curl_multi_remove_handle($multi, $curl);
            curl_multi_close($multi);
            curl_close($curl);
        }
    }

    private function config(): Sse
    {
        $hub             = getenv('SSE_MERCURE_URL') ?: 'http://127.0.0.1:3000/.well-known/mercure';
        $config          = new Sse();
        $config->broker  = 'mercure';
        $config->mercure = [
            'hubUrl'       => $hub,
            'publicHubUrl' => $hub,
            'topicPrefix'  => 'urn:integration:sse:',
            'publisherKey' => getenv('SSE_MERCURE_PUBLISHER_KEY')
                ?: 'development-publisher-key-change-before-production',
            'subscriberKey' => getenv('SSE_MERCURE_SUBSCRIBER_KEY')
                ?: 'development-subscriber-key-change-before-production',
            'verifyTls' => false,
            'cookie'    => [
                'secure' => false,
            ],
        ];

        return $config;
    }

    /**
     * @param callable(): bool $condition
     */
    private function waitUntil(CurlMultiHandle $multi, callable $condition): void
    {
        $deadline = microtime(true) + 8.0;

        do {
            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($condition()) {
                return;
            }

            if ($running === 0) {
                break;
            }

            curl_multi_select($multi, 0.1);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Timed out waiting for the Mercure Hub.');
    }
}
