<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Authorization;

use InvalidArgumentException;

final readonly class ChannelPattern
{
    private string $pattern;
    private string $regex;
    private string $signature;
    private int $specificity;

    /**
     * @var list<string>
     */
    private array $parameterNames;

    public function __construct(string $pattern)
    {
        $pattern = trim($pattern);

        if ($pattern === '' || strlen($pattern) > 200) {
            throw new InvalidArgumentException('A channel pattern must contain between 1 and 200 bytes.');
        }

        $segments       = explode('.', $pattern);
        $regexSegments  = [];
        $signature      = [];
        $specificity    = 0;
        $parameterNames = [];

        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException('Channel patterns must not contain empty segments.');
            }

            if ($segment === '*') {
                if ($index !== array_key_last($segments)) {
                    throw new InvalidArgumentException('The channel wildcard segment must be the final segment.');
                }

                $regexSegments[] = '.+';
                $signature[]     = '*';

                continue;
            }

            if (preg_match('/^\{([A-Za-z][A-Za-z0-9_]*)\}$/D', $segment, $matches) === 1) {
                $name = $matches[1];

                if (in_array($name, $parameterNames, true)) {
                    throw new InvalidArgumentException(
                        sprintf('The channel pattern parameter "%s" is duplicated.', $name),
                    );
                }

                $parameterNames[] = $name;
                $regexSegments[]  = sprintf('(?P<%s>[A-Za-z0-9][A-Za-z0-9_-]*)', $name);
                $signature[]      = '{}';
                $specificity++;

                continue;
            }

            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $segment) !== 1) {
                throw new InvalidArgumentException('Channel pattern segments may contain literals, {parameters}, or a final * wildcard.');
            }

            $regexSegments[] = preg_quote($segment, '/');
            $signature[]     = $segment;
            $specificity += 10;
        }

        $this->pattern        = $pattern;
        $this->regex          = '/\A' . implode('\.', $regexSegments) . '\z/';
        $this->signature      = implode('.', $signature);
        $this->specificity    = $specificity;
        $this->parameterNames = $parameterNames;
    }

    public function value(): string
    {
        return $this->pattern;
    }

    public function signature(): string
    {
        return $this->signature;
    }

    public function specificity(): int
    {
        return $this->specificity;
    }

    /**
     * @return array<string, string>|null
     */
    public function match(string $channel): ?array
    {
        if (preg_match($this->regex, $channel, $matches) !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($this->parameterNames as $name) {
            if (isset($matches[$name]) && is_string($matches[$name])) {
                $parameters[$name] = $matches[$name];
            }
        }

        return $parameters;
    }
}
