<?php

declare(strict_types=1);

namespace Tests\HTTP;

use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\Test\CIUnitTestCase;
use Maniaba\CodeIgniterSse\Contracts\SseOutputInterface;
use Maniaba\CodeIgniterSse\HTTP\LegacySseResponse;

/**
 * @internal
 */
final class LegacySseResponseTest extends CIUnitTestCase
{
    public function testUsesDownloadResponseAsLegacyNonBufferedBridge(): void
    {
        $response = new LegacySseResponse(static function (): void {
        });

        $this->assertInstanceOf(DownloadResponse::class, $response);
        $this->assertInstanceOf(SseOutputInterface::class, $response);
    }

    public function testEventWritesCodeIgniterCompatibleWireFormat(): void
    {
        $response = new LegacySseResponse(static function (): void {
        });

        ob_start();
        $result = $response->event("line1\nline2", "up\ndate", "1\n2");
        $output = ob_get_clean();

        $this->assertTrue($result);
        $this->assertSame(
            "event: update\nid: 12\ndata: line1\ndata: line2\n\n",
            $output,
        );
    }

    public function testSendPreparesSseHeadersAndRunsCallback(): void
    {
        $response = new LegacySseResponse(
            static function (SseOutputInterface $output): void {
                $output->event('hello');
            },
        );
        $response->pretend();

        ob_start();
        $result = $response->send();
        $output = ob_get_clean();

        $this->assertSame($response, $result);
        $this->assertSame("data: hello\n\n", $output);
        $this->assertSame(
            'text/event-stream; charset=UTF-8',
            $response->getHeaderLine('Content-Type'),
        );
        $this->assertSame('no-cache, no-transform', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('no', $response->getHeaderLine('X-Accel-Buffering'));
        $this->assertSame('keep-alive', $response->getHeaderLine('Connection'));
        $this->assertSame('', $response->getHeaderLine('Content-Encoding'));
    }

    public function testSendDoesNotAddConnectionHeaderForHttp2(): void
    {
        $response = new LegacySseResponse(static function (): void {
        });
        $response->setProtocolVersion('2.0');
        $response->pretend();

        ob_start();
        $response->send();
        ob_end_clean();

        $this->assertSame('', $response->getHeaderLine('Connection'));
    }

    public function testSendBodyIsNoOp(): void
    {
        $response = new LegacySseResponse(static function (): void {
        });

        ob_start();
        $result = $response->sendBody();
        $output = ob_get_clean();

        $this->assertSame($response, $result);
        $this->assertSame('', $output);
    }
}
