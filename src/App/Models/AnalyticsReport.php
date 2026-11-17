<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use DateTimeZone;
use Library\OrderCompletion;

/**
 * Full Monthly Analytics & Business Reporting Model (Asia/Manila).
 *
 * Recognizes revenue and sales metrics exclusively from completed orders,
 * while also computing daily/monthly series, category revenue breakdown,
 * top-selling & low-performing products, order status distribution,
 * customer retention & registration trends, live vs. period inventory
 * analytics, fixed-amount voucher analytics, CSV/PDF binary exports,
 * and server-side SVG/HTML report rendering.
 */
final class AnalyticsReport
{
    private static ?\Library\Database $instanceDb = null;

    public function __construct(private ?\Library\Database $db = null)
    {
        self::$instanceDb = $this->db;
    }

    public const MONTH_NAMES_FULL = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    public const MONTH_NAMES_SHORT = [
        'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];

    public static function getDaysInMonth(int $year, int $month): int
    {
        $safeMonth = max(1, min(12, $month));
        $dt = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $safeMonth), new DateTimeZone('Asia/Manila'));
        return (int) $dt->format('t');
    }

    public static function parseManilaDateOnly(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $str = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $str, $m)) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            $d = (int) $m[3];
            if ($mo < 1 || $mo > 12) {
                return null;
            }
            $maxDay = self::getDaysInMonth($y, $mo);
            if ($d < 1 || $d > $maxDay) {
                return null;
            }
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        try {
            $dt = new DateTimeImmutable($str);
            return $dt->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function formatManilaTimestamp(?int $nowMs = null): string
    {
        $ts = $nowMs !== null ? (int) floor($nowMs / 1000) : time();
        $dt = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Asia/Manila'));
        return $dt->format('M j, Y · g:i A') . ' PHT';
    }

    /**
     * Resolves an analytics date window and its prior comparison period in Asia/Manila.
     *
     * @param array<string, mixed> $filterOptions
     * @return array<string, mixed>
     */
    public static function resolveDateRange(array $filterOptions = [], ?string $nowIso = null, ?int $nowMs = null): array
    {
        $tz = new DateTimeZone('Asia/Manila');
        if ($nowMs !== null) {
            $now = (new DateTimeImmutable('@' . (int) floor($nowMs / 1000)))->setTimezone($tz);
        } elseif ($nowIso !== null && trim($nowIso) !== '') {
            $now = new DateTimeImmutable($nowIso, $tz);
        } else {
            $now = new DateTimeImmutable('now', $tz);
        }

        $nowYear = (int) $now->format('Y');
        $nowMonth = (int) $now->format('n');

        $preset = strtolower(trim((string) ($filterOptions['preset'] ?? 'this_month')));
        $startDate = '';
        $endDate = '';
        $previousStartDate = '';
        $previousEndDate = '';
        $periodLabel = '';
        $previousPeriodLabel = '';
        $selectedYear = $nowYear;
        $selectedMonth = $nowMonth;
        $resolvedPreset = $preset;

        if ($preset === 'last_month') {
            $prevMonth = $nowMonth === 1 ? 12 : $nowMonth - 1;
            $prevYear = $nowMonth === 1 ? $nowYear - 1 : $nowYear;
            $twoMonthsAgo = $prevMonth === 1 ? 12 : $prevMonth - 1;
            $twoMonthsAgoYear = $prevMonth === 1 ? $prevYear - 1 : $prevYear;

            $selectedYear = $prevYear;
            $selectedMonth = $prevMonth;
            $startDate = sprintf('%04d-%02d-01', $prevYear, $prevMonth);
            $endDate = sprintf('%04d-%02d-%02d', $prevYear, $prevMonth, self::getDaysInMonth($prevYear, $prevMonth));
            $previousStartDate = sprintf('%04d-%02d-01', $twoMonthsAgoYear, $twoMonthsAgo);
            $previousEndDate = sprintf('%04d-%02d-%02d', $twoMonthsAgoYear, $twoMonthsAgo, self::getDaysInMonth($twoMonthsAgoYear, $twoMonthsAgo));
            $periodLabel = self::MONTH_NAMES_FULL[$prevMonth - 1] . ' ' . $prevYear;
            $previousPeriodLabel = self::MONTH_NAMES_FULL[$twoMonthsAgo - 1] . ' ' . $twoMonthsAgoYear;
        } elseif ($preset === 'specific_month' || $preset === 'select_month') {
            $resolvedPreset = 'select_month';
            $reqYear = (int) ($filterOptions['year'] ?? $nowYear);
            $reqMonth = (int) ($filterOptions['month'] ?? $nowMonth);
            if ($reqYear < 2000 || $reqYear > 2100) {
                $reqYear = $nowYear;
            }
            if ($reqMonth < 1 || $reqMonth > 12) {
                $reqMonth = $nowMonth;
            }
            $prevM = $reqMonth === 1 ? 12 : $reqMonth - 1;
            $prevY = $reqMonth === 1 ? $reqYear - 1 : $reqYear;

            $selectedYear = $reqYear;
            $selectedMonth = $reqMonth;
            $startDate = sprintf('%04d-%02d-01', $reqYear, $reqMonth);
            $endDate = sprintf('%04d-%02d-%02d', $reqYear, $reqMonth, self::getDaysInMonth($reqYear, $reqMonth));
            $previousStartDate = sprintf('%04d-%02d-01', $prevY, $prevM);
            $previousEndDate = sprintf('%04d-%02d-%02d', $prevY, $prevM, self::getDaysInMonth($prevY, $prevM));
            $periodLabel = self::MONTH_NAMES_FULL[$reqMonth - 1] . ' ' . $reqYear;
            $previousPeriodLabel = self::MONTH_NAMES_FULL[$prevM - 1] . ' ' . $prevY;
        } elseif ($preset === 'this_year') {
            $reqYear = (int) ($filterOptions['year'] ?? $nowYear);
            if ($reqYear < 2000 || $reqYear > 2100) {
                $reqYear = $nowYear;
            }
            $selectedYear = $reqYear;
            $selectedMonth = $nowMonth;
            $startDate = sprintf('%04d-01-01', $reqYear);
            $endDate = sprintf('%04d-12-31', $reqYear);
            $previousStartDate = sprintf('%04d-01-01', $reqYear - 1);
            $previousEndDate = sprintf('%04d-12-31', $reqYear - 1);
            $periodLabel = 'Full Year ' . $reqYear;
            $previousPeriodLabel = 'Full Year ' . ($reqYear - 1);
        } elseif ($preset === 'custom' || $preset === 'custom_range') {
            $resolvedPreset = 'custom_range';
            $parsedStart = self::parseManilaDateOnly($filterOptions['startDate'] ?? '');
            $parsedEnd = self::parseManilaDateOnly($filterOptions['endDate'] ?? '');
            if ($parsedStart && $parsedEnd) {
                if ($parsedStart <= $parsedEnd) {
                    $startDate = $parsedStart;
                    $endDate = $parsedEnd;
                } else {
                    $startDate = $parsedEnd;
                    $endDate = $parsedStart;
                }
                $startDt = new DateTimeImmutable($startDate . ' 00:00:00', $tz);
                $endDt = new DateTimeImmutable($endDate . ' 00:00:00', $tz);
                $spanDays = max(1, (int) $startDt->diff($endDt)->days + 1);
                $prevEndDt = $startDt->modify('-1 day');
                $prevStartDt = $prevEndDt->modify('-' . ($spanDays - 1) . ' days');

                $previousStartDate = $prevStartDt->format('Y-m-d');
                $previousEndDate = $prevEndDt->format('Y-m-d');
                $periodLabel = $startDate . ' to ' . $endDate;
                $previousPeriodLabel = $previousStartDate . ' to ' . $previousEndDate;
                $selectedYear = (int) substr($endDate, 0, 4);
                $selectedMonth = (int) substr($endDate, 5, 2);
            } else {
                $resolvedPreset = 'this_month';
            }
        }

        if ($startDate === '' || $endDate === '') {
            $resolvedPreset = 'this_month';
            $prevM = $nowMonth === 1 ? 12 : $nowMonth - 1;
            $prevY = $nowMonth === 1 ? $nowYear - 1 : $nowYear;
            $selectedYear = $nowYear;
            $selectedMonth = $nowMonth;
            $startDate = sprintf('%04d-%02d-01', $nowYear, $nowMonth);
            $endDate = sprintf('%04d-%02d-%02d', $nowYear, $nowMonth, self::getDaysInMonth($nowYear, $nowMonth));
            $previousStartDate = sprintf('%04d-%02d-01', $prevY, $prevM);
            $previousEndDate = sprintf('%04d-%02d-%02d', $prevY, $prevM, self::getDaysInMonth($prevY, $prevM));
            $periodLabel = self::MONTH_NAMES_FULL[$nowMonth - 1] . ' ' . $nowYear;
            $previousPeriodLabel = self::MONTH_NAMES_FULL[$prevM - 1] . ' ' . $prevY;
        }

        $curStartDt = new DateTimeImmutable($startDate . ' 00:00:00', $tz);
        $curEndDt = new DateTimeImmutable($endDate . ' 00:00:00', $tz);
        $prevStartDt = new DateTimeImmutable($previousStartDate . ' 00:00:00', $tz);
        $prevEndDt = new DateTimeImmutable($previousEndDate . ' 00:00:00', $tz);
        $currentDayCount = max(1, (int) $curStartDt->diff($curEndDt)->days + 1);
        $previousDayCount = max(1, (int) $prevStartDt->diff($prevEndDt)->days + 1);

        return [
            'preset'              => $resolvedPreset,
            'startDate'           => $startDate,
            'endDate'             => $endDate,
            'previousStartDate'   => $previousStartDate,
            'previousEndDate'     => $previousEndDate,
            'periodLabel'         => $periodLabel,
            'previousPeriodLabel' => $previousPeriodLabel,
            'selectedYear'        => $selectedYear,
            'selectedMonth'       => $selectedMonth,
            'timezone'            => 'Asia/Manila',
            'current'             => [
                'startDate' => $startDate,
                'endDate'   => $endDate,
                'label'     => $periodLabel,
                'year'      => $selectedYear,
                'month'     => $selectedMonth,
                'dayCount'  => $currentDayCount,
            ],
            'previous'            => [
                'startDate' => $previousStartDate,
                'endDate'   => $previousEndDate,
                'label'     => $previousPeriodLabel,
                'dayCount'  => $previousDayCount,
            ],
        ];
    }

    /**
     * Safely computes percentage change without division-by-zero errors.
     *
     * @return array<string, mixed>
     */
    public static function computePercentageChange(float|int $currentValue, float|int $previousValue): array
    {
        $curr = is_finite((float) $currentValue) ? (float) $currentValue : 0.0;
        $prev = is_finite((float) $previousValue) ? (float) $previousValue : 0.0;
        $delta = $curr - $prev;

        if ($prev == 0.0) {
            if ($curr == 0.0) {
                return [
                    'currentValue'    => 0,
                    'previousValue'   => 0,
                    'delta'           => 0,
                    'percentChange'   => 0.0,
                    'direction'       => 'flat',
                    'formatted'       => '0.0%',
                    'formattedChange' => '0.0%',
                    'note'            => 'No change from previous period (0)',
                    'isNewFromZero'   => false,
                    'previousZero'    => true,
                ];
            }
            $dir = $curr > 0 ? 'up' : 'down';
            $pct = $curr > 0 ? 100.0 : -100.0;
            $fmt = $curr > 0 ? '+100.0% (New)' : '-100.0%';
            return [
                'currentValue'    => $curr,
                'previousValue'   => 0,
                'delta'           => $delta,
                'percentChange'   => $pct,
                'direction'       => $dir,
                'formatted'       => $fmt,
                'formattedChange' => $fmt,
                'note'            => 'Prior period was 0',
                'isNewFromZero'   => true,
                'previousZero'    => true,
            ];
        }

        $rawPct = (($curr - $prev) / abs($prev)) * 100.0;
        $roundedPct = round($rawPct, 1);
        $direction = $roundedPct > 0 ? 'up' : ($roundedPct < 0 ? 'down' : 'flat');
        $sign = $roundedPct > 0 ? '+' : '';
        $fmt = sprintf('%s%.1f%%', $sign, $roundedPct);

        return [
            'currentValue'    => $curr,
            'previousValue'   => $prev,
            'delta'           => $delta,
            'percentChange'   => $roundedPct,
            'direction'       => $direction,
            'formatted'       => $fmt,
            'formattedChange' => $fmt,
            'note'            => 'Compared against previous period',
            'isNewFromZero'   => false,
            'previousZero'    => false,
        ];
    }

    /**
     * Resolves the report recognition date for a completed order.
     *
     * @param array<string, mixed> $order
     */
    public static function getAnalyticsOrderDate(array $order): ?string
    {
        $rawDate = trim((string) ($order['completedAt'] ?? $order['orderDate'] ?? ''));
        return self::parseManilaDateOnly($rawDate);
    }

    /**
     * Computes historical order amount, preserving original order price.
     *
     * @param array<string, mixed> $order
     */
    public static function getHistoricalOrderAmount(array $order): int
    {
        if (isset($order['totalAmount']) && is_numeric($order['totalAmount']) && (float) $order['totalAmount'] >= 0) {
            return (int) round((float) $order['totalAmount']);
        }
        $qty = max(1, (int) ($order['quantity'] ?? 1));
        $unitPrice = max(0.0, (float) ($order['unitPrice'] ?? 0));
        $extraCost = max(0.0, (float) ($order['extraCost'] ?? 0));
        $discount = max(0.0, (float) ($order['voucherDiscountAmount'] ?? 0));
        return max(0, (int) round($unitPrice * $qty + $extraCost - $discount));
    }

    /**
     * Generates the complete Monthly Analytics & Business Report in PHP.
     *
     * @param array<string, mixed> $storeData
     * @param array<string, mixed> $filterOptions
     * @return array<string, mixed>
     */
    public static function generateReport(array $storeData = [], array $filterOptions = [], ?string $nowIso = null): array
    {
        $hasStoreArrays = isset($storeData['orders'])
            || isset($storeData['catalog'])
            || isset($storeData['products'])
            || isset($storeData['rawMaterials'])
            || isset($storeData['materials'])
            || isset($storeData['vouchers']);

        if (!$hasStoreArrays && (isset($storeData['preset']) || isset($storeData['year']) || isset($storeData['month']) || isset($storeData['startDate']) || empty($storeData))) {
            $filterOptions = $storeData;
            $dbInstance = self::$instanceDb ?? new \Library\Database();
            $storeData = $dbInstance->readStore();
        }

        $effectiveFilter = $storeData['filterInput'] ?? $storeData['filterOptions'] ?? $filterOptions;
        if (!is_array($effectiveFilter)) {
            $effectiveFilter = [];
        }
        $nowMs = isset($storeData['nowMs']) && is_numeric($storeData['nowMs'])
            ? (int) $storeData['nowMs']
            : ($nowIso !== null ? (int) (strtotime($nowIso) * 1000) : (int) (microtime(true) * 1000));

        $range = self::resolveDateRange($effectiveFilter, $nowIso, $nowMs);
        $products = is_array($storeData['catalog'] ?? null)
            ? $storeData['catalog']
            : (is_array($storeData['products'] ?? null) ? $storeData['products'] : []);
        $categories = is_array($storeData['categories'] ?? null) ? $storeData['categories'] : [];
        $materials = is_array($storeData['materials'] ?? null)
            ? $storeData['materials']
            : (is_array($storeData['rawMaterials'] ?? null) ? $storeData['rawMaterials'] : []);
        $rawOrders = is_array($storeData['orders'] ?? null) ? $storeData['orders'] : [];
        $vouchers = is_array($storeData['vouchers'] ?? null) ? $storeData['vouchers'] : [];
        $customers = is_array($storeData['customers'] ?? null) ? $storeData['customers'] : [];
        $inventoryTransactions = is_array($storeData['inventoryTransactions'] ?? null) ? $storeData['inventoryTransactions'] : [];

        // Deduplicate operational orders
        $seenKeys = [];
        $operationalOrders = [];
        foreach ($rawOrders as $ord) {
            if (!is_array($ord)) {
                continue;
            }
            $key = strtoupper(trim((string) ($ord['orderId'] ?? $ord['orderNumber'] ?? $ord['id'] ?? '')));
            if ($key !== '' && isset($seenKeys[$key])) {
                continue;
            }
            if ($key !== '') {
                $seenKeys[$key] = true;
            }
            $operationalOrders[] = $ord;
        }

        // Filter strictly to completed orders
        $completedOrders = array_values(array_filter(
            $operationalOrders,
            static fn(array $ord): bool => OrderCompletion::isOrderCompleted($ord)
        ));

        $productById = [];
        $productBySku = [];
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            if (!empty($p['id'])) {
                $productById[(string) $p['id']] = $p;
            }
            if (!empty($p['sku'])) {
                $productBySku[strtoupper((string) $p['sku'])] = $p;
            }
            if (!empty($p['productId'])) {
                $productBySku[strtoupper((string) $p['productId'])] = $p;
            }
        }

        $isWithinRange = static function (mixed $dateVal, string $startStr, string $endStr): bool {
            $d = self::parseManilaDateOnly($dateVal);
            return $d !== null && $d >= $startStr && $d <= $endStr;
        };

        $computeWindowMetrics = static function (string $startStr, string $endStr) use ($completedOrders, $customers, $isWithinRange): array {
            $periodOrders = [];
            $totalRevenue = 0.0;
            $totalProductsSold = 0;
            $totalDiscountsGiven = 0;
            $seenItems = [];

            foreach ($completedOrders as $ord) {
                $compDate = self::getAnalyticsOrderDate($ord);
                if ($compDate === null || $compDate < $startStr || $compDate > $endStr) {
                    continue;
                }
                $periodOrders[] = $ord;
                $totalRevenue += (float) self::getHistoricalOrderAmount($ord);
                $itemKey = (string) ($ord['orderItemId'] ?? $ord['orderId'] ?? $ord['orderNumber'] ?? $ord['id'] ?? '');
                if ($itemKey === '' || !isset($seenItems[$itemKey])) {
                    if ($itemKey !== '') {
                        $seenItems[$itemKey] = true;
                    }
                    $totalProductsSold += max(1, (int) ($ord['quantity'] ?? 1));
                }
                $totalDiscountsGiven += max(0, (int) round((float) ($ord['voucherDiscountAmount'] ?? 0)));
            }

            $newCustomersInWindow = array_values(array_filter($customers, static function ($c) use ($startStr, $endStr): bool {
                if (!is_array($c)) {
                    return false;
                }
                $regDate = self::parseManilaDateOnly($c['registeredAt'] ?? $c['createdAt'] ?? null);
                return $regDate !== null && $regDate >= $startStr && $regDate <= $endStr;
            }));

            $count = count($periodOrders);
            $avgOrderValue = $count > 0 ? (int) round($totalRevenue / $count) : 0;

            return [
                'periodOrders'              => $periodOrders,
                'totalRevenue'              => (float) $totalRevenue,
                'grossBookedRevenue'        => (float) $totalRevenue,
                'totalOrders'               => $count,
                'completedOrders'           => $count,
                'pendingOrders'             => 0,
                'processingOrders'          => 0,
                'shippedOrders'             => 0,
                'cancelledOrders'           => 0,
                'totalProductsSold'         => $totalProductsSold,
                'newCustomers'              => count($newCustomersInWindow),
                'newCustomersList'          => $newCustomersInWindow,
                'totalDiscountsGiven'       => $totalDiscountsGiven,
                'averageOrderValue'         => $avgOrderValue,
                'revenueEligibleOrderCount' => $count,
            ];
        };

        $currentMetrics = $computeWindowMetrics($range['startDate'], $range['endDate']);
        $previousMetrics = $computeWindowMetrics($range['previousStartDate'], $range['previousEndDate']);

        $formatUsd = static fn(int|float $val): string => '$' . number_format((int) round((float) $val));

        $buildKpi = static function (
            string $key,
            string $label,
            int|float $currVal,
            int|float $prevVal,
            string $formattedCurr,
            string $formattedPrev,
            string $subtitle
        ): array {
            $comp = self::computePercentageChange($currVal, $prevVal);
            return [
                'key'                    => $key,
                'label'                  => $label,
                'value'                  => $currVal,
                'previousValue'          => $prevVal,
                'formattedValue'         => $formattedCurr,
                'formattedPreviousValue' => $formattedPrev,
                'subtitle'               => $subtitle,
                'percentChange'          => $comp['percentChange'],
                'direction'              => $comp['direction'],
                'formatted'              => $comp['formatted'],
                'formattedChange'        => $comp['formattedChange'],
                'isNewFromZero'          => $comp['isNewFromZero'],
                'previousZero'           => $comp['previousZero'],
                'comparison'             => $comp,
            ];
        };

        $summary = [
            'totalRevenue' => $buildKpi(
                'totalRevenue',
                'Total Revenue',
                (float) $currentMetrics['totalRevenue'],
                (float) $previousMetrics['totalRevenue'],
                $formatUsd($currentMetrics['totalRevenue']),
                $formatUsd($previousMetrics['totalRevenue']),
                $currentMetrics['revenueEligibleOrderCount'] . ' completed order(s)'
            ),
            'totalOrders' => $buildKpi(
                'totalOrders',
                'Total Orders',
                $currentMetrics['totalOrders'],
                $previousMetrics['totalOrders'],
                (string) $currentMetrics['totalOrders'],
                (string) $previousMetrics['totalOrders'],
                'Completed sales: ' . $formatUsd($currentMetrics['grossBookedRevenue'])
            ),
            'completedOrders' => $buildKpi(
                'completedOrders',
                'Completed Orders',
                $currentMetrics['completedOrders'],
                $previousMetrics['completedOrders'],
                (string) $currentMetrics['completedOrders'],
                (string) $previousMetrics['completedOrders'],
                'Delivered & settled in full'
            ),
            'pendingOrders' => $buildKpi(
                'pendingOrders',
                'Pending Orders',
                $currentMetrics['pendingOrders'],
                $previousMetrics['pendingOrders'],
                (string) $currentMetrics['pendingOrders'],
                (string) $previousMetrics['pendingOrders'],
                '0 in workshop · 0 ready/shipped'
            ),
            'cancelledOrders' => $buildKpi(
                'cancelledOrders',
                'Cancelled Orders',
                $currentMetrics['cancelledOrders'],
                $previousMetrics['cancelledOrders'],
                (string) $currentMetrics['cancelledOrders'],
                (string) $previousMetrics['cancelledOrders'],
                'Excluded from realized revenue'
            ),
            'totalProductsSold' => $buildKpi(
                'totalProductsSold',
                'Total Products Sold',
                $currentMetrics['totalProductsSold'],
                $previousMetrics['totalProductsSold'],
                $currentMetrics['totalProductsSold'] . ' pcs',
                $previousMetrics['totalProductsSold'] . ' pcs',
                'Furniture units in completed orders'
            ),
            'newCustomers' => $buildKpi(
                'newCustomers',
                'New Customers',
                $currentMetrics['newCustomers'],
                $previousMetrics['newCustomers'],
                (string) $currentMetrics['newCustomers'],
                (string) $previousMetrics['newCustomers'],
                count($customers) . ' total registered clients'
            ),
            'totalDiscountsGiven' => $buildKpi(
                'totalDiscountsGiven',
                'Total Discounts Given',
                $currentMetrics['totalDiscountsGiven'],
                $previousMetrics['totalDiscountsGiven'],
                $formatUsd($currentMetrics['totalDiscountsGiven']),
                $formatUsd($previousMetrics['totalDiscountsGiven']),
                'Fixed-amount vouchers redeemed'
            ),
            'averageOrderValue' => $buildKpi(
                'averageOrderValue',
                'Average Order Value',
                $currentMetrics['averageOrderValue'],
                $previousMetrics['averageOrderValue'],
                $formatUsd($currentMetrics['averageOrderValue']),
                $formatUsd($previousMetrics['averageOrderValue']),
                'Revenue per completed order'
            ),
            'grossOrderValue'       => $currentMetrics['grossBookedRevenue'],
            'processingOrdersCount' => 0,
            'shippedOrdersCount'    => 0,
        ];

        $summaryCards = [
            $summary['totalRevenue'],
            $summary['totalOrders'],
            $summary['completedOrders'],
            $summary['pendingOrders'],
            $summary['cancelledOrders'],
            $summary['totalProductsSold'],
            $summary['newCustomers'],
            $summary['totalDiscountsGiven'],
            $summary['averageOrderValue'],
        ];

        // 4a. Daily series throughout the selected month
        $daysInTargetMonth = self::getDaysInMonth($range['selectedYear'], $range['selectedMonth']);
        $dailySeries = [];
        for ($d = 1; $d <= $daysInTargetMonth; $d++) {
            $dateKey = sprintf('%04d-%02d-%02d', $range['selectedYear'], $range['selectedMonth'], $d);
            $dayRev = 0;
            $dayUnits = 0;
            $dayCount = 0;
            foreach ($completedOrders as $ord) {
                if (self::getAnalyticsOrderDate($ord) === $dateKey) {
                    $dayCount++;
                    $dayRev += self::getHistoricalOrderAmount($ord);
                    $dayUnits += max(1, (int) ($ord['quantity'] ?? 1));
                }
            }
            $shortLbl = self::MONTH_NAMES_SHORT[$range['selectedMonth'] - 1] . ' ' . $d;
            $dailySeries[] = [
                'date'                   => $dateKey,
                'dayOfMonth'             => $d,
                'dayLabel'               => (string) $d,
                'label'                  => $shortLbl,
                'shortLabel'             => $shortLbl,
                'revenue'                => $dayRev,
                'orders'                 => $dayCount,
                'orderCount'             => $dayCount,
                'completedOrders'        => $dayCount,
                'nonCancelledOrderCount' => $dayCount,
                'unitsSold'              => $dayUnits,
            ];
        }

        // 4b. Monthly series across the selected year
        $monthlySeries = [];
        for ($m = 1; $m <= 12; $m++) {
            $mStart = sprintf('%04d-%02d-01', $range['selectedYear'], $m);
            $mEnd = sprintf('%04d-%02d-%02d', $range['selectedYear'], $m, self::getDaysInMonth($range['selectedYear'], $m));
            $mWin = $computeWindowMetrics($mStart, $mEnd);
            $prevMonthRev = $m > 1 ? $monthlySeries[$m - 2]['revenue'] : 0;
            $momChange = self::computePercentageChange($mWin['totalRevenue'], $prevMonthRev);

            $monthlySeries[] = [
                'month'                => $m,
                'monthIndex'           => $m,
                'monthKey'             => sprintf('%04d-%02d', $range['selectedYear'], $m),
                'monthName'            => self::MONTH_NAMES_FULL[$m - 1],
                'fullLabel'            => self::MONTH_NAMES_FULL[$m - 1] . ' ' . $range['selectedYear'],
                'label'                => self::MONTH_NAMES_SHORT[$m - 1],
                'shortName'            => self::MONTH_NAMES_SHORT[$m - 1],
                'revenue'              => $mWin['totalRevenue'],
                'grossBookedRevenue'   => $mWin['grossBookedRevenue'],
                'orders'               => $mWin['totalOrders'],
                'orderCount'           => $mWin['totalOrders'],
                'completedOrders'      => $mWin['completedOrders'],
                'productsSold'         => $mWin['totalProductsSold'],
                'vsPreviousMonth'      => $momChange,
                'monthOverMonthChange' => $momChange,
                'isSelectedMonth'      => $m === $range['selectedMonth'],
            ];
        }

        // 4c. Revenue by Product Category
        $categoryMap = [];
        foreach ($categories as $cat) {
            if (!is_array($cat) || empty($cat['id'])) {
                continue;
            }
            $cid = strtolower((string) $cat['id']);
            $categoryMap[$cid] = [
                'categoryId'   => $cid,
                'categoryCode' => (string) ($cat['categoryId'] ?? $cat['code'] ?? 'CAT-LR'),
                'categoryName' => (string) ($cat['name'] ?? 'Collection'),
                'revenue'      => 0,
                'unitsSold'    => 0,
                'orders'       => 0,
                'orderCount'   => 0,
            ];
        }

        foreach ($currentMetrics['periodOrders'] as $ord) {
            $matchedProd = $productById[(string) ($ord['furnitureId'] ?? '')]
                ?? $productBySku[strtoupper((string) ($ord['sku'] ?? ''))]
                ?? null;
            $slug = 'living';
            if (is_array($matchedProd) && !empty($matchedProd['categoryId'])) {
                $slug = strtolower((string) $matchedProd['categoryId']);
            } else {
                $rawCat = strtolower((string) ($ord['categoryName'] ?? ''));
                if (str_contains($rawCat, 'dining')) {
                    $slug = 'dining';
                } elseif (str_contains($rawCat, 'bed')) {
                    $slug = 'bedroom';
                } elseif (str_contains($rawCat, 'study') || str_contains($rawCat, 'office') || str_contains($rawCat, 'credenza') || str_contains($rawCat, 'desk')) {
                    $slug = 'office';
                } elseif (str_contains($rawCat, 'kitchen')) {
                    $slug = 'kitchen';
                }
            }
            if (!isset($categoryMap[$slug])) {
                $categoryMap[$slug] = [
                    'categoryId'   => $slug,
                    'categoryCode' => 'CAT-LR',
                    'categoryName' => (string) ($ord['categoryName'] ?? 'Collection'),
                    'revenue'      => 0,
                    'unitsSold'    => 0,
                    'orders'       => 0,
                    'orderCount'   => 0,
                ];
            }
            $categoryMap[$slug]['orders']++;
            $categoryMap[$slug]['orderCount']++;
            $categoryMap[$slug]['unitsSold'] += max(1, (int) ($ord['quantity'] ?? 1));
            $categoryMap[$slug]['revenue'] += self::getHistoricalOrderAmount($ord);
        }

        $totalCatRev = max(1, array_sum(array_column($categoryMap, 'revenue')));
        $categoryRevenue = array_values($categoryMap);
        foreach ($categoryRevenue as &$catRow) {
            $catRow['sharePercent'] = $currentMetrics['totalRevenue'] > 0
                ? round(($catRow['revenue'] / $totalCatRev) * 100, 1)
                : 0.0;
        }
        unset($catRow);
        usort($categoryRevenue, static fn(array $a, array $b): int => ($b['revenue'] <=> $a['revenue']) ?: ($b['unitsSold'] <=> $a['unitsSold']));

        $revComparison = self::computePercentageChange($currentMetrics['totalRevenue'], $previousMetrics['totalRevenue']);
        $performanceStatus = $revComparison['percentChange'] > 0
            ? 'Improving'
            : ($revComparison['percentChange'] < 0 ? 'Declining' : 'Stable');
        $performanceDirection = strtolower($performanceStatus);
        if ($performanceStatus === 'Improving') {
            $performanceInsight = sprintf(
                'Realized revenue increased by %s (%s vs %s in %s).',
                $revComparison['formatted'],
                $formatUsd($currentMetrics['totalRevenue']),
                $formatUsd($previousMetrics['totalRevenue']),
                $range['previousPeriodLabel']
            );
        } elseif ($performanceStatus === 'Declining') {
            $performanceInsight = sprintf(
                'Realized revenue shifted by %s (%s vs %s in %s) while workshop orders remain in production.',
                $revComparison['formatted'],
                $formatUsd($currentMetrics['totalRevenue']),
                $formatUsd($previousMetrics['totalRevenue']),
                $range['previousPeriodLabel']
            );
        } else {
            $performanceInsight = sprintf(
                'Realized revenue is steady at %s compared to %s.',
                $formatUsd($currentMetrics['totalRevenue']),
                $range['previousPeriodLabel']
            );
        }

        $salesAnalytics = [
            'year'                 => $range['selectedYear'],
            'dailySeries'          => $dailySeries,
            'monthlySeries'        => $monthlySeries,
            'monthlyComparison'    => $monthlySeries,
            'categoryRevenue'      => $categoryRevenue,
            'revenueByCategory'    => $categoryRevenue,
            'performanceDirection' => $performanceDirection,
            'performanceInsight'   => $performanceInsight,
            'performanceTrend'     => [
                'status'          => $performanceStatus,
                'comparison'      => $revComparison,
                'currentRevenue'  => $currentMetrics['totalRevenue'],
                'previousRevenue' => $previousMetrics['totalRevenue'],
                'summaryText'     => $performanceInsight,
            ],
        ];

        // 5. Best-Selling & Low-Selling Products
        $productSalesMap = [];
        foreach ($products as $prod) {
            if (!is_array($prod) || empty($prod['id'])) {
                continue;
            }
            $avail = max(0, (int) ($prod['inStockCount'] ?? $prod['stockQuantity'] ?? 0));
            $minStock = max(0, (int) ($prod['minStockLevel'] ?? 2));
            $stKey = $avail === 0 ? 'out_of_stock' : ($avail <= $minStock ? 'low_stock' : 'in_stock');
            $stLbl = $avail === 0 ? 'Out of Stock' : ($avail <= $minStock ? 'Low Stock' : 'In Stock');
            $pid = (string) $prod['id'];
            $productSalesMap[$pid] = [
                'id'               => $pid,
                'productId'        => (string) ($prod['productId'] ?? $prod['sku'] ?? 'LR-ITEM'),
                'sku'              => (string) ($prod['sku'] ?? $prod['productId'] ?? 'LR-ITEM'),
                'name'             => (string) ($prod['name'] ?? 'Product'),
                'categoryId'       => (string) ($prod['categoryId'] ?? 'living'),
                'categoryName'     => (string) ($prod['categoryName'] ?? 'Showroom Collection'),
                'image'            => (string) ($prod['image'] ?? 'assets/images/category_living_lounge.jpg'),
                'quantitySold'     => 0,
                'totalRevenue'     => 0,
                'orderCount'       => 0,
                'currentStock'     => $avail,
                'minStockLevel'    => $minStock,
                'stockStatusKey'   => $stKey,
                'stockStatusLabel' => $stLbl,
            ];
        }

        $seenProdItems = [];
        foreach ($currentMetrics['periodOrders'] as $ord) {
            $itemKey = (string) ($ord['orderItemId'] ?? $ord['orderId'] ?? $ord['orderNumber'] ?? $ord['id'] ?? '');
            if ($itemKey !== '' && isset($seenProdItems[$itemKey])) {
                continue;
            }
            if ($itemKey !== '') {
                $seenProdItems[$itemKey] = true;
            }
            $matchedProd = $productById[(string) ($ord['furnitureId'] ?? '')]
                ?? $productBySku[strtoupper((string) ($ord['sku'] ?? ''))]
                ?? null;
            $mapKey = is_array($matchedProd)
                ? (string) $matchedProd['id']
                : (string) ($ord['furnitureId'] ?? $ord['sku'] ?? $ord['id'] ?? 'custom');
            if (!isset($productSalesMap[$mapKey])) {
                $productSalesMap[$mapKey] = [
                    'id'               => $mapKey,
                    'productId'        => (string) ($ord['sku'] ?? 'LR-CUSTOM'),
                    'sku'              => (string) ($ord['sku'] ?? 'LR-CUSTOM'),
                    'name'             => (string) ($ord['furnitureName'] ?? 'Custom Piece'),
                    'categoryId'       => 'living',
                    'categoryName'     => (string) ($ord['categoryName'] ?? 'Bespoke Commission'),
                    'image'            => 'assets/images/category_living_lounge.jpg',
                    'quantitySold'     => 0,
                    'totalRevenue'     => 0,
                    'orderCount'       => 0,
                    'currentStock'     => 1,
                    'minStockLevel'    => 1,
                    'stockStatusKey'   => 'in_stock',
                    'stockStatusLabel' => 'In Stock',
                ];
            }
            $qty = max(1, (int) ($ord['quantity'] ?? 1));
            $productSalesMap[$mapKey]['quantitySold'] += $qty;
            $productSalesMap[$mapKey]['orderCount']++;
            $productSalesMap[$mapKey]['totalRevenue'] += self::getHistoricalOrderAmount($ord);
        }

        $allProductRankings = array_values($productSalesMap);
        usort($allProductRankings, static function (array $a, array $b): int {
            return ($b['totalRevenue'] <=> $a['totalRevenue'])
                ?: ($b['quantitySold'] <=> $a['quantitySold'])
                ?: strcmp($a['name'], $b['name']);
        });
        foreach ($allProductRankings as $idx => &$item) {
            $item['rank'] = $idx + 1;
        }
        unset($item);

        $topFiveProducts = array_slice($allProductRankings, 0, 5);
        $lowPerformingProducts = array_values(array_filter(
            $allProductRankings,
            static fn(array $it): bool => $it['quantitySold'] <= 1
        ));
        usort($lowPerformingProducts, static fn(array $a, array $b): int => ($a['quantitySold'] <=> $b['quantitySold']) ?: ($a['totalRevenue'] <=> $b['totalRevenue']));
        $lowPerformingProducts = array_slice($lowPerformingProducts, 0, 5);

        $bestSellingProducts = [
            'allRankedProducts'     => $allProductRankings,
            'topFive'               => $topFiveProducts,
            'topFiveProducts'       => $topFiveProducts,
            'lowSellingProducts'    => $lowPerformingProducts,
            'lowPerformingProducts' => $lowPerformingProducts,
        ];

        // 6. Order Status Distribution
        $statusDefs = [
            ['status' => 'Pending', 'statusKey' => 'Pending', 'label' => 'Pending', 'sqlStatusLabel' => 'Pending · Timber Selection', 'color' => '#D97706'],
            ['status' => 'Processing', 'statusKey' => 'Processing', 'label' => 'Processing', 'sqlStatusLabel' => 'InProduction · Workshop Joinery', 'color' => '#0095FF'],
            ['status' => 'Shipped', 'statusKey' => 'Shipped', 'label' => 'Shipped', 'sqlStatusLabel' => 'Ready · Dispatched / Transit', 'color' => '#6366F1'],
            ['status' => 'Completed', 'statusKey' => 'Completed', 'label' => 'Completed', 'sqlStatusLabel' => 'Delivered · Completed', 'color' => '#059669'],
            ['status' => 'Cancelled', 'statusKey' => 'Cancelled', 'label' => 'Cancelled', 'sqlStatusLabel' => 'Cancelled', 'color' => '#DC2626'],
        ];
        $statusDistribution = [];
        foreach ($statusDefs as $def) {
            $isComp = $def['statusKey'] === 'Completed';
            $cnt = $isComp ? $currentMetrics['totalOrders'] : 0;
            $amt = $isComp ? $currentMetrics['totalRevenue'] : 0;
            $share = ($isComp && $currentMetrics['totalOrders'] > 0) ? 100.0 : 0.0;
            $statusDistribution[] = array_merge($def, [
                'count'        => $cnt,
                'totalAmount'  => $amt,
                'totalValue'   => $amt,
                'sharePercent' => $share,
                'percentage'   => $share,
            ]);
        }

        $orderAnalytics = [
            'totalOrders'        => $currentMetrics['totalOrders'],
            'distribution'       => $statusDistribution,
            'statusDistribution' => $statusDistribution,
        ];

        // 7. Customer Analytics
        $customerOrderStats = [];
        foreach ($customers as $c) {
            if (!is_array($c)) {
                continue;
            }
            $email = strtolower(trim((string) ($c['email'] ?? '')));
            if ($email === '') {
                continue;
            }
            $customerOrderStats[$email] = [
                'customerId'     => (string) ($c['customerId'] ?? $c['id'] ?? 'LR-CUS-300'),
                'name'           => (string) ($c['fullName'] ?? $c['name'] ?? 'Client'),
                'email'          => (string) $c['email'],
                'company'        => (string) ($c['company'] ?? 'Private Residence'),
                'registeredAt'   => self::parseManilaDateOnly($c['registeredAt'] ?? $c['createdAt'] ?? null) ?? '2026-09-01',
                'periodOrders'   => 0,
                'periodSpent'    => 0,
                'lifetimeOrders' => 0,
                'lifetimeSpent'  => 0,
            ];
        }

        foreach ($completedOrders as $ord) {
            $email = strtolower(trim((string) ($ord['clientEmail'] ?? '')));
            if ($email === '') {
                continue;
            }
            if (!isset($customerOrderStats[$email])) {
                $customerOrderStats[$email] = [
                    'customerId'     => 'LR-CUS-' . (300 + (abs(crc32($email)) % 699)),
                    'name'           => (string) ($ord['clientName'] ?? 'Showroom Client'),
                    'email'          => (string) $ord['clientEmail'],
                    'company'        => (string) ($ord['clientCompany'] ?? 'Private Residence'),
                    'registeredAt'   => self::parseManilaDateOnly($ord['orderDate'] ?? null) ?? '2026-09-01',
                    'periodOrders'   => 0,
                    'periodSpent'    => 0,
                    'lifetimeOrders' => 0,
                    'lifetimeSpent'  => 0,
                ];
            }
            $amt = self::getHistoricalOrderAmount($ord);
            $customerOrderStats[$email]['lifetimeOrders']++;
            $customerOrderStats[$email]['lifetimeSpent'] += $amt;
            $compDate = self::getAnalyticsOrderDate($ord);
            if ($compDate !== null && $compDate >= $range['startDate'] && $compDate <= $range['endDate']) {
                $customerOrderStats[$email]['periodOrders']++;
                $customerOrderStats[$email]['periodSpent'] += $amt;
            }
        }

        $allCustomerStats = array_values($customerOrderStats);
        $orderingCustomers = array_values(array_filter($allCustomerStats, static fn(array $c): bool => $c['periodOrders'] > 0));
        $returningCustomers = array_values(array_filter($allCustomerStats, static fn(array $c): bool => $c['periodOrders'] > 0 && $c['lifetimeOrders'] > 1));
        $lifetimeReturning = array_values(array_filter($allCustomerStats, static fn(array $c): bool => $c['lifetimeOrders'] > 1));

        $topCustomers = $allCustomerStats;
        usort($topCustomers, static fn(array $a, array $b): int => ($b['periodSpent'] <=> $a['periodSpent']) ?: ($b['lifetimeSpent'] <=> $a['lifetimeSpent']));
        foreach ($topCustomers as $idx => &$tc) {
            $tc['rank'] = $idx + 1;
            $tc['isReturning'] = $tc['lifetimeOrders'] > 1;
        }
        unset($tc);

        $monthlyRegistrationTrend = [];
        for ($m = 1; $m <= 12; $m++) {
            $mStart = sprintf('%04d-%02d-01', $range['selectedYear'], $m);
            $mEnd = sprintf('%04d-%02d-%02d', $range['selectedYear'], $m, self::getDaysInMonth($range['selectedYear'], $m));
            $regCount = count(array_filter($allCustomerStats, static fn(array $c): bool => $c['registeredAt'] >= $mStart && $c['registeredAt'] <= $mEnd));
            $activeEmails = [];
            foreach ($completedOrders as $o) {
                $cd = self::getAnalyticsOrderDate($o);
                if ($cd !== null && $cd >= $mStart && $cd <= $mEnd) {
                    $em = strtolower(trim((string) ($o['clientEmail'] ?? '')));
                    if ($em !== '') {
                        $activeEmails[$em] = true;
                    }
                }
            }
            $monthlyRegistrationTrend[] = [
                'month'            => $m,
                'monthIndex'       => $m,
                'monthName'        => self::MONTH_NAMES_FULL[$m - 1],
                'label'            => self::MONTH_NAMES_SHORT[$m - 1],
                'shortName'        => self::MONTH_NAMES_SHORT[$m - 1],
                'newRegistrations' => $regCount,
                'activeBuyers'     => count($activeEmails),
            ];
        }

        $customerAnalytics = [
            'newCustomersCount'              => $currentMetrics['newCustomers'],
            'newCustomersList'               => $currentMetrics['newCustomersList'],
            'customersWhoPlacedOrdersCount'  => count($orderingCustomers),
            'orderingCustomersCount'         => count($orderingCustomers),
            'returningCustomersCount'        => count($returningCustomers),
            'totalLifetimeReturningCustomers'=> count($lifetimeReturning),
            'totalRegisteredCustomers'       => count($allCustomerStats),
            'highestPurchaseCustomers'       => $topCustomers,
            'topCustomers'                   => $topCustomers,
            'monthlyRegistrationTrends'      => $monthlyRegistrationTrend,
            'monthlyRegistrationTrend'       => $monthlyRegistrationTrend,
        ];

        // 8. Inventory Analytics
        $totalStockAvailable = 0;
        $lowStockProducts = [];
        $outOfStockProducts = [];
        $currentProductCatalogValue = 0;
        $availableProductsCount = 0;

        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $st = max(0, (int) ($p['inStockCount'] ?? $p['stockQuantity'] ?? 0));
            $minSt = max(0, (int) ($p['minStockLevel'] ?? 2));
            $price = max(0.0, (float) ($p['effectivePrice'] ?? $p['price'] ?? 0));
            $totalStockAvailable += $st;
            $currentProductCatalogValue += (int) round($st * $price);
            if ($st > 0) {
                $availableProductsCount++;
            }
            $normP = array_merge($p, [
                'productId' => (string) ($p['productId'] ?? $p['sku'] ?? 'LR-ITEM'),
                'stock'     => $st,
                'minStock'  => $minSt,
            ]);
            if ($st === 0) {
                $outOfStockProducts[] = $normP;
            } elseif ($st <= $minSt) {
                $lowStockProducts[] = $normP;
            }
        }

        $materialInventoryValue = 0;
        $materialsRequiringRestock = [];
        foreach ($materials as $m) {
            if (!is_array($m)) {
                continue;
            }
            $qty = max(0, (int) ($m['stockQuantity'] ?? 0));
            $minM = max(0, (int) ($m['minStockLevel'] ?? 10));
            $uCost = max(0.0, (float) ($m['unitCost'] ?? 0));
            $materialInventoryValue += (int) round($qty * $uCost);
            if ($qty <= $minM) {
                $materialsRequiringRestock[] = array_merge($m, [
                    'materialId'   => (string) ($m['materialId'] ?? $m['code'] ?? 'MAT-01'),
                    'category'     => (string) ($m['materialType'] ?? 'Solid Timber'),
                    'quantity'     => $qty,
                    'minimumStock' => $minM,
                    'unit'         => (string) ($m['unit'] ?? 'units'),
                    'status'       => $qty === 0 ? 'Out of Stock' : 'Low Stock',
                ]);
            }
        }

        $periodInvTransactions = array_values(array_filter(
            $inventoryTransactions,
            static fn($tx): bool => is_array($tx) && $isWithinRange($tx['dateTime'] ?? $tx['date'] ?? null, $range['startDate'], $range['endDate'])
        ));

        $consumedByMaterial = [];
        foreach ($periodInvTransactions as $tx) {
            if (($tx['transactionType'] ?? '') !== 'Stock Out') {
                continue;
            }
            $mId = (string) ($tx['materialId'] ?? $tx['materialCode'] ?? $tx['materialName'] ?? '');
            if (!isset($consumedByMaterial[$mId])) {
                $consumedByMaterial[$mId] = [
                    'materialId'        => $tx['materialId'] ?? $mId,
                    'materialCode'      => (string) ($tx['materialCode'] ?? 'MAT-01'),
                    'code'              => (string) ($tx['materialCode'] ?? 'MAT-01'),
                    'materialName'      => (string) ($tx['materialName'] ?? 'Material'),
                    'name'              => (string) ($tx['materialName'] ?? 'Material'),
                    'materialType'      => (string) ($tx['materialType'] ?? 'Solid Timber'),
                    'unit'              => (string) ($tx['unit'] ?? 'units'),
                    'quantityConsumed'  => 0,
                    'unitsConsumed'     => 0,
                    'unitCost'          => (float) ($tx['unitCost'] ?? 0),
                    'estimatedCost'     => 0,
                    'totalCost'         => 0,
                    'transactionCount'  => 0,
                    'transactionsCount' => 0,
                    'lastReason'        => (string) ($tx['reason'] ?? 'Workshop Production'),
                ];
            }
            $qty = max(0, (int) ($tx['quantity'] ?? 0));
            $consumedByMaterial[$mId]['quantityConsumed'] += $qty;
            $consumedByMaterial[$mId]['unitsConsumed'] += $qty;
            $consumedByMaterial[$mId]['estimatedCost'] += (int) round($qty * $consumedByMaterial[$mId]['unitCost']);
            $consumedByMaterial[$mId]['totalCost'] = $consumedByMaterial[$mId]['estimatedCost'];
            $consumedByMaterial[$mId]['transactionCount']++;
            $consumedByMaterial[$mId]['transactionsCount'] = $consumedByMaterial[$mId]['transactionCount'];
            if (!empty($tx['reason'])) {
                $consumedByMaterial[$mId]['lastReason'] = (string) $tx['reason'];
            }
        }

        foreach ($operationalOrders as $ord) {
            if (!$isWithinRange($ord['orderDate'] ?? null, $range['startDate'], $range['endDate'])) {
                continue;
            }
            if (preg_match('/cancel/i', (string) (($ord['status'] ?? '') . ' ' . ($ord['sqlStatus'] ?? '')))) {
                continue;
            }
            $ordNum = (string) ($ord['orderNumber'] ?? $ord['orderId'] ?? '');
            $alreadyLogged = false;
            foreach ($periodInvTransactions as $tx) {
                if (($tx['transactionType'] ?? '') === 'Stock Out' && str_contains((string) ($tx['reason'] ?? ''), $ordNum)) {
                    $alreadyLogged = true;
                    break;
                }
            }
            if ($alreadyLogged) {
                continue;
            }
            $mId = (string) ($ord['materialId'] ?? '');
            $qtyUsed = max(0, (int) ($ord['materialQtyUsed'] ?? 0));
            if ($mId === '' || $qtyUsed <= 0) {
                continue;
            }
            $matObj = null;
            foreach ($materials as $m) {
                if (is_array($m) && ($m['id'] ?? '') === $mId) {
                    $matObj = $m;
                    break;
                }
            }
            $mCode = (string) ($matObj['materialId'] ?? $matObj['code'] ?? 'MAT-01');
            $mName = (string) ($ord['materialName'] ?? $matObj['name'] ?? 'Workshop Material');
            if (!isset($consumedByMaterial[$mId])) {
                $consumedByMaterial[$mId] = [
                    'materialId'        => $mId,
                    'materialCode'      => $mCode,
                    'code'              => $mCode,
                    'materialName'      => $mName,
                    'name'              => $mName,
                    'materialType'      => (string) ($matObj['materialType'] ?? 'Solid Timber'),
                    'unit'              => (string) ($ord['materialUnit'] ?? $matObj['unit'] ?? 'units'),
                    'quantityConsumed'  => 0,
                    'unitsConsumed'     => 0,
                    'unitCost'          => (float) ($matObj['unitCost'] ?? 0),
                    'estimatedCost'     => 0,
                    'totalCost'         => 0,
                    'transactionCount'  => 0,
                    'transactionsCount' => 0,
                    'lastReason'        => 'Allocated to Order #' . $ordNum,
                ];
            }
            $consumedByMaterial[$mId]['quantityConsumed'] += $qtyUsed;
            $consumedByMaterial[$mId]['unitsConsumed'] += $qtyUsed;
            $consumedByMaterial[$mId]['estimatedCost'] += (int) round($qtyUsed * $consumedByMaterial[$mId]['unitCost']);
            $consumedByMaterial[$mId]['totalCost'] = $consumedByMaterial[$mId]['estimatedCost'];
            $consumedByMaterial[$mId]['transactionCount']++;
            $consumedByMaterial[$mId]['transactionsCount'] = $consumedByMaterial[$mId]['transactionCount'];
            $consumedByMaterial[$mId]['lastReason'] = 'Allocated to Order #' . $ordNum;
        }

        $materialsConsumedInPeriod = array_values($consumedByMaterial);
        usort($materialsConsumedInPeriod, static fn(array $a, array $b): int => ($b['estimatedCost'] <=> $a['estimatedCost']) ?: ($b['quantityConsumed'] <=> $a['quantityConsumed']));

        $totalMaterialUnitsConsumed = array_sum(array_column($materialsConsumedInPeriod, 'quantityConsumed'));
        $totalMaterialCostConsumed = array_sum(array_column($materialsConsumedInPeriod, 'estimatedCost'));

        $stockInTxs = array_values(array_filter($periodInvTransactions, static fn(array $tx): bool => ($tx['transactionType'] ?? '') === 'Stock In'));
        $totalUnitsRestocked = 0;
        $totalRestockSpend = 0;
        foreach ($stockInTxs as $tx) {
            $totalUnitsRestocked += max(0, (int) ($tx['quantity'] ?? 0));
            $totalRestockSpend += max(0, (int) round((float) ($tx['purchaseCost'] ?? 0)));
        }

        $inventoryAnalytics = [
            'currentSnapshot' => [
                'totalAvailableProducts'            => $availableProductsCount,
                'totalCatalogProducts'              => count($products),
                'totalAvailableUnits'               => $totalStockAvailable,
                'totalProductUnitsInStock'          => $totalStockAvailable,
                'lowStockProductsCount'             => count($lowStockProducts),
                'lowStockProducts'                  => $lowStockProducts,
                'outOfStockProductsCount'           => count($outOfStockProducts),
                'outOfStockProducts'                => $outOfStockProducts,
                'totalMaterials'                    => count($materials),
                'materialsRequiringRestockingCount' => count($materialsRequiringRestock),
                'materialsRequiringRestockCount'    => count($materialsRequiringRestock),
                'materialsRequiringRestocking'      => $materialsRequiringRestock,
                'materialsRequiringRestock'         => $materialsRequiringRestock,
                'materialInventoryValue'            => $materialInventoryValue,
                'currentMaterialInventoryValue'     => $materialInventoryValue,
                'catalogInventoryValue'             => $currentProductCatalogValue,
                'currentProductCatalogValue'        => $currentProductCatalogValue,
                'currentInventoryValue'             => $materialInventoryValue + $currentProductCatalogValue,
            ],
            'periodMovements' => [
                'periodLabel'                => $range['periodLabel'],
                'totalMovementsCount'        => count($periodInvTransactions),
                'materialsConsumedInPeriod'  => $materialsConsumedInPeriod,
                'totalUnitsConsumed'         => $totalMaterialUnitsConsumed,
                'totalMaterialUnitsConsumed' => $totalMaterialUnitsConsumed,
                'totalConsumedValue'         => $totalMaterialCostConsumed,
                'totalMaterialCostConsumed'  => $totalMaterialCostConsumed,
                'stockInCount'               => count($stockInTxs),
                'totalUnitsRestocked'        => $totalUnitsRestocked,
                'totalMaterialUnitsRestocked'=> $totalUnitsRestocked,
                'totalRestockedCost'         => $totalRestockSpend,
                'totalRestockSpend'          => $totalRestockSpend,
                'periodTransactions'         => $periodInvTransactions,
            ],
        ];

        // 9. Voucher Analytics
        $normalizedVouchers = [];
        $activeVouchers = 0;
        $expiringSoonVouchers = 0;
        $expiredVouchers = 0;
        $revokedVouchers = 0;
        $totalDiscountIssued = 0;

        foreach ($vouchers as $v) {
            if (!is_array($v)) {
                continue;
            }
            $discAmt = max(0, (int) round((float) ($v['discountAmount'] ?? $v['discountValue'] ?? 0)));
            $totalDiscountIssued += $discAmt;
            $rawStatus = trim((string) ($v['status'] ?? 'Active'));
            $expStr = (string) ($v['expiresAt'] ?? $v['expiryDate'] ?? '');
            $expMs = null;
            if ($expStr !== '') {
                $ts = strtotime(preg_match('/^\d{4}-\d{2}-\d{2}$/', $expStr) ? $expStr . 'T23:59:59+08:00' : $expStr);
                if ($ts !== false) {
                    $expMs = $ts * 1000;
                }
            }
            $effStatus = $rawStatus;
            if ($rawStatus === 'Active' || $rawStatus === 'Expiring Soon') {
                if ($expMs !== null && $expMs <= $nowMs) {
                    $effStatus = 'Expired';
                } elseif ($expMs !== null && ($expMs - $nowMs) <= 86400 * 1000) {
                    $effStatus = 'Expiring Soon';
                } else {
                    $effStatus = 'Active';
                }
            }
            if ($effStatus === 'Active') {
                $activeVouchers++;
            } elseif ($effStatus === 'Expiring Soon') {
                $activeVouchers++;
                $expiringSoonVouchers++;
            } elseif ($effStatus === 'Expired') {
                $expiredVouchers++;
            } elseif ($effStatus === 'Revoked' || $effStatus === 'Cancelled') {
                $revokedVouchers++;
            }
            $issuedDate = self::parseManilaDateOnly($v['issuedAt'] ?? $v['createdAt'] ?? null);
            $expiryDate = self::parseManilaDateOnly($v['expiresAt'] ?? $v['expiryDate'] ?? null);
            $normalizedVouchers[] = array_merge($v, [
                'voucherId'       => (string) ($v['voucherId'] ?? ('VCH-LR-' . (100 + (abs(crc32((string) ($v['code'] ?? ''))) % 899)))),
                'productName'     => (string) ($v['furnitureName'] ?? $v['productName'] ?? 'Showroom Piece'),
                'discountAmount'  => $discAmt,
                'effectiveStatus' => $effStatus,
                'status'          => $effStatus,
                'issuedAt'        => (string) ($v['createdAt'] ?? $issuedDate ?? '—'),
                'expiresAt'       => (string) ($v['expiryDate'] ?? $expiryDate ?? 'No expiry'),
                'issuedDate'      => $issuedDate,
                'expiryDate'      => $expiryDate,
            ]);
        }

        $vouchersInPeriod = array_values(array_filter(
            $normalizedVouchers,
            static fn(array $v): bool => $isWithinRange($v['issuedDate'], $range['startDate'], $range['endDate'])
        ));

        $completedVoucherCodes = [];
        foreach ($completedOrders as $ord) {
            $vc = strtoupper(trim((string) ($ord['appliedVoucherCode'] ?? '')));
            if ($vc !== '') {
                $completedVoucherCodes[$vc] = true;
            }
        }
        $periodVoucherCodes = [];
        foreach ($currentMetrics['periodOrders'] as $ord) {
            $vc = strtoupper(trim((string) ($ord['appliedVoucherCode'] ?? '')));
            if ($vc !== '') {
                $periodVoucherCodes[$vc] = true;
            }
        }

        $redeemedVouchers = count($completedVoucherCodes);
        $redeemedInPeriodCount = count($periodVoucherCodes);
        $effectiveDiscountRedeemed = 0;
        foreach ($completedOrders as $ord) {
            $effectiveDiscountRedeemed += max(0, (int) round((float) ($ord['voucherDiscountAmount'] ?? 0)));
        }
        $totalVouchersIssued = count($normalizedVouchers);
        $redemptionRate = $totalVouchersIssued > 0 ? round(($redeemedVouchers / $totalVouchersIssued) * 100, 1) : 0.0;
        $periodRedemptionRate = count($vouchersInPeriod) > 0 ? round(($redeemedInPeriodCount / count($vouchersInPeriod)) * 100, 1) : 0.0;

        $voucherAnalytics = [
            'totalVouchersIssued'          => $totalVouchersIssued,
            'totalIssuedInPeriod'          => count($vouchersInPeriod),
            'vouchersIssuedInSelectedPeriod'=> count($vouchersInPeriod),
            'activeVouchers'               => $activeVouchers,
            'expiringSoonVouchers'         => $expiringSoonVouchers,
            'redeemedVouchers'             => $redeemedVouchers,
            'redeemedInPeriodCount'        => $redeemedInPeriodCount,
            'expiredVouchers'              => $expiredVouchers,
            'revokedVouchers'              => $revokedVouchers,
            'totalDiscountIssued'          => $totalDiscountIssued,
            'totalDiscountRedeemed'        => $effectiveDiscountRedeemed,
            'periodDiscountRedeemed'       => $currentMetrics['totalDiscountsGiven'],
            'redemptionRate'               => $redemptionRate,
            'periodRedemptionRate'         => $periodRedemptionRate,
            'voucherBreakdown'             => $normalizedVouchers,
            'vouchers'                     => $normalizedVouchers,
        ];

        // 10. Transactions ledger
        $transactions = [];
        foreach ($currentMetrics['periodOrders'] as $ord) {
            $compDate = self::getAnalyticsOrderDate($ord);
            $histAmt = self::getHistoricalOrderAmount($ord);
            $disc = max(0, (int) round((float) ($ord['voucherDiscountAmount'] ?? 0)));
            $transactions[] = [
                'orderId'               => (string) ($ord['orderId'] ?? $ord['orderNumber'] ?? ''),
                'orderNumber'           => (string) ($ord['orderNumber'] ?? $ord['orderId'] ?? ''),
                'orderDate'             => (string) ($ord['orderDate'] ?? ''),
                'completedAt'           => $compDate,
                'reportDate'            => $compDate,
                'clientName'            => (string) ($ord['clientName'] ?? ''),
                'clientEmail'           => (string) ($ord['clientEmail'] ?? ''),
                'furnitureName'         => (string) ($ord['furnitureName'] ?? ''),
                'productId'             => (string) ($ord['sku'] ?? $ord['furnitureId'] ?? ''),
                'sku'                   => (string) ($ord['sku'] ?? $ord['furnitureId'] ?? ''),
                'quantity'              => max(1, (int) ($ord['quantity'] ?? 1)),
                'totalAmount'           => $histAmt,
                'depositPaid'           => max(0, (int) round((float) ($ord['depositPaid'] ?? 0))),
                'discountAmount'        => $disc,
                'voucherDiscountAmount' => $disc,
                'appliedVoucherCode'    => $ord['appliedVoucherCode'] ?? null,
                'normalizedStatus'      => 'Completed',
                'statusBucket'          => 'Completed',
                'stageLabel'            => (string) ($ord['status'] ?? 'Delivered · Completed'),
                'paymentStatus'         => (string) ($ord['paymentStatus'] ?? 'Paid in Full'),
                'realizedRevenue'       => $histAmt,
            ];
        }

        return [
            'recognitionBasis'  => 'completed_orders',
            'businessName'      => 'Lillia and Rowena Furniture',
            'generatedAtIso'    => gmdate('Y-m-d\TH:i:s\Z', (int) floor($nowMs / 1000)),
            'generatedAtManila' => self::formatManilaTimestamp($nowMs),
            'dateRange'         => $range,
            'period'            => $range['current'],
            'previousPeriod'    => $range['previous'],
            'summary'           => $summary,
            'summaryCards'      => $summaryCards,
            'kpis'              => [
                'totalRevenue'        => $currentMetrics['totalRevenue'],
                'totalOrders'         => $currentMetrics['totalOrders'],
                'completedOrders'     => $currentMetrics['completedOrders'],
                'pendingOrders'       => 0,
                'cancelledOrders'     => 0,
                'totalProductsSold'   => $currentMetrics['totalProductsSold'],
                'newCustomers'        => $currentMetrics['newCustomers'],
                'totalDiscountsGiven' => $currentMetrics['totalDiscountsGiven'],
                'averageOrderValue'   => $currentMetrics['averageOrderValue'],
            ],
            'salesAnalytics'      => $salesAnalytics,
            'bestSellingProducts' => $bestSellingProducts,
            'productAnalytics'    => $bestSellingProducts,
            'orderAnalytics'      => $orderAnalytics,
            'customerAnalytics'   => $customerAnalytics,
            'inventoryAnalytics'  => $inventoryAnalytics,
            'voucherAnalytics'    => $voucherAnalytics,
            'transactions'        => $transactions,
            'periodOrders'        => $transactions,
        ];
    }

    /**
     * Generates the full CSV content for an analytics report.
     *
     * @param array<string, mixed> $report
     */
    public static function exportCsv(array $report): string
    {
        $esc = static function (mixed $val): string {
            $str = preg_replace('/\r?\n/', ' ', (string) ($val ?? '')) ?? '';
            if (str_contains($str, ',') || str_contains($str, '"')) {
                return '"' . str_replace('"', '""', $str) . '"';
            }
            return $str;
        };

        $lines = [];
        $lines[] = 'Business Name,' . $esc($report['businessName'] ?? 'Lillia and Rowena Furniture');
        $lines[] = 'Report Period,' . $esc($report['dateRange']['periodLabel'] ?? $report['period']['label'] ?? '');
        $lines[] = 'Date Range,' . $esc(($report['dateRange']['startDate'] ?? '') . ' to ' . ($report['dateRange']['endDate'] ?? ''));
        $lines[] = 'Generated Date (PHT),' . $esc($report['generatedAtManila'] ?? '');
        $lines[] = '';
        $lines[] = 'MONTHLY BUSINESS SUMMARY KPIs';
        $lines[] = 'Metric,Selected Period Value,Previous Period Value,Change (%)';
        foreach (($report['summaryCards'] ?? []) as $card) {
            $lines[] = implode(',', [
                $esc($card['label'] ?? ''),
                $esc($card['formattedValue'] ?? ''),
                $esc($card['formattedPreviousValue'] ?? ''),
                $esc($card['comparison']['formatted'] ?? $card['formattedChange'] ?? '0.0%'),
            ]);
        }
        $lines[] = '';
        $lines[] = 'BEST-SELLING PRODUCTS';
        $lines[] = 'Rank,Product ID,Product Name,Category,Quantity Sold,Total Revenue ($),Stock Availability';
        foreach (($report['bestSellingProducts']['allRankedProducts'] ?? []) as $p) {
            $lines[] = implode(',', [
                $esc($p['rank'] ?? ''),
                $esc($p['productId'] ?? ''),
                $esc($p['name'] ?? ''),
                $esc($p['categoryName'] ?? ''),
                $esc($p['quantitySold'] ?? 0),
                $esc('$' . ($p['totalRevenue'] ?? 0)),
                $esc(($p['currentStock'] ?? 0) . ' (' . ($p['stockStatusLabel'] ?? 'In Stock') . ')'),
            ]);
        }
        $lines[] = '';
        $lines[] = 'VOUCHER ANALYTICS SUMMARY';
        $lines[] = 'Total Issued,Active,Redeemed,Expired,Revoked,Total Discount Redeemed ($),Redemption Rate (%)';
        $va = $report['voucherAnalytics'] ?? [];
        $lines[] = implode(',', [
            $esc($va['totalVouchersIssued'] ?? 0),
            $esc($va['activeVouchers'] ?? 0),
            $esc($va['redeemedVouchers'] ?? 0),
            $esc($va['expiredVouchers'] ?? 0),
            $esc($va['revokedVouchers'] ?? 0),
            $esc('$' . ($va['totalDiscountRedeemed'] ?? 0)),
            $esc(($va['redemptionRate'] ?? 0) . '%'),
        ]);
        $lines[] = '';
        $lines[] = 'TRANSACTION DETAILS';
        $lines[] = 'Order ID,Completion Date,Customer,Customer Email,Product,Product ID,Qty,Order Total ($),Discount ($),Status';
        foreach (($report['transactions'] ?? []) as $ord) {
            $lines[] = implode(',', [
                $esc($ord['orderId'] ?? ''),
                $esc($ord['reportDate'] ?? $ord['completedAt'] ?? $ord['orderDate'] ?? ''),
                $esc($ord['clientName'] ?? ''),
                $esc($ord['clientEmail'] ?? ''),
                $esc($ord['furnitureName'] ?? ''),
                $esc($ord['sku'] ?? ''),
                $esc($ord['quantity'] ?? 1),
                $esc('$' . ($ord['totalAmount'] ?? 0)),
                $esc('$' . ($ord['voucherDiscountAmount'] ?? 0)),
                $esc($ord['statusBucket'] ?? 'Completed'),
            ]);
        }

        return implode("\r\n", $lines);
    }

    /**
     * Generates a valid PDF-1.4 document binary for an analytics report.
     *
     * @param array<string, mixed> $report
     */
    public static function exportPdfBytes(array $report): string
    {
        $safeAscii = static function (mixed $str): string {
            $s = (string) ($str ?? '');
            $s = str_replace(['₱', '—', '–', '×'], ['$', '-', '-', 'x'], $s);
            $s = preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
            return preg_replace('/([()\\\\])/', '\\\\$1', $s) ?? '';
        };

        $lines = [];
        $lines[] = 'LILLIA AND ROWENA FURNITURE - MONTHLY ANALYTICS & BUSINESS REPORT';
        $lines[] = sprintf(
            'Reporting Period: %s (%s to %s)',
            $report['dateRange']['periodLabel'] ?? $report['period']['label'] ?? 'Current Period',
            $report['dateRange']['startDate'] ?? '',
            $report['dateRange']['endDate'] ?? ''
        );
        $lines[] = 'Generated On: ' . ($report['generatedAtManila'] ?? '') . ' (Asia/Manila)';
        $lines[] = '--------------------------------------------------------------------------------';
        $lines[] = '1. EXECUTIVE BUSINESS SUMMARY';
        foreach (($report['summaryCards'] ?? []) as $card) {
            $lines[] = sprintf(
                '   - %s: %s (Prior: %s | %s)',
                $card['label'] ?? '',
                $card['formattedValue'] ?? '',
                $card['formattedPreviousValue'] ?? '',
                $card['comparison']['formatted'] ?? $card['formattedChange'] ?? '0.0%'
            );
        }
        $lines[] = '--------------------------------------------------------------------------------';
        $lines[] = '2. TOP 5 BEST-SELLING PRODUCTS';
        foreach (($report['bestSellingProducts']['topFive'] ?? []) as $p) {
            $lines[] = sprintf(
                '   #%d [%s] %s (%s) - Sold: %d pcs | Revenue: $%s | Stock: %d',
                (int) ($p['rank'] ?? 1),
                $p['productId'] ?? '',
                $p['name'] ?? '',
                $p['categoryName'] ?? '',
                (int) ($p['quantitySold'] ?? 0),
                number_format((int) ($p['totalRevenue'] ?? 0)),
                (int) ($p['currentStock'] ?? 0)
            );
        }
        $lines[] = '--------------------------------------------------------------------------------';
        $lines[] = '3. ORDER STATUS DISTRIBUTION';
        foreach (($report['orderAnalytics']['distribution'] ?? []) as $s) {
            $lines[] = sprintf(
                '   - %s: %d order(s) (%s%%) - Total: $%s',
                $s['label'] ?? '',
                (int) ($s['count'] ?? 0),
                (string) ($s['sharePercent'] ?? 0),
                number_format((int) ($s['totalAmount'] ?? 0))
            );
        }
        $lines[] = '--------------------------------------------------------------------------------';
        $va = $report['voucherAnalytics'] ?? [];
        $lines[] = sprintf(
            '4. VOUCHER ANALYTICS: Issued: %d | Active: %d | Redeemed: %d | Expired: %d | Revoked: %d',
            (int) ($va['totalVouchersIssued'] ?? 0),
            (int) ($va['activeVouchers'] ?? 0),
            (int) ($va['redeemedVouchers'] ?? 0),
            (int) ($va['expiredVouchers'] ?? 0),
            (int) ($va['revokedVouchers'] ?? 0)
        );
        $lines[] = sprintf(
            '   Total Discount Redeemed: $%s | Redemption Rate: %s%%',
            number_format((int) ($va['totalDiscountRedeemed'] ?? 0)),
            (string) ($va['redemptionRate'] ?? 0)
        );
        $lines[] = '--------------------------------------------------------------------------------';
        $inv = $report['inventoryAnalytics']['currentSnapshot'] ?? [];
        $mov = $report['inventoryAnalytics']['periodMovements'] ?? [];
        $lines[] = sprintf(
            '5. INVENTORY ANALYTICS: Available Products: %d | Low Stock: %d | Needs Restock: %d',
            (int) ($inv['totalAvailableProducts'] ?? 0),
            (int) ($inv['lowStockProductsCount'] ?? 0),
            (int) ($inv['materialsRequiringRestockingCount'] ?? 0)
        );
        $lines[] = sprintf(
            '   Current Combined Inventory Value: $%s | Period Material Consumed Cost: $%s',
            number_format((int) ($inv['currentInventoryValue'] ?? 0)),
            number_format((int) ($mov['totalMaterialCostConsumed'] ?? 0))
        );
        $lines[] = '--------------------------------------------------------------------------------';
        $txList = $report['transactions'] ?? [];
        $lines[] = sprintf('6. PERIOD TRANSACTIONS (%d record(s))', count($txList));
        foreach (array_slice($txList, 0, 12) as $ord) {
            $lines[] = sprintf(
                '   %s | %s | %s | %s (x%d) | $%s | %s',
                $ord['orderId'] ?? '',
                $ord['orderDate'] ?? '',
                $ord['clientName'] ?? '',
                $ord['sku'] ?? '',
                (int) ($ord['quantity'] ?? 1),
                number_format((int) ($ord['totalAmount'] ?? 0)),
                $ord['statusBucket'] ?? 'Completed'
            );
        }

        $textOps = ['BT', '/F1 9 Tf', '40 755 Td', '13 TL'];
        foreach ($lines as $idx => $line) {
            $textOps[] = ($idx === 0 ? '' : 'T* ') . '(' . $safeAscii($line) . ') Tj';
        }
        $textOps[] = 'ET';
        $streamBody = implode("\n", $textOps);

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            sprintf("5 0 obj\n<< /Length %d >>\nstream\n%s\nendstream\nendobj\n", strlen($streamBody), $streamBody),
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj;
        }
        $xrefStart = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);
        for ($i = 1, $n = count($offsets); $i < $n; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF", count($objects) + 1, $xrefStart);

        return $pdf;
    }
}
