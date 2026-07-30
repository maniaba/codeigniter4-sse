<?php

declare(strict_types=1);

namespace Tests\Stream;

use Maniaba\CodeIgniterSse\Stream\SseEncoder;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SseEncoderTest extends TestCase
{
    private SseEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new SseEncoder();
    }

    public function testEventFormatsMultilineDataAndSanitizesSingleLineFields(): void
    {
        $result = $this->encoder->event(
            "line1\nline2",
            "up\ndate",
            "1\n2",
        );

        $this->assertSame(
            "event: update\nid: 12\ndata: line1\ndata: line2\n\n",
            $result,
        );
    }

    public function testEventNormalizesCarriageReturnLineEndings(): void
    {
        $this->assertSame(
            "data: one\ndata: two\ndata: three\n\n",
            $this->encoder->event("one\r\ntwo\rthree"),
        );
    }

    public function testEventOmitsNullEventAndIdFields(): void
    {
        $this->assertSame("data: hello\n\n", $this->encoder->event('hello'));
    }

    public function testCommentFormatsEveryLineAsSseComment(): void
    {
        $this->assertSame(
            ": keep\n: alive\n\n",
            $this->encoder->comment("keep\nalive"),
        );
    }

    public function testRetryFormatsMilliseconds(): void
    {
        $this->assertSame("retry: 1500\n\n", $this->encoder->retry(1500));
    }
}
