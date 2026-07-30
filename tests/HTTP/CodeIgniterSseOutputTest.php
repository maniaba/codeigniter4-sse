<?php

declare(strict_types=1);

namespace Tests\HTTP;

use InvalidArgumentException;
use Maniaba\CodeIgniterSse\HTTP\CodeIgniterSseOutput;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @internal
 */
final class CodeIgniterSseOutputTest extends TestCase
{
    public function testDelegatesAllOperationsToNativeOutput(): void
    {
        $native = new class () {
            /**
             * @var list<array{method: string, arguments: list<mixed>}>
             */
            public array $calls = [];

            public function event(string $data, ?string $event, ?string $id): bool
            {
                $this->calls[] = [
                    'method'    => 'event',
                    'arguments' => [$data, $event, $id],
                ];

                return true;
            }

            public function comment(string $text): bool
            {
                $this->calls[] = [
                    'method'    => 'comment',
                    'arguments' => [$text],
                ];

                return true;
            }

            public function retry(int $milliseconds): bool
            {
                $this->calls[] = [
                    'method'    => 'retry',
                    'arguments' => [$milliseconds],
                ];

                return true;
            }

            public function isClientConnected(): bool
            {
                $this->calls[] = [
                    'method'    => 'isClientConnected',
                    'arguments' => [],
                ];

                return false;
            }
        };

        $output = new CodeIgniterSseOutput($native);

        $this->assertTrue($output->event('{"ok":true}', 'updated', '42'));
        $this->assertTrue($output->comment('heartbeat'));
        $this->assertTrue($output->retry(3000));
        $this->assertFalse($output->isClientConnected());
        $this->assertSame(
            [
                [
                    'method'    => 'event',
                    'arguments' => ['{"ok":true}', 'updated', '42'],
                ],
                [
                    'method'    => 'comment',
                    'arguments' => ['heartbeat'],
                ],
                [
                    'method'    => 'retry',
                    'arguments' => [3000],
                ],
                [
                    'method'    => 'isClientConnected',
                    'arguments' => [],
                ],
            ],
            $native->calls,
        );
    }

    public function testRejectsObjectWithoutNativeSseMethods(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('comment()');

        new CodeIgniterSseOutput(new class () {
            public function event(): bool
            {
                return true;
            }
        });
    }

    public function testRejectsNonBooleanNativeResult(): void
    {
        $native = new class () {
            public function event(): string
            {
                return 'yes';
            }

            public function comment(): bool
            {
                return true;
            }

            public function retry(): bool
            {
                return true;
            }

            public function isClientConnected(): bool
            {
                return true;
            }
        };

        $output = new CodeIgniterSseOutput($native);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('event()');

        $output->event('payload');
    }
}
