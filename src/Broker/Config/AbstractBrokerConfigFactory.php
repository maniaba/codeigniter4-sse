<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Config;

abstract class AbstractBrokerConfigFactory
{
    protected static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    protected static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            is_string(...),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected static function arrayOption(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
