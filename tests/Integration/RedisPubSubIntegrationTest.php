<?php

declare(strict_types=1);

namespace Tests\Integration;

use Maniaba\CodeIgniterSse\Broker\Redis\RedisConfig;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConnectionFactory;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisPublisher;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisSubscriber;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;
use Maniaba\CodeIgniterSse\Event\SseEvent;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[Group('integration')]
final class RedisPubSubIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('SSE_REDIS_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set SSE_REDIS_INTEGRATION=1 to run live Redis tests.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The live Redis test requires pcntl and stream socket pairs.');
        }
    }

    public function testPublisherAndSubscriberExchangeAVersionedEvent(): void
    {
        $config = new RedisConfig(
            host: getenv('SSE_REDIS_HOST') ?: '127.0.0.1',
            port: (int) (getenv('SSE_REDIS_PORT') ?: 16379),
            connectTimeout: 2.0,
            readTimeout: 2.0,
            channelPrefix: 'integration:sse:',
            pollIntervalSeconds: 0.1,
            maxReconnectAttempts: 0,
        );
        $serializer = new JsonEventSerializer();
        $factory    = new RedisConnectionFactory($config);
        $sockets    = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            $this->fail('Unable to create the integration-test process socket.');
        }

        [$parentSocket, $childSocket] = $sockets;
        $pid                          = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Unable to fork the Redis subscriber test process.');
        }

        if ($pid === 0) {
            fclose($parentSocket);
            $received   = false;
            $ready      = false;
            $subscriber = new RedisSubscriber($config, $serializer, $factory);

            $subscriber->subscribe(
                ['public.integration'],
                static function ($message) use ($childSocket, &$received): void {
                    fwrite($childSocket, 'EVENT ' . $message->id() . "\n");
                    $received = true;
                },
                static function () use (&$received): bool {
                    return $received;
                },
                static function () use ($childSocket, &$ready): void {
                    if (! $ready) {
                        fwrite($childSocket, "READY\n");
                        $ready = true;
                    }
                },
            );

            fclose($childSocket);

            exit(0);
        }

        fclose($childSocket);
        $childExited = false;

        try {
            $this->assertSame("READY\n", $this->readLine($parentSocket));

            $publisher = new RedisPublisher($config, $serializer, $factory);
            $publisher->publish(
                'public.integration',
                new SseEvent('integration.created', ['ok' => true], 'integration-event'),
            );
            $publisher->close();

            $this->assertSame("EVENT integration-event\n", $this->readLine($parentSocket));

            pcntl_waitpid($pid, $status);
            $childExited = true;
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        } finally {
            fclose($parentSocket);

            if (! $childExited) {
                if (function_exists('posix_kill')) {
                    posix_kill($pid, SIGTERM);
                }

                pcntl_waitpid($pid, $status);
            }
        }
    }

    public function testSubscribedSocketRespondsToHealthPing(): void
    {
        $config = new RedisConfig(
            host: getenv('SSE_REDIS_HOST') ?: '127.0.0.1',
            port: (int) (getenv('SSE_REDIS_PORT') ?: 16379),
            connectTimeout: 2.0,
            readTimeout: 2.0,
        );
        $connection = (new RedisConnectionFactory($config))->create();

        try {
            $connection->connect();
            $connection->subscribe(['integration:sse:health']);

            $this->assertTrue($connection->ping());
        } finally {
            $connection->close();
        }
    }

    /**
     * @param resource $socket
     */
    private function readLine(mixed $socket): string
    {
        $read   = [$socket];
        $write  = [];
        $except = [];

        if (stream_select($read, $write, $except, 5) !== 1) {
            throw new RuntimeException('Timed out waiting for the Redis integration test process.');
        }

        $line = fgets($socket);

        if ($line === false) {
            throw new RuntimeException('The Redis integration test process closed its socket.');
        }

        return $line;
    }
}
