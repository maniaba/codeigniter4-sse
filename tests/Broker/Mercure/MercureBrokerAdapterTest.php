<?php

declare(strict_types=1);

namespace Tests\Broker\Mercure;

use Maniaba\CodeIgniterSse\Broker\HealthCheckResult;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureBrokerAdapter;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureBrokerAdapterFactory;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercureConfig;
use Maniaba\CodeIgniterSse\Broker\Mercure\MercurePublisher;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Event\EventFactory;
use Maniaba\CodeIgniterSse\Event\JsonEventSerializer;
use Maniaba\CodeIgniterSse\Factory\BrokerBuildContext;
use Maniaba\CodeIgniterSse\Factory\MercureConfigFactory;
use Maniaba\CodeIgniterSse\HTTP\MercureSubscriptionEndpoint;
use PHPUnit\Framework\TestCase;
use Tests\Support\Adapter\BasicSubscriptionEndpoint;
use Tests\Support\RecordingPublisher;

/**
 * @internal
 */
final class MercureBrokerAdapterTest extends TestCase
{
    public function testReturnsConfiguredCollaborators(): void
    {
        $publisher = new RecordingPublisher();
        $endpoint  = new BasicSubscriptionEndpoint();
        $adapter   = new MercureBrokerAdapter($this->mercureConfig(), $publisher, $endpoint, true);

        $this->assertSame($publisher, $adapter->publisher());
        $this->assertSame($endpoint, $adapter->subscriptionEndpoint());
    }

    public function testHealthCheckReportsValidConfiguration(): void
    {
        $adapter = new MercureBrokerAdapter(
            $this->mercureConfig(),
            new RecordingPublisher(),
            new BasicSubscriptionEndpoint(),
            true,
        );

        $result = $adapter->healthCheck();

        $this->assertSame(HealthCheckResult::OK, $result->status);
        $this->assertSame(
            'Mercure SSE configuration is valid for http://mercure/.well-known/mercure.',
            $result->summary,
        );
        $this->assertSame(
            ['Hub readiness is exposed through the Mercure Caddy admin API and is not queried by this command.'],
            $result->details,
        );
    }

    public function testHealthCheckReportsMissingCurlExtension(): void
    {
        $adapter = new MercureBrokerAdapter(
            $this->mercureConfig(),
            new RecordingPublisher(),
            new BasicSubscriptionEndpoint(),
            false,
        );

        $result = $adapter->healthCheck();

        $this->assertSame(HealthCheckResult::FAILED, $result->status);
        $this->assertSame('The Mercure publisher requires the PHP cURL extension.', $result->summary);
    }

    public function testFactoryCreatesMercureAdapter(): void
    {
        $adapter = (new MercureBrokerAdapterFactory())->create(
            $this->config(),
            new BrokerBuildContext(new JsonEventSerializer(), new EventFactory()),
        );

        $this->assertInstanceOf(MercureBrokerAdapter::class, $adapter);
        $this->assertInstanceOf(MercurePublisher::class, $adapter->publisher());
        $this->assertInstanceOf(MercureSubscriptionEndpoint::class, $adapter->subscriptionEndpoint());
    }

    private function mercureConfig(): MercureConfig
    {
        return (new MercureConfigFactory())->create($this->config());
    }

    private function config(): Sse
    {
        $config          = new Sse();
        $config->broker  = 'mercure';
        $config->mercure = [
            'hubUrl'        => 'http://mercure/.well-known/mercure',
            'publicHubUrl'  => 'https://example.test/.well-known/mercure',
            'publisherKey'  => 'publisher-test-secret',
            'subscriberKey' => 'subscriber-test-secret',
        ];

        return $config;
    }
}
