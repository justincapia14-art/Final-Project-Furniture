<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AnalyticsReport;
use Library\Database;

/**
 * PHP Controller & Server-Side Renderer for Monthly Analytics & Business Reports.
 *
 * Generates SVG charts (Daily Revenue, Daily Orders, Weekly Revenue Trend),
 * KPI cards, category revenue breakdowns, product rankings, order status
 * distribution, customer retention tables, inventory snapshot/audit views,
 * voucher analytics, and CSV/PDF export downloads.
 */
final class AnalyticsController
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? new Database();
    }

    /**
     * Generates the full analytics report from the current database store.
     *
     * @param array<string, mixed> $filterOptions
     * @return array<string, mixed>
     */
    public function getReport(array $filterOptions = [], ?string $nowIso = null): array
    {
        $store = $this->db->readStore();
        return AnalyticsReport::generateReport($store, $filterOptions, $nowIso);
    }

    /**
     * Renders the Daily Revenue Line + Area SVG chart for the selected month.
     *
     * @param array<int, array<string, mixed>> $dailySeries
     */
    public static function renderDailyRevenueSvg(array $dailySeries): string
    {
        if (empty($dailySeries)) {
            return '';
        }
        $W = 680;
        $H = 210;
        $padL = 56;
        $padR = 20;
        $padT = 22;
        $padB = 34;
        $plotW = $W - $padL - $padR;
        $plotH = $H - $padT - $padB;

        $revenues = array_map(static fn(array $d): float => (float) ($d['revenue'] ?? 0), $dailySeries);
        $maxRev = max(1000.0, ...$revenues);
        $count = count($dailySeries);

        $points = [];
        foreach ($dailySeries as $idx => $d) {
            $rev = (float) ($d['revenue'] ?? 0);
            $x = $padL + ($count > 1 ? ($idx / ($count - 1)) * $plotW : $plotW / 2);
            $y = $padT + $plotH - ($rev / $maxRev) * $plotH;
            $points[] = [
                'x'          => round($x, 1),
                'y'          => round($y, 1),
                'dayOfMonth' => (int) ($d['dayOfMonth'] ?? ($idx + 1)),
                'label'      => (string) ($d['label'] ?? ($idx + 1)),
                'revenue'    => (int) round($rev),
                'orders'     => (int) ($d['orders'] ?? 0),
            ];
        }

        $polyPoints = implode(' ', array_map(static fn(array $p): string => $p['x'] . ',' . $p['y'], $points));
        $firstX = $points[0]['x'] ?? $padL;
        $lastX = $points[$count - 1]['x'] ?? ($W - $padR);
        $areaPoints = sprintf('%s,%d %s %s,%d', $firstX, $padT + $plotH, $polyPoints, $lastX, $padT + $plotH);

        $gridHtml = '';
        foreach ([0.0, 0.25, 0.5, 0.75, 1.0] as $r) {
            $y = round($padT + $plotH * (1.0 - $r), 1);
            $val = (int) round($maxRev * $r);
            $labelStr = $val >= 1000 ? ('$' . round($val / 1000, 1) . 'k') : ('$' . $val);
            $gridHtml .= sprintf(
                '<line x1="%d" y1="%s" x2="%d" y2="%s" stroke="#E2E8F0" stroke-dasharray="3 3" stroke-width="1" />'
                . '<text x="%d" y="%s" text-anchor="end" fill="#64748B" font-size="10" font-family="monospace">%s</text>',
                $padL,
                $y,
                $W - $padR,
                $y,
                $padL - 8,
                $y + 3,
                htmlspecialchars($labelStr, ENT_QUOTES, 'UTF-8')
            );
        }

        $xLabelsHtml = '';
        foreach ($points as $idx => $p) {
            if ($p['dayOfMonth'] === 1 || $p['dayOfMonth'] % 5 === 0 || $idx === $count - 1) {
                $xLabelsHtml .= sprintf(
                    '<text x="%s" y="%d" text-anchor="middle" fill="#64748B" font-size="10" font-family="monospace">%s</text>',
                    $p['x'],
                    $H - 10,
                    htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8')
                );
            }
        }

        $dotsHtml = '';
        foreach ($points as $p) {
            if ($p['revenue'] > 0) {
                $dotsHtml .= sprintf(
                    '<g><circle cx="%s" cy="%s" r="4.5" fill="#0095FF" stroke="#FFFFFF" stroke-width="2" />'
                    . '<title>%s: $%s (%d order%s)</title></g>',
                    $p['x'],
                    $p['y'],
                    htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8'),
                    number_format($p['revenue']),
                    $p['orders'],
                    $p['orders'] === 1 ? '' : 's'
                );
            }
        }

        return sprintf(
            '<svg viewBox="0 0 %d %d" class="analytics-svg-chart" role="img" aria-label="Daily revenue trend line chart">'
            . '<defs><linearGradient id="revAreaGrad" x1="0" y1="0" x2="0" y2="1">'
            . '<stop offset="0%%" stop-color="#0095FF" stop-opacity="0.26" />'
            . '<stop offset="100%%" stop-color="#0095FF" stop-opacity="0.0" />'
            . '</linearGradient></defs>'
            . '%s<polygon points="%s" fill="url(#revAreaGrad)" />'
            . '<polyline fill="none" stroke="#0095FF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" points="%s" />'
            . '%s%s</svg>',
            $W,
            $H,
            $gridHtml,
            $areaPoints,
            $polyPoints,
            $dotsHtml,
            $xLabelsHtml
        );
    }

    /**
     * Renders the Daily Orders Bar SVG chart for the selected month.
     *
     * @param array<int, array<string, mixed>> $dailySeries
     */
    public static function renderDailyOrdersSvg(array $dailySeries): string
    {
        if (empty($dailySeries)) {
            return '';
        }
        $W = 680;
        $H = 210;
        $padL = 42;
        $padR = 16;
        $padT = 22;
        $padB = 34;
        $plotW = $W - $padL - $padR;
        $plotH = $H - $padT - $padB;

        $counts = array_map(static fn(array $d): int => (int) ($d['orders'] ?? 0), $dailySeries);
        $maxOrders = max(3, ...$counts);
        $n = count($dailySeries);
        $slotW = $plotW / max(1, $n);
        $barW = max(6.0, min(16.0, $slotW * 0.62));

        $gridHtml = '';
        for ($step = 0; $step <= $maxOrders; $step++) {
            $y = round($padT + $plotH * (1.0 - ($step / $maxOrders)), 1);
            $gridHtml .= sprintf(
                '<line x1="%d" y1="%s" x2="%d" y2="%s" stroke="#E2E8F0" stroke-dasharray="3 3" stroke-width="1" />'
                . '<text x="%d" y="%s" text-anchor="end" fill="#64748B" font-size="10" font-family="monospace">%d</text>',
                $padL,
                $y,
                $W - $padR,
                $y,
                $padL - 8,
                $y + 3,
                $step
            );
        }

        $barsHtml = '';
        foreach ($dailySeries as $idx => $d) {
            $orders = (int) ($d['orders'] ?? 0);
            $dayOfMonth = (int) ($d['dayOfMonth'] ?? ($idx + 1));
            $label = (string) ($d['label'] ?? $dayOfMonth);
            $rev = (int) round((float) ($d['revenue'] ?? 0));
            $cx = $padL + $idx * $slotW + $slotW / 2;
            $barH = $orders > 0 ? max(8.0, ($orders / $maxOrders) * $plotH) : 3.0;
            $y = $padT + $plotH - $barH;
            $fill = $orders > 0 ? '#0D1015' : '#E2E8F0';
            $showX = ($dayOfMonth === 1 || $dayOfMonth % 5 === 0 || $idx === $n - 1);

            $barsHtml .= sprintf(
                '<g><rect x="%s" y="%s" width="%s" height="%s" rx="3" fill="%s"><title>%s: %d order(s) · $%s</title></rect>%s%s</g>',
                round($cx - $barW / 2, 1),
                round($y, 1),
                round($barW, 1),
                round($barH, 1),
                $fill,
                htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
                $orders,
                number_format($rev),
                $orders > 0 ? sprintf('<text x="%s" y="%s" text-anchor="middle" fill="#0095FF" font-size="9" font-weight="700" font-family="monospace">%d</text>', round($cx, 1), round($y - 4, 1), $orders) : '',
                $showX ? sprintf('<text x="%s" y="%d" text-anchor="middle" fill="#64748B" font-size="10" font-family="monospace">%d</text>', round($cx, 1), $H - 10, $dayOfMonth) : ''
            );
        }

        return sprintf(
            '<svg viewBox="0 0 %d %d" class="analytics-svg-chart" role="img" aria-label="Orders per day bar chart">%s%s</svg>',
            $W,
            $H,
            $gridHtml,
            $barsHtml
        );
    }
}
