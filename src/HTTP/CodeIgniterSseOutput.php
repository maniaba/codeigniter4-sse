<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\HTTP;

use Closure;
use InvalidArgumentException;
use Maniaba\CodeIgniterSse\Contracts\SseOutputInterface;
use UnexpectedValueException;

/**
 * Adapts a native CodeIgniter SSE response without statically referencing its
 * concrete class.
 */
final class CodeIgniterSseOutput implements SseOutputInterface
{
    private readonly Closure $eventCallback;
    private readonly Closure $commentCallback;
    private readonly Closure $retryCallback;
    private readonly Closure $connectionCallback;

    public function __construct(object $output)
    {
        $this->eventCallback      = $this->nativeMethod($output, 'event');
        $this->commentCallback    = $this->nativeMethod($output, 'comment');
        $this->retryCallback      = $this->nativeMethod($output, 'retry');
        $this->connectionCallback = $this->nativeMethod($output, 'isClientConnected');
    }

    public function event(string $data, ?string $event = null, ?string $id = null): bool
    {
        return $this->invoke($this->eventCallback, 'event', $data, $event, $id);
    }

    public function comment(string $text): bool
    {
        return $this->invoke($this->commentCallback, 'comment', $text);
    }

    public function retry(int $milliseconds): bool
    {
        return $this->invoke($this->retryCallback, 'retry', $milliseconds);
    }

    public function isClientConnected(): bool
    {
        return $this->invoke($this->connectionCallback, 'isClientConnected');
    }

    private function nativeMethod(object $output, string $method): Closure
    {
        $callback = [$output, $method];

        if (! is_callable($callback)) {
            throw new InvalidArgumentException(
                sprintf('Native SSE output must provide a callable %s() method.', $method),
            );
        }

        return $callback(...);
    }

    private function invoke(Closure $callback, string $method, mixed ...$arguments): bool
    {
        $result = $callback(...$arguments);

        if (! is_bool($result)) {
            throw new UnexpectedValueException(
                sprintf('Native SSE output method %s() must return bool.', $method),
            );
        }

        return $result;
    }
}
