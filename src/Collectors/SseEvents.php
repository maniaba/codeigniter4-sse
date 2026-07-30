<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Collectors;

use CodeIgniter\Debug\Toolbar\Collectors\BaseCollector;
use Maniaba\CodeIgniterSse\Debug\Toolbar\SseEventHistory;

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
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">'
            . '<g fill="none" stroke="#dd4814" stroke-linecap="round" stroke-linejoin="round" stroke-width="2">'
            . '<path d="M18.364 19.364a9 9 0 1 0-12.728 0"/>'
            . '<path d="M15.536 16.536a5 5 0 1 0-7.072 0"/>'
            . '<path d="M11 13a1 1 0 1 0 2 0a1 1 0 1 0-2 0"/>'
            . '</g>'
            . '</svg>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
