<?php

declare(strict_types=1);

namespace Tests\HTTP;

use Closure;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use LogicException;
use Maniaba\CodeIgniterSse\Contracts\SseOutputInterface;
use Maniaba\CodeIgniterSse\HTTP\CodeIgniterSseOutput;
use Maniaba\CodeIgniterSse\HTTP\LegacySseResponse;
use Maniaba\CodeIgniterSse\HTTP\SseResponseFactory;

final class NativeSseOutputDouble
{
    /**
     * @var array{string, string|null, string|null}|null
     */
    public ?array $eventArguments = null;

    public function event(string $data, ?string $event, ?string $id): bool
    {
        $this->eventArguments = [$data, $event, $id];

        return true;
    }

    public function comment(string $text): bool
    {
        return true;
    }

    public function retry(int $milliseconds): bool
    {
        return true;
    }

    public function isClientConnected(): bool
    {
        return true;
    }
}

final class NativeEventStreamFactoryDouble
{
    private ?Closure $callback = null;

    public function __construct(
        private readonly ResponseInterface $returnedResponse,
        private readonly NativeSseOutputDouble $nativeOutput,
    ) {
    }

    public function eventStream(callable $callback): ResponseInterface
    {
        $this->callback = $callback(...);

        return $this->returnedResponse;
    }

    public function dispatch(): void
    {
        if ($this->callback === null) {
            throw new LogicException('The native event stream callback was not registered.');
        }

        ($this->callback)($this->nativeOutput);
    }
}

/**
 * @internal
 */
final class SseResponseFactoryTest extends CIUnitTestCase
{
    public function testUsesNativeEventStreamFactoryWhenAvailable(): void
    {
        $returnedResponse = $this->createStub(ResponseInterface::class);
        $nativeOutput     = $this->nativeOutput();
        $response         = new NativeEventStreamFactoryDouble($returnedResponse, $nativeOutput);

        $received = null;
        $factory  = new SseResponseFactory($response);
        $result   = $factory->create(
            static function (SseOutputInterface $output) use (&$received): void {
                $received = $output;
                $output->event('{"ok":true}', 'updated', '42');
            },
        );

        $response->dispatch();

        $this->assertSame($returnedResponse, $result);
        $this->assertInstanceOf(CodeIgniterSseOutput::class, $received);
        $this->assertSame(
            ['{"ok":true}', 'updated', '42'],
            $nativeOutput->eventArguments,
        );
    }

    public function testFallsBackToLegacyResponseAndCopiesProtocolVersion(): void
    {
        $response = new class () {
            public function getProtocolVersion(): string
            {
                return '2.0';
            }
        };

        $received = null;
        $factory  = new SseResponseFactory($response);
        $result   = $factory->create(
            static function (SseOutputInterface $output) use (&$received): void {
                $received = $output;
            },
        );

        $this->assertInstanceOf(LegacySseResponse::class, $result);
        $this->assertSame('2.0', $result->getProtocolVersion());

        $result->pretend();
        ob_start();
        $result->send();
        ob_end_clean();

        $this->assertSame($result, $received);
    }

    private function nativeOutput(): NativeSseOutputDouble
    {
        return new NativeSseOutputDouble();
    }
}
