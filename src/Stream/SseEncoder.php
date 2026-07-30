<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Stream;

/**
 * Formats fields for the Server-Sent Events wire protocol.
 *
 * Portions are derived from the CodeIgniter 4 framework.
 *
 * (c) CodeIgniter Foundation <admin@codeigniter.com>
 * Licensed under the MIT License:
 * https://github.com/codeigniter4/CodeIgniter4/blob/develop/LICENSE
 */
final class SseEncoder
{
    public function event(string $data, ?string $event = null, ?string $id = null): string
    {
        $output = '';

        if ($event !== null) {
            $output .= 'event: ' . $this->sanitizeLine($event) . "\n";
        }

        if ($id !== null) {
            $output .= 'id: ' . $this->sanitizeLine($id) . "\n";
        }

        return $output . $this->formatMultiline('data', $data);
    }

    public function comment(string $text): string
    {
        return $this->formatMultiline('', $text);
    }

    public function retry(int $milliseconds): string
    {
        return "retry: {$milliseconds}\n\n";
    }

    /**
     * SSE event names and IDs are single-line fields.
     */
    private function sanitizeLine(string $value): string
    {
        return str_replace(["\r\n", "\r", "\n"], '', $value);
    }

    /**
     * Normalize line endings and prefix each line with an SSE field name.
     */
    private function formatMultiline(string $prefix, string $value): string
    {
        $value  = str_replace(["\r\n", "\r"], "\n", $value);
        $output = '';

        foreach (explode("\n", $value) as $line) {
            $output .= "{$prefix}: {$line}\n";
        }

        return $output . "\n";
    }
}
