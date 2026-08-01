<?php

declare(strict_types=1);

namespace Tests\Broker\Redis;

use Closure;
use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisCommandException;
use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisProtocolException;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConfig;
use Maniaba\CodeIgniterSse\Broker\Redis\SocketRedisConnection;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SocketRedisConnectionTest extends TestCase
{
    /**
     * @var resource|null
     */
    private mixed $client;

    /**
     * @var resource
     */
    private mixed $server;

    protected function setUp(): void
    {
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);

        [$this->client, $this->server] = $pair;
    }

    protected function tearDown(): void
    {
        if (is_resource($this->client)) {
            \fclose($this->client);
        }
        if (is_resource($this->server)) {
            \fclose($this->server);
        }
    }

    public function testAuthenticatesSelectsDatabaseSetsClientNameAndPings(): void
    {
        \fwrite($this->server, "+OK\r\n+OK\r\n+OK\r\n+PONG\r\n");
        $connectorArguments = [];
        $connector          = function (string $endpoint, float $timeout, array $context) use (&$connectorArguments) {
            $connectorArguments = [$endpoint, $timeout, $context];

            return $this->client;
        };
        $config = new RedisConfig(
            host: 'redis.internal',
            port: 6380,
            password: 'secret',
            database: 3,
            connectTimeout: 1.25,
            username: 'application',
            scheme: 'tls',
            streamContext: ['ssl' => ['verify_peer' => true]],
            clientName: 'ci4-sse',
        );
        $connection = new SocketRedisConnection($config, Closure::fromCallable($connector));

        $connection->connect();

        $this->assertSame(
            ['tls://redis.internal:6380', 1.25, ['ssl' => ['verify_peer' => true]]],
            $connectorArguments,
        );
        $this->assertSame(
            self::command('AUTH', 'application', 'secret')
                . self::command('SELECT', '3')
                . self::command('CLIENT', 'SETNAME', 'ci4-sse')
                . self::command('PING'),
            $this->readCommands(),
        );
        $this->assertTrue($connection->isConnected());

        $connection->close();
        $this->client = null;
    }

    public function testPublishesBinarySafePayloadAndReturnsSubscriberCount(): void
    {
        \fwrite($this->server, "+PONG\r\n:2\r\n");
        $connection = $this->connection();
        $connection->connect();

        $subscribers = $connection->publish('app:sse:users.42', "line one\nline two");

        $this->assertSame(2, $subscribers);
        $this->assertSame(
            self::command('PING')
                . self::command('PUBLISH', 'app:sse:users.42', "line one\nline two"),
            $this->readCommands(),
        );
    }

    public function testReadsRegularAndPatternMessagesAndBuffersMessageInterleavedWithAcks(): void
    {
        $responses = "+PONG\r\n"
            . self::pubSubFrame(['subscribe', 'app:sse:news', 1])
            . self::pubSubFrame(['message', 'app:sse:news', '{"id":"one"}'])
            . self::pubSubFrame(['psubscribe', 'app:sse:news.*', 2])
            . self::pubSubFrame(['pmessage', 'app:sse:news.*', 'app:sse:news.eu', '{"id":"two"}']);
        \fwrite($this->server, $responses);
        $connection = $this->connection();
        $connection->connect();

        $connection->subscribe(['app:sse:news'], ['app:sse:news.*']);
        $regular = $connection->readMessage(0.1);
        $pattern = $connection->readMessage(0.1);

        $this->assertNotNull($regular);
        $this->assertSame('app:sse:news', $regular->channel);
        $this->assertSame('{"id":"one"}', $regular->payload);
        $this->assertNull($regular->pattern);

        $this->assertNotNull($pattern);
        $this->assertSame('app:sse:news.eu', $pattern->channel);
        $this->assertSame('app:sse:news.*', $pattern->pattern);
        $this->assertNotNull($pattern->pattern);

        $this->assertSame(
            self::command('PING')
                . self::command('SUBSCRIBE', 'app:sse:news')
                . self::command('PSUBSCRIBE', 'app:sse:news.*'),
            $this->readCommands(),
        );
        $this->assertNull($connection->readMessage(0.001));
    }

    public function testSubscribedPingBuffersInterleavedMessagesUntilPong(): void
    {
        $responses = "+PONG\r\n"
            . self::pubSubFrame(['subscribe', 'app:sse:news', 1])
            . self::pubSubFrame(['message', 'app:sse:news', '{"id":"during-ping"}'])
            . self::pubSubFrame(['pong', '']);
        \fwrite($this->server, $responses);
        $connection = $this->connection();
        $connection->connect();
        $connection->subscribe(['app:sse:news']);

        $this->assertTrue($connection->ping());

        $message = $connection->readMessage(0.1);

        $this->assertNotNull($message);
        $this->assertSame('app:sse:news', $message->channel);
        $this->assertSame('{"id":"during-ping"}', $message->payload);
        $this->assertSame(
            self::command('PING')
                . self::command('SUBSCRIBE', 'app:sse:news')
                . self::command('PING'),
            $this->readCommands(),
        );
    }

    public function testRejectsBulkPayloadAboveConfiguredLimitBeforeReadingItsBody(): void
    {
        $responses = "+PONG\r\n"
            . self::pubSubFrame(['subscribe', 'app:sse:news', 1])
            . "*3\r\n"
            . "$7\r\nmessage\r\n"
            . "$12\r\napp:sse:news\r\n"
            . "$1025\r\n";
        \fwrite($this->server, $responses);
        $connection = $this->connection(new RedisConfig(maxPayloadBytes: 1024));
        $connection->connect();
        $connection->subscribe(['app:sse:news']);

        $this->expectException(RedisProtocolException::class);
        $this->expectExceptionMessage('payload limit');

        $connection->readMessage(0.1);
    }

    public function testRejectsArrayAboveConfiguredElementLimit(): void
    {
        $responses = "+PONG\r\n"
            . self::pubSubFrame(['subscribe', 'app:sse:news', 1])
            . "*4\r\n";
        \fwrite($this->server, $responses);
        $connection = $this->connection(new RedisConfig(maxResponseElements: 3));
        $connection->connect();
        $connection->subscribe(['app:sse:news']);

        $this->expectException(RedisProtocolException::class);
        $this->expectExceptionMessage('element limit');

        $connection->readMessage(0.1);
    }

    public function testRejectsRespIntegerOutsidePlatformRange(): void
    {
        \fwrite($this->server, "+PONG\r\n:999999999999999999999999\r\n");
        $connection = $this->connection();
        $connection->connect();

        $this->expectException(RedisProtocolException::class);
        $this->expectExceptionMessage('platform range');

        $connection->publish('app:sse:news', '{}');
    }

    public function testRedisCommandErrorIsExposed(): void
    {
        \fwrite($this->server, "-ERR invalid password\r\n");
        $connection = new SocketRedisConnection(
            new RedisConfig(password: 'wrong'),
            Closure::fromCallable(fn () => $this->client),
        );

        $this->expectException(RedisCommandException::class);
        $this->expectExceptionMessage('invalid password');

        $connection->connect();
    }

    public function testMalformedPublishResponseIsRejected(): void
    {
        \fwrite($this->server, "+PONG\r\n+OK\r\n");
        $connection = $this->connection();
        $connection->connect();

        $this->expectException(RedisProtocolException::class);

        $connection->publish('app:sse:news', '{}');
    }

    private function connection(?RedisConfig $config = null): SocketRedisConnection
    {
        return new SocketRedisConnection(
            $config ?? new RedisConfig(),
            Closure::fromCallable(fn () => $this->client),
        );
    }

    private function readCommands(): string
    {
        \stream_set_blocking($this->server, false);

        return (string) \stream_get_contents($this->server);
    }

    private static function command(string ...$arguments): string
    {
        $command = '*' . count($arguments) . "\r\n";

        foreach ($arguments as $argument) {
            $command .= '$' . \strlen($argument) . "\r\n" . $argument . "\r\n";
        }

        return $command;
    }

    /**
     * @param list<int|string> $values
     */
    private static function pubSubFrame(array $values): string
    {
        $frame = '*' . count($values) . "\r\n";

        foreach ($values as $value) {
            if (is_int($value)) {
                $frame .= ':' . $value . "\r\n";

                continue;
            }

            $frame .= '$' . \strlen($value) . "\r\n" . $value . "\r\n";
        }

        return $frame;
    }
}
