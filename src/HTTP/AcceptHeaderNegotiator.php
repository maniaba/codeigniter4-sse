<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\HTTP;

final class AcceptHeaderNegotiator
{
    /**
     * @param array<string, string> $supported Name-to-media-type map in server preference order.
     *
     * @return string|null The selected name, or null when no representation is acceptable.
     */
    public function preferred(string $header, array $supported): ?string
    {
        if ($header === '') {
            return null;
        }

        $ranges = $this->parse($header);
        $best   = null;

        foreach ($supported as $name => $mediaType) {
            $preference = $this->preferenceFor($mediaType, $ranges);

            if ($preference === null || $preference['quality'] <= 0.0) {
                continue;
            }

            if ($best === null || $this->isPreferred($preference, $best)) {
                $best = ['name' => $name, ...$preference];
            }
        }

        return $best['name'] ?? null;
    }

    /**
     * @return list<array{type: string, subtype: string, quality: float, order: int}>
     */
    private function parse(string $header): array
    {
        $ranges = [];

        foreach (explode(',', strtolower($header)) as $order => $value) {
            $parts     = array_map(trim(...), explode(';', $value));
            $mediaType = array_shift($parts) ?? '';
            $typeParts = explode('/', $mediaType, 2);

            if (count($typeParts) !== 2) {
                continue;
            }

            $quality = 1.0;

            foreach ($parts as $parameter) {
                $pair = array_map(trim(...), explode('=', $parameter, 2));

                if (($pair[0] ?? '') !== 'q') {
                    continue;
                }

                $quality = $this->parseQuality($pair[1] ?? '');

                break;
            }

            $ranges[] = [
                'type'    => $typeParts[0],
                'subtype' => $typeParts[1],
                'quality' => $quality,
                'order'   => $order,
            ];
        }

        return $ranges;
    }

    /**
     * @param list<array{type: string, subtype: string, quality: float, order: int}> $ranges
     *
     * @return array{quality: float, specificity: int, order: int}|null
     */
    private function preferenceFor(string $mediaType, array $ranges): ?array
    {
        $parts = explode('/', strtolower($mediaType), 2);

        if (count($parts) !== 2) {
            return null;
        }

        $best = null;

        foreach ($ranges as $range) {
            $specificity = $this->specificity(
                $range['type'],
                $range['subtype'],
                $parts[0],
                $parts[1],
            );

            if ($specificity < 0) {
                continue;
            }

            $candidate = [
                'quality'     => $range['quality'],
                'specificity' => $specificity,
                'order'       => $range['order'],
            ];

            if ($best === null || $this->isMoreSpecific($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function specificity(
        string $rangeType,
        string $rangeSubtype,
        string $supportedType,
        string $supportedSubtype,
    ): int {
        if ($rangeType === '*' && $rangeSubtype === '*') {
            return 0;
        }

        if ($rangeType !== $supportedType) {
            return -1;
        }

        if ($rangeSubtype === '*') {
            return 1;
        }

        return $rangeSubtype === $supportedSubtype ? 2 : -1;
    }

    /**
     * @param array{quality: float, specificity: int, order: int} $candidate
     * @param array{quality: float, specificity: int, order: int} $current
     */
    private function isMoreSpecific(array $candidate, array $current): bool
    {
        if ($candidate['specificity'] !== $current['specificity']) {
            return $candidate['specificity'] > $current['specificity'];
        }

        if ($candidate['quality'] !== $current['quality']) {
            return $candidate['quality'] > $current['quality'];
        }

        return $candidate['order'] < $current['order'];
    }

    /**
     * @param array{quality: float, specificity: int, order: int}               $candidate
     * @param array{name: string, quality: float, specificity: int, order: int} $current
     */
    private function isPreferred(array $candidate, array $current): bool
    {
        if ($candidate['quality'] !== $current['quality']) {
            return $candidate['quality'] > $current['quality'];
        }

        if ($candidate['specificity'] !== $current['specificity']) {
            return $candidate['specificity'] > $current['specificity'];
        }

        return $candidate['order'] < $current['order'];
    }

    private function parseQuality(string $value): float
    {
        if (preg_match('/^(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/D', $value) !== 1) {
            return 0.0;
        }

        return (float) $value;
    }
}
