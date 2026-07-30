<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\HTTP;

use Closure;
use CodeIgniter\HTTP\DownloadResponse;
use Maniaba\CodeIgniterSse\Contracts\SseOutputInterface;
use Maniaba\CodeIgniterSse\Stream\SseEncoder;

/**
 * SSE response fallback for CodeIgniter applications.
 *
 * Extending DownloadResponse is intentional: CodeIgniter 4.7 treats
 * download responses as non-buffered and therefore skip response body
 * gathering, page caching, and Debug Toolbar injection.
 *
 * Streaming behavior is derived from the CodeIgniter 4 framework.
 *
 * (c) CodeIgniter Foundation <admin@codeigniter.com>
 * Licensed under the MIT License:
 * https://github.com/codeigniter4/CodeIgniter4/blob/develop/LICENSE
 */
final class LegacySseResponse extends DownloadResponse implements SseOutputInterface
{
    /**
     * @var Closure(SseOutputInterface): void
     */
    private readonly Closure $callback;

    private readonly SseEncoder $encoder;

    /**
     * @param callable(SseOutputInterface): void $callback
     *
     * @not-deprecated
     */
    public function __construct(callable $callback, ?SseEncoder $encoder = null)
    {
        parent::__construct('__sse_stream__', false);

        $this->callback = $callback(...);
        $this->encoder  = $encoder ?? new SseEncoder();
    }

    public function event(string $data, ?string $event = null, ?string $id = null): bool
    {
        if (! $this->isClientConnected()) {
            return false;
        }

        return $this->write($this->encoder->event($data, $event, $id));
    }

    public function comment(string $text): bool
    {
        return $this->write($this->encoder->comment($text));
    }

    public function retry(int $milliseconds): bool
    {
        return $this->write($this->encoder->retry($milliseconds));
    }

    public function isClientConnected(): bool
    {
        return connection_status() === CONNECTION_NORMAL && connection_aborted() === 0;
    }

    /**
     * Send headers, then execute the streaming callback.
     *
     * @return $this
     */
    public function send()
    {
        if (! $this->isTesting()) {
            set_time_limit(0);
            ini_set('zlib.output_compression', 'Off');

            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->prepareSseHeaders();
        $this->sendHeaders();
        $this->sendCookies();

        ($this->callback)($this);

        return $this;
    }

    /**
     * The body is emitted by the callback in send().
     *
     * @return $this
     */
    public function sendBody()
    {
        return $this;
    }

    private function write(string $output): bool
    {
        if (! $this->isClientConnected()) {
            return false;
        }

        echo $output;

        if (! $this->isTesting()) {
            if (ob_get_level() > 0) {
                ob_flush();
            }

            flush();
        }

        return true;
    }

    private function isTesting(): bool
    {
        return defined('ENVIRONMENT') && constant('ENVIRONMENT') === 'testing';
    }

    private function prepareSseHeaders(): void
    {
        $this->setContentType('text/event-stream', 'UTF-8');
        $this->removeHeader('Cache-Control');
        $this->setHeader('Cache-Control', 'no-cache, no-transform');
        $this->setHeader('X-Accel-Buffering', 'no');

        if (version_compare($this->getProtocolVersion(), '2.0', '<')) {
            $this->setHeader('Connection', 'keep-alive');
        }
    }
}
