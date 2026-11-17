<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use DateTimeZone;
use Library\OrderCompletion;

/**
 * Authoritative PHP Store & Workshop Domain Service.
 *
 * Implements all product, variant pricing, catalog filtering, custom commission
 * calculation, order lifecycle, customer payment, material inventory stock movement,
 * customer directory, and promotional voucher logic in PHP 8.
 */
final class StoreService
{
    public const ADMIN_MESSENGER_URL = 'https://www.messenger.com/t/100000505712325';

    public const CATEGORY_ID_PREFIX_MAP = [
        'living'  => 'LV',
        'dining'  => 'DN',
        'bedroom' => 'BD',
        'office'  => 'ST',
        'kitchen' => 'KT',
    ];

    public const CATEGORY_NAME_MAP = [
        'living'  => 'Living Room',
        'dining'  => 'Dining Room',
        'bedroom' => 'Bedroom',
        'office'  => 'Study & Storage',
        'kitchen' => 'Kitchen',
    ];

    public const CATALOG_COLOR_FILTERS = [
        ['key' => 'walnut', 'label' => 'dark walnut', 'hex' => '#5C3A21', 'keywords' => ['walnut']],
        ['key' => 'white-oak', 'label' => 'white oak', 'hex' => '#CBB28D', 'keywords' => ['white oak', 'natural oak']],
        ['key' => 'smoked-oak', 'label' => 'smoked oak', 'hex' => '#9C8265', 'keywords' => ['smoked', 'fumed', 'chestnut']],
        ['key' => 'ebonized', 'label' => 'ebonized black', 'hex' => '#222428', 'keywords' => ['ebonized', 'black']],
        ['key' => 'boucle', 'label' => 'warm oat bouclé', 'hex' => '#E6DEC8', 'keywords' => ['bouclé', 'boucle']],
        ['key' => 'linen', 'label' => 'beige linen', 'hex' => '#DDD3C2', 'keywords' => ['linen']],
        ['key' => 'ash', 'label' => 'natural ash', 'hex' => '#D9C7AE', 'keywords' => ['ash']],
        ['key' => 'travertine', 'label' => 'travertine stone', 'hex' => '#D8CABB', 'keywords' => ['travertine', 'limestone']],
        ['key' => 'cognac', 'label' => 'cognac leather', 'hex' => '#8A4B29', 'keywords' => ['cognac', 'leather']],
        ['key' => 'indigo', 'label' => 'midnight indigo', 'hex' => '#243B55', 'keywords' => ['indigo', 'mohair']],
    ];

    /**
     * Generates an explicit non-incrementing entity ID (e.g. LR-LV-213, ORD-2026-4821, MAT-WAL-42, VCH-LR-301, LR-CUS-412).
     *
     * @return array{codeId: string, slugId: string}
     */
    public static function generateOwnEntityId(string $entityType = 'product', string $hint = '', string $categoryId = 'living'): array
    {
        $rand3 = random_int(100, 999);
        $rand4 = random_int(1000, 9999);
        $type = strtolower(trim($entityType));

        if ($type === 'product') {
            $catKey = strtolower(trim($categoryId));
            $catPrefix = self::CATEGORY_ID_PREFIX_MAP[$catKey] ?? 'FUR';
            $code = sprintf('LR-%s-%d', $catPrefix, $rand3);
            return ['codeId' => $code, 'slugId' => 'furn-' . strtolower($code)];
        }
        if ($type === 'material') {
            $cleanHint = strtoupper(preg_replace('/[^A-Za-z]/', '', $hint) ?? '');
            $sub = substr($cleanHint !== '' ? $cleanHint : 'MAT', 0, 3);
            $code = sprintf('MAT-%s-%d', str_pad($sub, 3, 'X'), random_int(10, 99));
            return ['codeId' => $code, 'slugId' => 'mat-' . strtolower($code)];
        }
        if ($type === 'order') {
            $code = sprintf('ORD-2026-%d', $rand4);
            return ['codeId' => $code, 'slugId' => 'ord-' . $rand4];
        }
        if ($type === 'voucher') {
            $code = sprintf('VCH-LR-%d', $rand3);
            return ['codeId' => $code, 'slugId' => 'vch-' . strtolower($code)];
        }
        if ($type === 'customer') {
            $code = sprintf('LR-CUS-%d', $rand3);
            return ['codeId' => $code, 'slugId' => 'cust-' . strtolower($code)];
        }
        if ($type === 'category') {
            $cleanCat = strtoupper(preg_replace('/[^A-Za-z]/', '', $hint) ?? '');
            $sub = substr($cleanCat !== '' ? $cleanCat : 'CAT', 0, 2);
            $code = sprintf('CAT-%s-%d', str_pad($sub, 2, 'X'), random_int(10, 99));
            return ['codeId' => $code, 'slugId' => 'cat-' . strtolower($code)];
        }

        $fallback = sprintf('LR-ID-%d', $rand4);
        return ['codeId' => $fallback, 'slugId' => strtolower($fallback)];
    }

    /**
     * Parses raw color variant definitions into structured variant arrays.
     *
     * @return array<int, array{name: string, hex: string, priceDelta: int}>
     */
    public static function parseColorVariantsInput(mixed $rawInput, string $defaultFinish = 'Hand-Rubbed Natural Walnut'): array
    {
        if (is_array($rawInput) && count($rawInput) > 0) {
            $result = [];
            foreach ($rawInput as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $result[] = [
                    'name'       => trim((string) ($v['name'] ?? $defaultFinish)),
                    'hex'        => trim((string) ($v['hex'] ?? '#5C3A21')),
                    'priceDelta' => (int) ($v['priceDelta'] ?? 0),
                ];
            }
            if (count($result) > 0) {
                return $result;
            }
        }

        $str = trim((string) ($rawInput ?? ''));
        if ($str === '') {
            return [
                ['name' => $defaultFinish, 'hex' => '#5C3A21', 'priceDelta' => 0],
                ['name' => 'Smoked White Oak', 'hex' => '#9C8265', 'priceDelta' => 180],
                ['name' => 'Ebonized Ash', 'hex' => '#222428', 'priceDelta' => 260],
            ];
        }

        $parts = array_filter(array_map('trim', explode(',', $str)));
        $parsed = [];
        foreach ($parts as $idx => $entry) {
            $segs = array_map('trim', explode('|', $entry));
            $cName = $segs[0] ?? '';
            $hex = $segs[1] ?? '';
            $delta = isset($segs[2]) && is_numeric($segs[2]) ? (int) $segs[2] : $idx * 150;
            $parsed[] = [
                'name'       => $cName !== '' ? $cName : 'Finish Option ' . ($idx + 1),
                'hex'        => str_starts_with($hex, '#') ? $hex : ['#5C3A21', '#9C8265', '#222428'][$idx % 3],
                'priceDelta' => $delta,
            ];
        }
        return $parsed;
    }

    /**
     * Computes variant pricing for a product and selected finish.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function computeVariantPricing(array $item, ?string $selectedColorName = null): array
    {
        $basePrice = max(0, (int) round((float) ($item['price'] ?? 0)));
        $discountPercent = max(0.0, min(80.0, (float) ($item['discountPercent'] ?? 0)));
        $variants = is_array($item['colorVariants'] ?? null) ? $item['colorVariants'] : [];

        $matched = null;
        if ($selectedColorName !== null && $selectedColorName !== '') {
            foreach ($variants as $v) {
                if (is_array($v) && ($v['name'] ?? '') === $selectedColorName) {
                    $matched = $v;
                    break;
                }
            }
        }
        if ($matched === null && isset($variants[0]) && is_array($variants[0])) {
            $matched = $variants[0];
        }
        if ($matched === null) {
            $matched = [
                'name'       => (string) ($selectedColorName ?: ($item['defaultFinish'] ?? 'Standard Finish')),
                'hex'        => '#5C3A21',
                'priceDelta' => 0,
            ];
        }

        $priceDelta = (int) round((float) ($matched['priceDelta'] ?? 0));
        $variantBasePrice = max(50, $basePrice + $priceDelta);
        $effectivePrice = (int) round($variantBasePrice * (1.0 - $discountPercent / 100.0));

        return [
            'colorName'        => (string) ($matched['name'] ?? 'Standard Finish'),
            'colorHex'         => (string) ($matched['hex'] ?? '#5C3A21'),
            'priceDelta'       => $priceDelta,
            'variantBasePrice' => $variantBasePrice,
            'effectivePrice'   => $effectivePrice,
            'finalPrice'       => $effectivePrice,
            'discountSavings'  => $variantBasePrice - $effectivePrice,
        ];
    }

    /**
     * Builds a normalized product record with own code IDs and color variants.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existingProduct
     * @return array<string, mixed>
     */
    public static function buildProductRecord(array $input, ?array $existingProduct = null): array
    {
        $existing = $existingProduct ?? [];
        $categoryId = (string) ($input['categoryId'] ?? $existing['categoryId'] ?? 'dining');
        $price = max(50, (int) round((float) ($input['price'] ?? $existing['price'] ?? 1500)));
        $discountPercent = min(75, max(0, (int) round((float) ($input['discountPercent'] ?? $existing['discountPercent'] ?? 0))));
        $inStockCount = max(0, (int) floor((float) ($input['inStockCount'] ?? $existing['inStockCount'] ?? 4)));
        $minStockLevel = max(0, (int) floor((float) ($input['minStockLevel'] ?? $existing['minStockLevel'] ?? 2)));

        $rawType = strtolower(trim((string) ($input['productType'] ?? $existing['productType'] ?? 'standard')));
        if ($rawType === '' && !empty($input['isCustomClientDesign'] ?? $existing['isCustomClientDesign'] ?? false)) {
            $rawType = 'customized';
        }
        $productType = $rawType === 'customized' ? 'customized' : 'standard';

        $colorVariants = self::parseColorVariantsInput(
            $input['colorsRaw'] ?? $input['colorVariants'] ?? $existing['colorVariants'] ?? null,
            (string) ($input['defaultFinish'] ?? $existing['defaultFinish'] ?? 'Hand-Rubbed Natural Walnut')
        );
        $defaultVariant = $colorVariants[0] ?? ['name' => 'Hand-Rubbed Natural Walnut', 'hex' => '#5C3A21', 'priceDelta' => 0];
        $basePlusDefault = $price + (int) ($defaultVariant['priceDelta'] ?? 0);
        $effectivePrice = (int) round($basePlusDefault * (1.0 - $discountPercent / 100.0));

        $rawSku = strtoupper(trim((string) ($input['sku'] ?? $input['productId'] ?? $existing['productId'] ?? $existing['sku'] ?? '')));
        $hasExplicitCode = strlen($rawSku) >= 4 && !preg_match('/^\d+$/', $rawSku);
        $resolvedProductId = $hasExplicitCode
            ? $rawSku
            : self::generateOwnEntityId('product', (string) ($input['name'] ?? ''), $categoryId)['codeId'];
        $resolvedSlugId = (string) ($existing['id'] ?? $input['id'] ?? ('furn-' . strtolower($resolvedProductId)));

        return array_merge($existing, [
            'id'                      => $resolvedSlugId,
            'productId'               => $resolvedProductId,
            'sku'                     => $resolvedProductId,
            'name'                    => trim((string) ($input['name'] ?? $existing['name'] ?? 'Handcrafted Piece')),
            'categoryId'              => $categoryId,
            'categoryName'            => self::CATEGORY_NAME_MAP[$categoryId] ?? (string) ($existing['categoryName'] ?? 'Dining Room'),
            'productType'             => $productType,
            'originSource'            => $productType === 'customized' ? 'Messenger Request' : 'Admin Catalog',
            'messengerConversationId' => $productType === 'customized'
                ? ($input['messengerConversationId'] ?? $existing['messengerConversationId'] ?? null)
                : null,
            'messengerCustomerName'   => $productType === 'customized'
                ? ($input['messengerCustomerName'] ?? $existing['messengerCustomerName'] ?? null)
                : null,
            'messengerTopic'          => $productType === 'customized'
                ? ($input['messengerTopic'] ?? $existing['messengerTopic'] ?? null)
                : null,
            'customizationNotes'      => trim((string) ($input['customizationNotes'] ?? $existing['customizationNotes'] ?? '')),
            'publicCatalogVisible'    => true,
            'price'                   => $price,
            'discountPercent'         => $discountPercent,
            'effectivePrice'          => $effectivePrice,
            'inStockCount'            => $inStockCount,
            'minStockLevel'           => $minStockLevel,
            'leadTimeWeeks'           => (int) ($input['leadTimeWeeks'] ?? $existing['leadTimeWeeks'] ?? ($productType === 'customized' ? 5 : 3)),
            'dimensions'              => trim((string) ($input['dimensions'] ?? $existing['dimensions'] ?? '240cm L × 100cm W × 75cm H')),
            'defaultFinish'           => (string) $defaultVariant['name'],
            'finishes'                => array_values(array_map(static fn(array $v): string => (string) $v['name'], $colorVariants)),
            'colorVariants'           => $colorVariants,
            'isCustomClientDesign'    => $productType === 'customized',
            'image'                   => trim((string) ($input['image'] ?? $existing['image'] ?? 'assets/images/category_dining_tables.jpg')),
            'description'             => (string) ($input['description'] ?? $existing['description'] ?? (
                $productType === 'customized'
                    ? 'Customized product created from a customer Messenger request and published to the public catalog.'
                    : 'Handcrafted solid timber commission added via the Admin Management Console.'
            )),
            'craftsmanshipNote'       => (string) ($input['craftsmanshipNote'] ?? $existing['craftsmanshipNote'] ?? 'Constructed to order in our workshop with kiln-dried FAS-grade timber.'),
        ]);
    }

    /**
     * Filters catalog items for the public storefront or catalog page.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public static function filterCatalogItems(array $items, array $options = []): array
    {
        $categoryId = (string) ($options['categoryId'] ?? 'all');
        $colorKey = (string) ($options['colorKey'] ?? 'all');
        $maxPrice = isset($options['maxPrice']) && is_numeric($options['maxPrice']) ? (float) $options['maxPrice'] : INF;
        $searchQuery = strtolower(trim((string) ($options['searchQuery'] ?? '')));

        return array_values(array_filter($items, static function (array $item) use ($categoryId, $colorKey, $maxPrice, $searchQuery): bool {
            if ($categoryId !== '' && $categoryId !== 'all' && $categoryId !== 'recommended') {
                if (($item['categoryId'] ?? '') !== $categoryId) {
                    return false;
                }
            }

            $effectiveCheckPrice = (float) ($item['effectivePrice'] ?? $item['price'] ?? 0);
            if ($colorKey !== '' && $colorKey !== 'all') {
                $matchedVar = self::findMatchingColorVariant($item, $colorKey);
                if ($matchedVar === null) {
                    return false;
                }
                $effectiveCheckPrice = (float) self::computeVariantPricing($item, (string) $matchedVar['name'])['effectivePrice'];
            }

            if ($effectiveCheckPrice > $maxPrice) {
                return false;
            }

            if ($searchQuery !== '') {
                $finishes = is_array($item['finishes'] ?? null) ? implode(' ', $item['finishes']) : '';
                $hay = strtolower(implode(' ', [
                    (string) ($item['name'] ?? ''),
                    (string) ($item['sku'] ?? ''),
                    (string) ($item['categoryName'] ?? ''),
                    (string) ($item['description'] ?? ''),
                    (string) ($item['defaultFinish'] ?? ''),
                    $finishes,
                ]));
                if (!str_contains($hay, $searchQuery)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Finds a matching color variant on a product for a sidebar color filter key.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    public static function findMatchingColorVariant(array $item, string $colorKey): ?array
    {
        if ($colorKey === '' || $colorKey === 'all') {
            return null;
        }
        $keywords = [strtolower($colorKey)];
        foreach (self::CATALOG_COLOR_FILTERS as $def) {
            if ($def['key'] === $colorKey) {
                $keywords = $def['keywords'];
                break;
            }
        }
        $variants = is_array($item['colorVariants'] ?? null) ? $item['colorVariants'] : [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $nameLower = strtolower((string) ($variant['name'] ?? ''));
            foreach ($keywords as $kw) {
                if (str_contains($nameLower, $kw)) {
                    return $variant;
                }
            }
        }
        return null;
    }

    /**
     * Computes customer directory records enriched with order counts and total spend.
     *
     * @param array<int, array<string, mixed>> $orders
     * @param array<int, array<string, mixed>> $customers
     * @param array<string, mixed>|null $customerProfile
     * @return array<int, array<string, mixed>>
     */
    public static function computeCustomerDirectory(array $orders = [], array $customers = [], ?array $customerProfile = null): array
    {
        if (isset($orders['orders']) || isset($orders['customers']) || isset($orders['catalog'])) {
            $store = $orders;
            $orders = is_array($store['orders'] ?? null) ? $store['orders'] : [];
            $customers = is_array($store['customers'] ?? null) ? $store['customers'] : [];
            $customerProfile = is_array($store['customerProfile'] ?? null) ? $store['customerProfile'] : null;
        }
        $byEmail = [];

        $deriveOwnId = static function (string $emailStr, mixed $explicitId = null): string {
            $cleanExp = strtoupper(trim((string) ($explicitId ?? '')));
            if ($cleanExp !== '' && $cleanExp !== 'CUST-PROFILE' && !preg_match('/^\d+$/', $cleanExp)) {
                return $cleanExp;
            }
            $hash = 0;
            $clean = strtolower(trim($emailStr));
            $len = strlen($clean);
            for ($i = 0; $i < $len; $i++) {
                $hash = ($hash * 31 + ord($clean[$i])) % 699;
            }
            return 'LR-CUS-' . (300 + abs($hash));
        };

        $upsert = static function (array $entry) use (&$byEmail, $deriveOwnId): void {
            $email = strtolower(trim((string) ($entry['email'] ?? '')));
            if ($email === '' || $email === 'admin@lilliarowena.com' || ($entry['role'] ?? '') === 'admin') {
                return;
            }
            $resolvedId = $deriveOwnId($email, $entry['customerId'] ?? $entry['id'] ?? null);
            $existing = $byEmail[$email] ?? [
                'id'            => (!empty($entry['id']) && (string) $entry['id'] !== 'cust-profile') ? (string) $entry['id'] : 'cust-' . $resolvedId,
                'customerId'    => $resolvedId,
                'name'          => (string) ($entry['name'] ?? $entry['fullName'] ?? 'Client'),
                'email'         => trim((string) $entry['email']),
                'phone'         => (string) ($entry['phone'] ?? $entry['phoneNumber'] ?? '+1 (212) 555-0140'),
                'address'       => (string) ($entry['address'] ?? 'Showroom Registered Client'),
                'company'       => (string) ($entry['company'] ?? 'Private Residence'),
                'avatar'        => trim((string) ($entry['avatar'] ?? '')),
                'orderCount'    => 0,
                'totalSpent'    => 0,
                'lastOrderDate' => '—',
            ];
            $byEmail[$email] = array_merge($existing, [
                'customerId' => $existing['customerId'] ?: $resolvedId,
                'name'       => (string) ($entry['name'] ?? $entry['fullName'] ?? $existing['name']),
                'phone'      => (string) ($entry['phone'] ?? $entry['phoneNumber'] ?? $existing['phone']),
                'address'    => (string) ($entry['address'] ?? $existing['address']),
                'company'    => (string) ($entry['company'] ?? $existing['company']),
                'avatar'     => trim((string) ($entry['avatar'] ?? $existing['avatar'] ?? '')),
            ]);
        };

        if (is_array($customerProfile) && !empty($customerProfile['email'])) {
            $profEmail = strtolower(trim((string) $customerProfile['email']));
            if ($profEmail !== 'admin@lilliarowena.com' && ($customerProfile['role'] ?? '') !== 'admin') {
                $addrParts = array_filter([
                    trim((string) ($customerProfile['address'] ?? '')),
                    trim((string) ($customerProfile['city'] ?? '')),
                ]);
                $upsert([
                    'id'      => $customerProfile['customerId'] ?? $customerProfile['id'] ?? null,
                    'name'    => $customerProfile['name'] ?? 'Rowena Vance',
                    'email'   => $customerProfile['email'],
                    'phone'   => $customerProfile['phone'] ?? '',
                    'address' => implode(', ', $addrParts),
                    'company' => 'Vance & Associates Spatial Design',
                    'avatar'  => $customerProfile['avatar'] ?? '',
                ]);
            }
        }

        foreach ($customers as $c) {
            if (is_array($c)) {
                $upsert($c);
            }
        }

        foreach ($orders as $ord) {
            if (!is_array($ord)) {
                continue;
            }
            $email = strtolower(trim((string) ($ord['clientEmail'] ?? '')));
            if ($email === '' || $email === 'admin@lilliarowena.com') {
                continue;
            }
            if (!isset($byEmail[$email])) {
                $custOwnId = $deriveOwnId($email);
                $upsert([
                    'id'         => 'cust-' . $custOwnId,
                    'customerId' => $custOwnId,
                    'name'       => (string) ($ord['clientName'] ?? 'Showroom Client'),
                    'email'      => (string) $ord['clientEmail'],
                    'phone'      => '+1 (212) 555-0192',
                    'address'    => (string) ($ord['deliveryAddress'] ?? 'Private Residence'),
                    'company'    => (string) ($ord['clientCompany'] ?? 'Private Residence'),
                ]);
            }
            $byEmail[$email]['orderCount']++;
            $byEmail[$email]['totalSpent'] += max(0, (int) round((float) ($ord['totalAmount'] ?? 0)));
            $ordDate = (string) ($ord['orderDate'] ?? '');
            if ($ordDate !== '' && ($byEmail[$email]['lastOrderDate'] === '—' || $ordDate > $byEmail[$email]['lastOrderDate'])) {
                $byEmail[$email]['lastOrderDate'] = $ordDate;
            }
            if (!empty($ord['deliveryAddress']) && ($byEmail[$email]['address'] === '' || $byEmail[$email]['address'] === 'Showroom Registered Client')) {
                $byEmail[$email]['address'] = (string) $ord['deliveryAddress'];
            }
        }

        return array_values($byEmail);
    }

    /**
     * Applies an Admin Order stage or delivery update and handles completion recognition.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $updates
     * @return array<string, mixed>
     */
    public static function applyAdminOrderUpdate(array $order, array $updates = []): array
    {
        $next = array_merge($order, $updates);
        $parts = explode('-', (string) ($next['orderNumber'] ?? '9001'));
        $ordSuffix = end($parts) ?: '9001';

        if (!empty($updates['status']) && empty($updates['sqlStatus'])) {
            $st = (string) $updates['status'];
            if (preg_match('/Confirmed|Pending|Timber/i', $st)) {
                $next['sqlStatus'] = 'Pending';
                $next['taskStatus'] = ($next['taskStatus'] ?? '') === 'Done' ? 'InProgress' : ($next['taskStatus'] ?? 'Todo');
                $next['isCompleted'] = false;
            } elseif (preg_match('/Joinery|Finishing|Millwork|Production/i', $st)) {
                $next['sqlStatus'] = 'InProduction';
                $next['taskStatus'] = 'InProgress';
                $next['isCompleted'] = false;
            } elseif (preg_match('/Quality|Ready/i', $st)) {
                $next['sqlStatus'] = 'Ready';
                $next['taskStatus'] = 'Done';
                $next['isCompleted'] = false;
            } elseif (preg_match('/Completed|Delivered/i', $st)) {
                $next['sqlStatus'] = 'Delivered';
                $next['taskStatus'] = 'Done';
                $next['deliveryStatus'] = 'Delivered';
                $next['isCompleted'] = true;
                $next['completedAt'] = $next['completedAt'] ?? gmdate('Y-m-d\TH:i:s\Z');
                $next['transactionReference'] = $next['transactionReference'] ?? ('TXN-2026-' . $ordSuffix);
                $next['depositPaid'] = max((int) ($next['depositPaid'] ?? 0), (int) ($next['totalAmount'] ?? 0));
                $next['paymentStatus'] = 'Paid in Full';
                $next['cancellationRequested'] = false;
            } elseif (preg_match('/Dispatched/i', $st)) {
                $next['sqlStatus'] = 'Ready';
                $next['taskStatus'] = 'Done';
                if (($next['deliveryStatus'] ?? '') === 'Scheduled') {
                    $next['deliveryStatus'] = 'InTransit';
                }
                $next['isCompleted'] = false;
            } elseif (preg_match('/Cancelled/i', $st)) {
                $next['sqlStatus'] = 'Cancelled';
                $next['isCompleted'] = false;
            }
        }

        if (($updates['deliveryStatus'] ?? '') === 'Delivered' || ($updates['isCompleted'] ?? false) === true) {
            $next['sqlStatus'] = 'Delivered';
            $next['taskStatus'] = 'Done';
            $next['deliveryStatus'] = 'Delivered';
            if (!preg_match('/Completed|Delivered/i', (string) ($next['status'] ?? ''))) {
                $next['status'] = 'Delivered · Completed';
            }
            $next['isCompleted'] = true;
            $next['completedAt'] = $next['completedAt'] ?? gmdate('Y-m-d\TH:i:s\Z');
            $next['transactionReference'] = $next['transactionReference'] ?? ('TXN-2026-' . $ordSuffix);
            $next['depositPaid'] = max((int) ($next['depositPaid'] ?? 0), (int) ($next['totalAmount'] ?? 0));
            $next['paymentStatus'] = 'Paid in Full';
            $next['cancellationRequested'] = false;
        } elseif (($updates['deliveryStatus'] ?? '') === 'InTransit' && !preg_match('/Dispatched|Delivered|Completed/i', (string) ($next['status'] ?? ''))) {
            $next['status'] = 'Dispatched · White-Glove Transit';
            $next['sqlStatus'] = 'Ready';
            $next['isCompleted'] = false;
        }

        if (!OrderCompletion::isOrderCompleted($next)) {
            unset($next['completedAt']);
        }

        return $next;
    }

    /**
     * Records a customer payment toward an order's remaining balance.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public static function recordCustomerOrderPayment(array $order, ?float $amountToPay = null): array
    {
        $total = max(0, (int) round((float) ($order['totalAmount'] ?? 0)));
        $currentPaid = max(0, (int) round((float) ($order['depositPaid'] ?? 0)));
        $remaining = max(0, $total - $currentPaid);
        $payment = $amountToPay !== null ? min($remaining, max(0, (int) round($amountToPay))) : $remaining;
        $nextPaid = $currentPaid + $payment;

        return array_merge($order, [
            'depositPaid'     => $nextPaid,
            'paymentStatus'   => $nextPaid >= $total
                ? 'Paid in Full'
                : sprintf('Partial (%d%% Paid)', (int) round(($nextPaid / max(1, $total)) * 100)),
            'lastPaymentDate' => gmdate('Y-m-d'),
        ]);
    }
}
