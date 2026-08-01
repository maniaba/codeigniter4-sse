<?php

declare(strict_types=1);

namespace Tests\Factory;

use LogicException;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterFactoryInterface;
use Maniaba\CodeIgniterSse\Contracts\BrokerAdapterInterface;
use Maniaba\CodeIgniterSse\Factory\BrokerAdapterResolver;
use Maniaba\CodeIgniterSse\Factory\BrokerBuildContext;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Support\Tests\Adapter\BasicBrokerAdapter;
use Support\Tests\Adapter\BasicBrokerAdapterFactory;
use Support\Tests\Adapter\InvalidAdapter;
use Support\Tests\Adapter\InvalidBrokerAdapterFactory;

/**
 * @internal
 */
final class BrokerAdapterResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        BasicBrokerAdapterFactory::reset();
    }

    public function testResolvesConfiguredAdapterInstance(): void
    {
        $adapter = new BasicBrokerAdapter();
        $config  = $this->config(['adapter' => $adapter]);

        $this->assertSame($adapter, (new BrokerAdapterResolver())->resolve($config));
    }

    public function testResolvesConfiguredAdapterClosure(): void
    {
        $adapter = new BasicBrokerAdapter();
        $config  = $this->config([
            'adapter' => static fn (Sse $config, BrokerBuildContext $context): BrokerAdapterInterface => $adapter,
        ]);

        $this->assertSame($adapter, (new BrokerAdapterResolver())->resolve($config));
    }

    public function testResolvesConfiguredAdapterCallableObject(): void
    {
        $adapter = new BasicBrokerAdapter();
        $config  = $this->config([
            'adapter' => new class ($adapter) {
                public function __construct(
                    private readonly BrokerAdapterInterface $adapter,
                ) {
                }

                public function __invoke(Sse $config, BrokerBuildContext $context): BrokerAdapterInterface
                {
                    return $this->adapter;
                }
            },
        ]);

        $this->assertSame($adapter, (new BrokerAdapterResolver())->resolve($config));
    }

    public function testResolvesConfiguredAdapterClass(): void
    {
        $config = $this->config(['adapter' => BasicBrokerAdapter::class]);

        $this->assertInstanceOf(BasicBrokerAdapter::class, (new BrokerAdapterResolver())->resolve($config));
    }

    public function testRejectsMissingAdapterClass(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The configured SSE broker adapter "MissingAdapter" does not exist.');

        (new BrokerAdapterResolver())->resolve($this->config(['adapter' => 'MissingAdapter']));
    }

    public function testRejectsAdapterClassThatDoesNotImplementTheContract(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement ' . BrokerAdapterInterface::class);

        (new BrokerAdapterResolver())->resolve($this->config(['adapter' => InvalidAdapter::class]));
    }

    public function testRejectsAdapterClosureThatReturnsInvalidValue(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement ' . BrokerAdapterInterface::class);

        (new BrokerAdapterResolver())->resolve($this->config([
            'adapter' => static fn (): stdClass => new stdClass(),
        ]));
    }

    public function testRejectsInvalidAdapterClosureAfterOneInvocation(): void
    {
        $calls = 0;

        try {
            (new BrokerAdapterResolver())->resolve($this->config([
                'adapter' => static function () use (&$calls): stdClass {
                    $calls++;

                    return new stdClass();
                },
            ]));

            $this->fail('Invalid adapter closures must be rejected.');
        } catch (LogicException) {
            $this->assertSame(1, $calls);
        }
    }

    public function testResolvesConfiguredFactoryInstance(): void
    {
        $adapter                            = new BasicBrokerAdapter();
        $factory                            = new BasicBrokerAdapterFactory();
        BasicBrokerAdapterFactory::$adapter = $adapter;

        $this->assertSame($adapter, (new BrokerAdapterResolver())->resolve($this->config([
            'factory' => $factory,
        ])));
    }

    public function testResolvesConfiguredFactoryClosure(): void
    {
        $adapter                            = new BasicBrokerAdapter();
        BasicBrokerAdapterFactory::$adapter = $adapter;

        $this->assertSame($adapter, (new BrokerAdapterResolver())->resolve($this->config([
            'factory' => static fn (): BrokerAdapterFactoryInterface => new BasicBrokerAdapterFactory(),
        ])));
    }

    public function testResolvesConfiguredFactoryCallableObject(): void
    {
        $adapter                            = new BasicBrokerAdapter();
        BasicBrokerAdapterFactory::$adapter = $adapter;

        $factoryProvider = new class () {
            public function __invoke(): BrokerAdapterFactoryInterface
            {
                return new BasicBrokerAdapterFactory();
            }
        };

        $this->assertSame($adapter, (new BrokerAdapterResolver())->resolve($this->config([
            'factory' => $factoryProvider,
        ])));
    }

    public function testResolvesConfiguredFactoryClass(): void
    {
        $config = $this->config(['factory' => BasicBrokerAdapterFactory::class]);

        $this->assertInstanceOf(BasicBrokerAdapter::class, (new BrokerAdapterResolver())->resolve($config));
    }

    public function testRejectsMissingFactoryClass(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The configured SSE broker adapter factory "MissingFactory" does not exist.');

        (new BrokerAdapterResolver())->resolve($this->config(['factory' => 'MissingFactory']));
    }

    public function testRejectsFactoryClassThatDoesNotImplementTheContract(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement ' . BrokerAdapterFactoryInterface::class);

        (new BrokerAdapterResolver())->resolve($this->config(['factory' => InvalidBrokerAdapterFactory::class]));
    }

    public function testRejectsFactoryCallableThatReturnsInvalidValue(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement ' . BrokerAdapterFactoryInterface::class);

        (new BrokerAdapterResolver())->resolve($this->config([
            'factory' => static fn (): stdClass => new stdClass(),
        ]));
    }

    public function testRejectsInvalidFactoryClosureAfterOneInvocation(): void
    {
        $calls = 0;

        try {
            (new BrokerAdapterResolver())->resolve($this->config([
                'factory' => static function () use (&$calls): stdClass {
                    $calls++;

                    return new stdClass();
                },
            ]));

            $this->fail('Invalid factory closures must be rejected.');
        } catch (LogicException) {
            $this->assertSame(1, $calls);
        }
    }

    public function testSharedDefinitionsReuseTheResolvedAdapter(): void
    {
        $config = $this->config([
            'factory' => BasicBrokerAdapterFactory::class,
            'shared'  => true,
        ]);
        $resolver = new BrokerAdapterResolver();

        $first  = $resolver->resolve($config);
        $second = $resolver->resolve($config);

        $this->assertSame($first, $second);
        $this->assertSame(1, BasicBrokerAdapterFactory::$created);
    }

    public function testNonSharedDefinitionsCreateANewAdapterEachTime(): void
    {
        $config   = $this->config(['factory' => BasicBrokerAdapterFactory::class]);
        $resolver = new BrokerAdapterResolver();

        $this->assertNotSame($resolver->resolve($config), $resolver->resolve($config));
        $this->assertSame(2, BasicBrokerAdapterFactory::$created);
    }

    public function testRejectsNonArrayBrokerDefinition(): void
    {
        $config         = new Sse();
        $config->broker = 'invalid';
        (new ReflectionProperty($config, 'brokers'))->setValue($config, ['invalid' => 'not-array']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The configured SSE broker definition must be an array.');

        (new BrokerAdapterResolver())->resolve($config);
    }

    public function testRejectsBrokerDefinitionsWithoutFactoryOrAdapter(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must define either "factory" or "adapter"');

        (new BrokerAdapterResolver())->resolve($this->config([]));
    }

    public function testRejectsBrokerDefinitionsWithFactoryAndAdapter(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must not define both "factory" and "adapter"');

        (new BrokerAdapterResolver())->resolve($this->config([
            'factory' => BasicBrokerAdapterFactory::class,
            'adapter' => new BasicBrokerAdapter(),
        ]));
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function config(array $definition): Sse
    {
        $config                    = new Sse();
        $config->broker            = 'custom';
        $config->brokers['custom'] = $definition;

        return $config;
    }
}
