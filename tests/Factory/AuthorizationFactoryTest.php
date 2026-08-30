<?php

declare(strict_types=1);

namespace Tests\Factory;

use LogicException;
use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorizationContext;
use Maniaba\CodeIgniterSse\Config\Sse;
use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;
use Maniaba\CodeIgniterSse\Factory\AuthorizationFactory;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Support\Tests\Config\Fixtures\ConfiguredUserChannel;

/**
 * @internal
 */
final class AuthorizationFactoryTest extends TestCase
{
    public function testResolvesConfiguredChannelDefinitionClass(): void
    {
        $config           = new Sse();
        $config->channels = [ConfiguredUserChannel::class];

        $definitions = (new AuthorizationFactory())->channelDefinitions($config);

        $this->assertCount(1, $definitions);
        $this->assertInstanceOf(ConfiguredUserChannel::class, $definitions[0]);
    }

    public function testResolvesConfiguredChannelDefinitionInstance(): void
    {
        $definition       = $this->definition();
        $config           = new Sse();
        $config->channels = [$definition];

        $this->assertSame(
            [$definition],
            (new AuthorizationFactory())->channelDefinitions($config),
        );
    }

    public function testResolvesConfiguredChannelDefinitionFactory(): void
    {
        $definition       = $this->definition();
        $config           = new Sse();
        $config->channels = [static fn (): ChannelDefinitionInterface => $definition];

        $this->assertSame(
            [$definition],
            (new AuthorizationFactory())->channelDefinitions($config),
        );
    }

    public function testRejectsMissingChannelDefinitionClass(): void
    {
        $config = new Sse();
        $this->setConfiguredChannels($config, ['MissingChannelDefinition']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not exist');

        (new AuthorizationFactory())->channelDefinitions($config);
    }

    public function testRejectsClassThatIsNotAChannelDefinition(): void
    {
        $config = new Sse();
        $this->setConfiguredChannels($config, [stdClass::class]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement ' . ChannelDefinitionInterface::class);

        (new AuthorizationFactory())->channelDefinitions($config);
    }

    public function testRejectsFactoryThatDoesNotReturnAChannelDefinition(): void
    {
        $config = new Sse();
        $this->setConfiguredChannels($config, [static fn (): stdClass => new stdClass()]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('factory must return ' . ChannelDefinitionInterface::class);

        (new AuthorizationFactory())->channelDefinitions($config);
    }

    /**
     * @param list<mixed> $channels
     */
    private function setConfiguredChannels(Sse $config, array $channels): void
    {
        (new ReflectionProperty($config, 'channels'))->setValue($config, $channels);
    }

    private function definition(): ChannelDefinitionInterface
    {
        return new class () implements ChannelDefinitionInterface {
            public static function pattern(): string
            {
                return 'test.{id}';
            }

            public function authorize(ChannelAuthorizationContext $context): bool
            {
                return true;
            }
        };
    }
}
