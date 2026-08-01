<?php

declare(strict_types=1);

namespace Tests\HTTP;

use Maniaba\CodeIgniterSse\Broker\Redis\RedisChannelSelectorValidator;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConfig;
use Maniaba\CodeIgniterSse\Exception\InvalidChannelException;
use Maniaba\CodeIgniterSse\Exception\InvalidChannelRequestException;
use Maniaba\CodeIgniterSse\HTTP\ChannelRequestParser;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ChannelRequestParserTest extends TestCase
{
    public function testParsesDeduplicatesAndNormalizesChannelList(): void
    {
        $channels = (new ChannelRequestParser())->parse(
            'users.42, orders.918,users.42',
        );

        $this->assertSame(['users.42', 'orders.918'], $channels);
    }

    public function testChannelLimitIsEnforced(): void
    {
        $this->expectException(InvalidChannelRequestException::class);

        (new ChannelRequestParser(1))->parse('public.news,public.alerts');
    }

    public function testOversizedRawChannelsParameterIsRejectedBeforeSplitting(): void
    {
        $this->expectException(InvalidChannelRequestException::class);
        $this->expectExceptionMessage('The channels query parameter must not exceed 200 bytes.');

        (new ChannelRequestParser(1))->parse(str_repeat('public.news,', 20) . 'public.news');
    }

    public function testPatternsAreOptIn(): void
    {
        $this->expectException(InvalidChannelException::class);

        (new ChannelRequestParser())->parse('public.*');
    }

    public function testRedisValidatorCanAllowPatterns(): void
    {
        $this->assertSame(
            ['public.*'],
            (new ChannelRequestParser(
                validator: new RedisChannelSelectorValidator(
                    new RedisConfig(allowPatternSubscriptions: true),
                ),
            ))->parse('public.*'),
        );
    }

    public function testPatternValidationMatchesTheRedisSubscriber(): void
    {
        $this->expectException(InvalidChannelException::class);

        (new ChannelRequestParser(
            validator: new RedisChannelSelectorValidator(
                new RedisConfig(allowPatternSubscriptions: true),
            ),
        ))->parse('public.*.');
    }
}
