<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Debug\Toolbar;

use CodeIgniter\Debug\Toolbar\Collectors\BaseCollector;

final class SseEvents extends BaseCollector
{
    /**
     * @var bool
     */
    protected $hasTabContent = true;

    /**
     * @var bool
     */
    protected $hasLabel = true;

    /**
     * @var string
     */
    protected $title = 'SSE Events';

    public function display(): string
    {
        $events = SseEventHistory::all();

        if ($events === []) {
            return '<p class="muted">No SSE events were published during this request.</p>';
        }

        $rows = '';

        foreach (array_reverse($events) as $event) {
            $status = $event['status'] === 'published'
                ? '<span style="color:#008000">published</span>'
                : '<span style="color:#b00020">failed</span>';

            $details = $event['error'] ?? $event['publisher'];

            $rows .= '<tr>'
                . '<td>' . self::escape($event['datetime']) . '</td>'
                . '<td>' . $status . '</td>'
                . '<td><code>' . self::escape($event['channel']) . '</code></td>'
                . '<td><code>' . self::escape($event['event']) . '</code></td>'
                . '<td><code>' . self::escape($event['id']) . '</code></td>'
                . '<td class="debug-bar-alignRight">' . self::escape((string) $event['payloadBytes']) . '</td>'
                . '<td>' . self::escape(implode(', ', $event['dataKeys'])) . '</td>'
                . '<td><small>' . self::escape($details) . '</small></td>'
                . '</tr>';
        }

        return '<table>'
            . '<thead>'
            . '<tr>'
            . '<th>Time</th>'
            . '<th>Status</th>'
            . '<th>Channel</th>'
            . '<th>Event</th>'
            . '<th>ID</th>'
            . '<th>Bytes</th>'
            . '<th>Data keys</th>'
            . '<th>Publisher / Error</th>'
            . '</tr>'
            . '</thead>'
            . '<tbody>'
            . $rows
            . '</tbody>'
            . '</table>';
    }

    public function getBadgeValue(): int
    {
        return SseEventHistory::count();
    }

    public function getTitleDetails(): string
    {
        $count = SseEventHistory::count();

        return $count === 1 ? '1 event' : $count . ' events';
    }

    public function isEmpty(): bool
    {
        return false;
    }

    public function icon(): string
    {
        return 'data:image/svg+xml;base64,'
            . 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyNCIgaGVpZ2h0PSIyNCIgdmlld0JveD0iMCAwIDI0IDI0Ij48ZyBmaWxsPSJub25lIiBzdHJva2U9IiNkZDQ4MTQiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIgc3Ryb2tlLXdpZHRoPSIyIj48cGF0aCBkPSJNMTguMzY0IDE5LjM2NGE5IDkgMCAxIDAtMTIuNzI4IDAiLz48cGF0aCBkPSJNMTUuNTM2IDE2LjUzNmE1IDUgMCAxIDAtNy4wNzIgMCIvPjxwYXRoIGQ9Ik0xMSAxM2ExIDEgMCAxIDAgMiAwYTEgMSAwIDEgMC0yIDAiLz48L2c+PC9zdmc+';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
