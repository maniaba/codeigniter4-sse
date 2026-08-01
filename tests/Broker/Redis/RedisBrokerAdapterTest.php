<?php

declare(strict_types=1);

namespace Tests\Broker\Redis;

use Maniaba\CodeIgniterSse\Broker\HealthCheckResult;
use Maniaba\CodeIgniterSse\Broker\Redis\Exception\RedisConnectionException;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisBrokerAdapter;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisHealthChecker;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisPublisher;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisSubscriber;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;
use Maniaba\CodeIgniterSse\Factory\BrokerBuildContext;
use Maniaba\CodeIgniterSse\Factory\RedisConfigFactory;
use Maniaba\CodeIgniterSse\HTTP\LocalSseSubscriptionEndpoint;
use PHPUnit\Framework\TestCase;
use Tests\Broker\Redis\Fixtures\FakeRedisConnection;
use Tests\Broker\Redis\Fixtures\FakeRedisConnectionFactory;
use Tests\Support\Adapter\BasicSubscriptionEndpoint;
use Tests\Support\RecordingPublisher;
use Tests\Support\RecordingSubscriber;

/**
 * @internal
 */
final class RedisBrokerAdapterTest extends TestCase
{
    public function testReturnsConfiguredCollaborators(): void
    {
        $redis      = (new RedisConfigFactory())->create(new Sse());
        $publisher  = new RecordingPublisher();
        $subscriber = new RecordingSubscriber();
        $endpoint   = new BasicSubscriptionEndpoint();
        $checker    = new RedisHealthChecker(new FakeRedisConnectionFactory([new FakeRedisConnection()]));
        $adapter    = new RedisBrokerAdapter($redis, $publisher, $subscriber, $endpoint, $checker);

        $this->assertSame($publisher, $adapter->publisher());
        $this->assertSame($subscriber, $adapter->subscriber());
        $this->assertSame($endpoint, $adapter->subscriptionEndpoint());
    }

    public function testHealthCheckReportsReachableRedis(): void
    {
        $redis      = (new RedisConfigFactory())->create(new Sse());
        $connection = new FakeRedisConnection();
        $adapter    = new RedisBrokerAdapter(
            $redis,
            new RecordingPublisher(),
            new RecordingSubscriber(),
            new BasicSubscriptionEndpoint(),
            new RedisHealthChecker(new FakeRedisConnectionFactory([$connection])),
        );

        $result = $adapter->healthCheck();

        $this->assertSame(HealthCheckResult::OK, $result->status);
        $this->assertSame('Redis SSE broker is reachable at tcp://127.0.0.1:6379.', $result->summary);
        $this->assertNull($result->error);
        $this->assertSame(1, $connection->pingCalls);
        $this->assertSame(1, $connection->closeCalls);
    }

    public function testHealthCheckReportsConnectionFailure(): void
    {
        $config        = new Sse();
        $config->redis = [
            'host'     => 'redis.internal',
            'port'     => 6380,
            'database' => 4,
        ];
        $redis                      = (new RedisConfigFactory())->create($config);
        $connection                 = new FakeRedisConnection();
        $error                      = new RedisConnectionException('offline');
        $connection->connectFailure = $error;
        $adapter                    = new RedisBrokerAdapter(
            $redis,
            new RecordingPublisher(),
            new RecordingSubscriber(),
            new BasicSubscriptionEndpoint(),
            new RedisHealthChecker(new FakeRedisConnectionFactory([$connection])),
        );

        $result = $adapter->healthCheck();

        $this->assertSame(HealthCheckResult::FAILED, $result->status);
        $this->assertSame(
            'Redis SSE health check failed for tcp://redis.internal:6380 (database 4).',
            $result->summary,
        );
        $this->assertSame($error, $result->error);
        $this->assertSame(1, $connection->closeCalls);
    }

    public function testFactoryCreatesRedisAdapterWithoutOpeningAConnection(): void
    {
        $config                       = new Sse();
        $config->redis                = ['host' => 'redis.internal'];
        $config->requireAcceptHeader  = false;
        $config->emitConnectedEvent   = false;
        $config->retryMilliseconds    = 1500;
        $config->heartbeatInterval    = 5;
        $config->maxConnectionSeconds = 10;

        $adapter = (new RedisBrokerAdapterFactory())->create(
            $config,
            new BrokerBuildContext(new JsonEventSerializer(), new EventFactory()),
        );

        $this->assertInstanceOf(RedisBrokerAdapter::class, $adapter);
        $this->assertInstanceOf(RedisPublisher::class, $adapter->publisher());
        $this->assertInstanceOf(RedisSubscriber::class, $adapter->subscriber());
        $this->assertInstanceOf(LocalSseSubscriptionEndpoint::class, $adapter->subscriptionEndpoint());
    }
}
