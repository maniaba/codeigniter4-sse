<?php

declare(strict_types=1);

namespace Tests\Broker\Redis;

use Maniaba\CodeIgniterSse\Broker\Redis\RedisChannelSelectorValidator;
use Maniaba\CodeIgniterSse\Broker\Redis\RedisConfig;
use Maniaba\CodeIgniterSse\Exception\InvalidChannelException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class RedisChannelSelectorValidatorTest extends TestCase
{
    public function testAllowsNormalChannelsWhenPatternsAreDisabled(): void
    {
        $validator = new RedisChannelSelectorValidator(new RedisConfig());

        $validator->assertValid('public.news');

        $this->expectNotToPerformAssertions();
    }

    public function testRejectsPatternsWhenPatternSubscriptionsAreDisabled(): void
    {
        $validator = new RedisChannelSelectorValidator(new RedisConfig());

        $this->expectException(InvalidChannelException::class);
        $this->expectExceptionMessage('Redis pattern subscriptions are disabled.');

        $validator->assertValid('public.*');
    }

    public function testAllowsValidPatternsWhenPatternSubscriptionsAreEnabled(): void
    {
        $validator = new RedisChannelSelectorValidator(
            new RedisConfig(allowPatternSubscriptions: true),
        );

        $validator->assertValid('public.*');

        $this->expectNotToPerformAssertions();
    }

    public function testRejectsInvalidPatternsWhenPatternSubscriptionsAreEnabled(): void
    {
        $validator = new RedisChannelSelectorValidator(
            new RedisConfig(allowPatternSubscriptions: true),
        );

        $this->expectException(InvalidChannelException::class);

        $validator->assertValid('public.*.');
    }
}
