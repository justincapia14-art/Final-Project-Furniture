<?php

declare(strict_types=1);

namespace App\Models;

use Library\Database;
use Library\OrderCompletion;
use Library\Exceptions\ValidationException;

/**
 * Order, OrderItem, Payment, Voucher, and Offer Model
 *
 * Encapsulates SQL queries and PHP domain rules for customer orders,
 * custom furniture workshop material allocations, installment/full payments,
 * single-customer/single-product promotional vouchers, and Messenger price offers.
 */
class Order
{
    public function __construct(private ?Database $db = null)
    {
    }

    /**
     * Retrieve all orders from the relational database or hydrated store state,
     * enriched with order line items, material allocations, and payment records.
     *
     * @param array<string, mixed>|null $fallbackState
     * @return array<int, array<string, mixed>>
     */
    public function getAllOrders(?array $fallbackState = null): array
    {
        if ($this->db !== null) {
            $state = $this->db->getHydratedStoreState();
            if (is_array($state['orders'] ?? null)) {
                return $state['orders'];
            }
        }

        if (is_array($fallbackState['orders'] ?? null)) {
            return $fallbackState['orders'];
        }

        return [];
    }

    /**
     * Find a specific order by its non-incrementing OrderID (e.g. ORD-2026-4819).
     *
     * @param string $orderId
     * @param array<int, array<string, mixed>> $orders
     * @return array<string, mixed>|null
     */
    public static function findById(string $orderId, array $orders): ?array
    {
        $needle = trim($orderId);
        if ($needle === '') {
            return null;
        }

        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $currentId = (string) ($order['OrderID'] ?? $order['id'] ?? '');
            if ($currentId === $needle) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Filter orders belonging to a specific customer (by CustomerID or email).
     *
     * @param array<int, array<string, mixed>> $orders
     * @param string $customerId
     * @param string $customerEmail
     * @return array<int, array<string, mixed>>
     */
    public static function filterByCustomer(array $orders, string $customerId = '', string $customerEmail = ''): array
    {
        $normId = trim($customerId);
        $normEmail = strtolower(trim($customerEmail));

        $matched = [];
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $ordCustId = trim((string) ($order['CustomerID'] ?? $order['customerId'] ?? ''));
            $ordEmail = strtolower(trim((string) ($order['customerEmail'] ?? $order['email'] ?? '')));

            $idMatches = ($normId !== '' && $ordCustId !== '' && $ordCustId === $normId);
            $emailMatches = ($normEmail !== '' && $ordEmail !== '' && $ordEmail === $normEmail);

            if ($idMatches || $emailMatches) {
                $matched[] = $order;
            }
        }

        return $matched;
    }

    /**
     * Filter completed customer transactions suitable for official receipt printing
     * and completed transaction history tables.
     *
     * @param array<int, array<string, mixed>> $orders
     * @param string $customerId
     * @param string $customerEmail
     * @return array<int, array<string, mixed>>
     */
    public static function getCompletedTransactionsForCustomer(
        array $orders,
        string $customerId = '',
        string $customerEmail = ''
    ): array {
        $customerOrders = ($customerId === '' && $customerEmail === '')
            ? $orders
            : self::filterByCustomer($orders, $customerId, $customerEmail);

        $completed = [];
        foreach ($customerOrders as $order) {
            if (OrderCompletion::isOrderCompleted($order)) {
                $completed[] = $order;
            }
        }

        usort($completed, static function (array $a, array $b): int {
            $dateA = OrderCompletion::reportDate($a) ?? (string) ($a['completedAt'] ?? $a['orderDate'] ?? '');
            $dateB = OrderCompletion::reportDate($b) ?? (string) ($b['completedAt'] ?? $b['orderDate'] ?? '');
            return strcmp($dateB, $dateA);
        });

        return $completed;
    }

    /**
     * Compute comprehensive financial breakdown for a single order,
     * preserving historical prices, applied voucher discounts, deposits paid,
     * and remaining balance due.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public static function computeOrderFinancialSummary(array $order): array
    {
        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        $computedLineSubtotal = 0.0;
        $totalUnits = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = max(1, (int) ($item['quantity'] ?? $item['Quantity'] ?? 1));
            $unitPrice = (float) ($item['unitPrice'] ?? $item['UnitPrice'] ?? $item['price'] ?? 0);
            $lineSubtotal = isset($item['subtotal']) || isset($item['Subtotal'])
                ? (float) ($item['subtotal'] ?? $item['Subtotal'])
                : round($unitPrice * $qty, 2);

            $computedLineSubtotal += $lineSubtotal;
            $totalUnits += $qty;
        }

        $subtotalAmount = isset($order['subtotalAmount']) && is_numeric($order['subtotalAmount']) && (float) $order['subtotalAmount'] > 0
            ? (float) $order['subtotalAmount']
            : ($computedLineSubtotal > 0 ? $computedLineSubtotal : (float) ($order['totalAmount'] ?? $order['TotalAmount'] ?? 0));

        $discountAmount = max(0.0, (float) ($order['discountAmount'] ?? $order['DiscountAmount'] ?? $order['voucherDiscount'] ?? 0));
        $netTotalAmount = isset($order['totalAmount']) || isset($order['TotalAmount'])
            ? max(0.0, (float) ($order['totalAmount'] ?? $order['TotalAmount']))
            : max(0.0, round($subtotalAmount - $discountAmount, 2));

        $amountPaid = max(0.0, (float) ($order['amountPaid'] ?? $order['AmountPaid'] ?? 0));
        $paymentStatus = (string) ($order['paymentStatus'] ?? $order['PaymentStatus'] ?? 'Pending');
        if (strtolower($paymentStatus) === 'fully paid' && $amountPaid < $netTotalAmount) {
            $amountPaid = $netTotalAmount;
        }

        $balanceDue = isset($order['balanceDue']) && is_numeric($order['balanceDue'])
            ? max(0.0, (float) $order['balanceDue'])
            : max(0.0, round($netTotalAmount - $amountPaid, 2));

        $paymentProgressPct = $netTotalAmount > 0
            ? min(100.0, round(($amountPaid / $netTotalAmount) * 100, 1))
            : 100.0;

        return [
            'orderId' => (string) ($order['OrderID'] ?? $order['id'] ?? ''),
            'itemCount' => count($items),
            'totalUnits' => max(1, $totalUnits),
            'subtotalAmount' => round($subtotalAmount, 2),
            'discountAmount' => round($discountAmount, 2),
            'totalAmount' => round($netTotalAmount, 2),
            'amountPaid' => round($amountPaid, 2),
            'balanceDue' => round($balanceDue, 2),
            'paymentProgressPct' => $paymentProgressPct,
            'paymentStatus' => $balanceDue <= 0.009 ? 'Fully Paid' : ($amountPaid > 0 ? 'Partial / Deposit Paid' : $paymentStatus),
            'isCompleted' => OrderCompletion::isOrderCompleted($order),
            'isCancelled' => OrderCompletion::isCancelled($order),
            'voucherCode' => (string) ($order['voucherCode'] ?? $order['appliedVoucherCode'] ?? ''),
        ];
    }

    /**
     * Validate and build a new Custom or Showroom Order record with explicit
     * non-incrementing OrderID (`ORD-YYYY-XXXX`), normalized line items,
     * and workshop raw material allocations.
     *
     * @param array<string, mixed> $payload
     * @param array<int, array<string, mixed>> $existingOrders
     * @param array<int, array<string, mixed>> $catalogProducts
     * @param array<int, array<string, mixed>> $inventoryMaterials
     * @return array<string, mixed>
     */
    public static function buildOrderRecord(
        array $payload,
        array $existingOrders = [],
        array $catalogProducts = [],
        array $inventoryMaterials = []
    ): array {
        $customerName = trim((string) ($payload['customerName'] ?? ''));
        $customerEmail = strtolower(trim((string) ($payload['customerEmail'] ?? '')));

        if ($customerName === '') {
            throw new ValidationException('Customer full name is required to create an order.');
        }
        if ($customerEmail === '' || !str_contains($customerEmail, '@')) {
            throw new ValidationException('A valid customer email address is required.');
        }

        $requestedOrderId = trim((string) ($payload['OrderID'] ?? $payload['id'] ?? ''));
        $genOrder = StoreService::generateOwnEntityId('order', $requestedOrderId, '', $existingOrders);
        $orderId = (string) ($genOrder['codeId'] ?? 'ORD-2026-1001');

        $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $normalizedItems = [];
        $subtotal = 0.0;

        foreach ($rawItems as $idx => $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }
            $productId = trim((string) ($rawItem['ProductID'] ?? $rawItem['productId'] ?? 'LR-CUSTOM-001'));
            $productName = trim((string) ($rawItem['name'] ?? $rawItem['productName'] ?? 'Custom Workshop Piece'));
            $qty = max(1, (int) ($rawItem['quantity'] ?? $rawItem['Quantity'] ?? 1));
            $unitPrice = max(0.0, (float) ($rawItem['unitPrice'] ?? $rawItem['UnitPrice'] ?? $rawItem['price'] ?? 0));
            $selectedColor = trim((string) ($rawItem['selectedColor'] ?? $rawItem['colorName'] ?? 'Natural Finish'));
            $lineSubtotal = round($unitPrice * $qty, 2);
            $subtotal += $lineSubtotal;

            $normalizedItems[] = [
                'OrderItemID' => sprintf('%s-ITM-%02d', $orderId, $idx + 1),
                'OrderID' => $orderId,
                'ProductID' => $productId,
                'productId' => $productId,
                'name' => $productName,
                'productName' => $productName,
                'selectedColor' => $selectedColor,
                'quantity' => $qty,
                'Quantity' => $qty,
                'unitPrice' => $unitPrice,
                'UnitPrice' => $unitPrice,
                'subtotal' => $lineSubtotal,
                'Subtotal' => $lineSubtotal,
                'customizationNotes' => trim((string) ($rawItem['customizationNotes'] ?? '')),
            ];
        }

        if (empty($normalizedItems)) {
            $fallbackProductName = trim((string) ($payload['productName'] ?? 'Custom Solid Timber Furniture'));
            $fallbackPrice = max(0.0, (float) ($payload['totalAmount'] ?? $payload['subtotalAmount'] ?? 0));
            $subtotal = $fallbackPrice;
            $normalizedItems[] = [
                'OrderItemID' => $orderId . '-ITM-01',
                'OrderID' => $orderId,
                'ProductID' => (string) ($payload['productId'] ?? 'LR-CUSTOM-001'),
                'productId' => (string) ($payload['productId'] ?? 'LR-CUSTOM-001'),
                'name' => $fallbackProductName,
                'productName' => $fallbackProductName,
                'selectedColor' => trim((string) ($payload['selectedColor'] ?? 'Natural Solid Wood')),
                'quantity' => 1,
                'Quantity' => 1,
                'unitPrice' => $fallbackPrice,
                'UnitPrice' => $fallbackPrice,
                'subtotal' => $fallbackPrice,
                'Subtotal' => $fallbackPrice,
                'customizationNotes' => trim((string) ($payload['customizationNotes'] ?? '')),
            ];
        }

        $discountAmount = max(0.0, (float) ($payload['discountAmount'] ?? $payload['voucherDiscount'] ?? 0));
        $totalAmount = isset($payload['totalAmount']) && is_numeric($payload['totalAmount'])
            ? max(0.0, (float) $payload['totalAmount'])
            : max(0.0, round($subtotal - $discountAmount, 2));

        $amountPaid = max(0.0, (float) ($payload['amountPaid'] ?? 0));
        $balanceDue = max(0.0, round($totalAmount - $amountPaid, 2));
        $status = trim((string) ($payload['status'] ?? $payload['OrderStatus'] ?? 'Pending Confirmation'));
        $paymentStatus = $balanceDue <= 0.009 && $totalAmount > 0
            ? 'Fully Paid'
            : ($amountPaid > 0 ? 'Partial / Deposit Paid' : (string) ($payload['paymentStatus'] ?? 'Pending'));

        $nowIso = gmdate('Y-m-d\TH:i:s\Z');
        $orderDate = trim((string) ($payload['orderDate'] ?? substr($nowIso, 0, 10)));

        $allocations = [];
        $rawAllocations = is_array($payload['materialAllocations'] ?? null) ? $payload['materialAllocations'] : [];
        foreach ($rawAllocations as $aIdx => $alloc) {
            if (!is_array($alloc)) {
                continue;
            }
            $matId = trim((string) ($alloc['MaterialID'] ?? $alloc['materialId'] ?? ''));
            $qtyAllocated = max(0.0, (float) ($alloc['quantityAllocated'] ?? $alloc['QuantityAllocated'] ?? $alloc['quantity'] ?? 0));
            if ($matId === '' || $qtyAllocated <= 0) {
                continue;
            }
            $allocations[] = [
                'AllocationID' => sprintf('%s-MAT-%02d', $orderId, $aIdx + 1),
                'OrderID' => $orderId,
                'MaterialID' => $matId,
                'materialId' => $matId,
                'materialName' => trim((string) ($alloc['materialName'] ?? $alloc['name'] ?? $matId)),
                'quantityAllocated' => $qtyAllocated,
                'QuantityAllocated' => $qtyAllocated,
                'unit' => trim((string) ($alloc['unit'] ?? 'pcs')),
            ];
        }

        return [
            'id' => $orderId,
            'OrderID' => $orderId,
            'CustomerID' => trim((string) ($payload['CustomerID'] ?? $payload['customerId'] ?? 'LR-CUS-001')),
            'customerId' => trim((string) ($payload['CustomerID'] ?? $payload['customerId'] ?? 'LR-CUS-001')),
            'customerName' => $customerName,
            'customerEmail' => $customerEmail,
            'customerPhone' => trim((string) ($payload['customerPhone'] ?? '')),
            'deliveryAddress' => trim((string) ($payload['deliveryAddress'] ?? '')),
            'orderType' => trim((string) ($payload['orderType'] ?? 'Showroom Order')),
            'status' => $status,
            'OrderStatus' => $status,
            'productionStage' => trim((string) ($payload['productionStage'] ?? 'Order Received')),
            'progress' => max(0, min(100, (int) ($payload['progress'] ?? 10))),
            'subtotalAmount' => round($subtotal, 2),
            'discountAmount' => round($discountAmount, 2),
            'totalAmount' => round($totalAmount, 2),
            'TotalAmount' => round($totalAmount, 2),
            'amountPaid' => round($amountPaid, 2),
            'balanceDue' => round($balanceDue, 2),
            'paymentStatus' => $paymentStatus,
            'PaymentStatus' => $paymentStatus,
            'paymentMethod' => trim((string) ($payload['paymentMethod'] ?? 'Bank Transfer / GCash')),
            'voucherCode' => strtoupper(trim((string) ($payload['voucherCode'] ?? ''))),
            'orderDate' => $orderDate,
            'estimatedCompletion' => trim((string) ($payload['estimatedCompletion'] ?? '')),
            'completedAt' => OrderCompletion::isCompleted(['status' => $status, 'sqlStatus' => $status]) ? ($payload['completedAt'] ?? $nowIso) : null,
            'items' => $normalizedItems,
            'materialAllocations' => $allocations,
            'payments' => is_array($payload['payments'] ?? null) ? $payload['payments'] : [],
        ];
    }

    /**
     * Queue a customer cancellation request or process an Admin approval/decline
     * of a pending cancellation request.
     *
     * @param array<int, array<string, mixed>> $orders
     * @param string $orderId
     * @param string $action 'request' | 'approve' | 'decline'
     * @param string $reason
     * @return array{orders: array<int, array<string, mixed>>, updatedOrder: array<string, mixed>|null}
     */
    public static function handleOrderCancellation(
        array $orders,
        string $orderId,
        string $action,
        string $reason = ''
    ): array {
        $needle = trim($orderId);
        $updatedOrder = null;
        $nowIso = gmdate('Y-m-d\TH:i:s\Z');

        foreach ($orders as $idx => $order) {
            if (!is_array($order)) {
                continue;
            }
            $currId = (string) ($order['OrderID'] ?? $order['id'] ?? '');
            if ($currId !== $needle) {
                continue;
            }

            if ($action === 'request') {
                $order['cancellationRequested'] = true;
                $order['cancellationStatus'] = 'Pending Admin Approval';
                $order['cancellationReason'] = trim($reason) !== '' ? trim($reason) : 'Customer requested order cancellation';
                $order['cancellationRequestedAt'] = $nowIso;
            } elseif ($action === 'approve') {
                $order['cancellationRequested'] = false;
                $order['cancellationStatus'] = 'Approved';
                $order['status'] = 'Cancelled';
                $order['OrderStatus'] = 'Cancelled';
                $order['cancelledAt'] = $nowIso;
            } elseif ($action === 'decline') {
                $order['cancellationRequested'] = false;
                $order['cancellationStatus'] = 'Declined';
                $order['cancellationAdminNote'] = trim($reason) !== '' ? trim($reason) : 'Order is already in active workshop production';
            }

            $orders[$idx] = $order;
            $updatedOrder = $order;
            break;
        }

        return [
            'orders' => $orders,
            'updatedOrder' => $updatedOrder,
        ];
    }

    /**
     * Validate and build a Messenger Make-an-Offer proposal record without mutating
     * the catalog base price of the furniture item.
     *
     * @param array<string, mixed> $payload
     * @param array<int, array<string, mixed>> $catalogProducts
     * @param array<int, array<string, mixed>> $existingOffers
     * @return array<string, mixed>
     */
    public static function buildOfferProposal(
        array $payload,
        array $catalogProducts = [],
        array $existingOffers = []
    ): array {
        $productId = trim((string) ($payload['productId'] ?? $payload['ProductID'] ?? ''));
        $offeredPrice = (float) ($payload['offeredPrice'] ?? $payload['offerAmount'] ?? 0);
        $quantity = max(1, (int) ($payload['quantity'] ?? 1));
        $customerName = trim((string) ($payload['customerName'] ?? 'Showroom Guest'));
        $selectedColor = trim((string) ($payload['selectedColor'] ?? 'Natural Finish'));

        if ($productId === '') {
            throw new ValidationException('Product ID is required to submit an offer proposal.');
        }
        if ($offeredPrice <= 0) {
            throw new ValidationException('Offered price must be greater than zero.');
        }

        $matchedProduct = null;
        foreach ($catalogProducts as $product) {
            if (!is_array($product)) {
                continue;
            }
            $pid = (string) ($product['ProductID'] ?? $product['id'] ?? '');
            if ($pid === $productId) {
                $matchedProduct = $product;
                break;
            }
        }

        $productName = $matchedProduct !== null
            ? (string) ($matchedProduct['name'] ?? $matchedProduct['ProductName'] ?? 'Showroom Piece')
            : trim((string) ($payload['productName'] ?? 'Showroom Piece'));

        $pricing = $matchedProduct !== null
            ? StoreService::computeVariantPricing($matchedProduct, $selectedColor)
            : ['finalPrice' => (float) ($payload['listedPrice'] ?? $offeredPrice)];

        $listedUnitPrice = (float) ($pricing['finalPrice'] ?? $offeredPrice);
        $genOffer = StoreService::generateOwnEntityId('offer', '', '', $existingOffers);
        $offerId = (string) ($genOffer['codeId'] ?? 'OFR-LR-1001');

        $discountPct = $listedUnitPrice > 0
            ? round((($listedUnitPrice - $offeredPrice) / $listedUnitPrice) * 100, 1)
            : 0.0;

        return [
            'id' => $offerId,
            'OfferID' => $offerId,
            'ProductID' => $productId,
            'productId' => $productId,
            'productName' => $productName,
            'selectedColor' => $selectedColor,
            'quantity' => $quantity,
            'listedUnitPrice' => round($listedUnitPrice, 2),
            'offeredUnitPrice' => round($offeredPrice, 2),
            'totalOfferedAmount' => round($offeredPrice * $quantity, 2),
            'requestedDiscountPercent' => $discountPct,
            'customerName' => $customerName,
            'customerEmail' => strtolower(trim((string) ($payload['customerEmail'] ?? ''))),
            'customerNotes' => trim((string) ($payload['notes'] ?? $payload['message'] ?? '')),
            'status' => 'Submitted via Messenger',
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Format an Official Showroom Receipt HTML document for a completed order.
     *
     * @param array<string, mixed> $order
     * @return string
     */
    public static function renderOfficialReceiptHtml(array $order): string
    {
        $summary = self::computeOrderFinancialSummary($order);
        $orderId = htmlspecialchars($summary['orderId'], ENT_QUOTES, 'UTF-8');
        $customerName = htmlspecialchars((string) ($order['customerName'] ?? 'Valued Client'), ENT_QUOTES, 'UTF-8');
        $customerEmail = htmlspecialchars((string) ($order['customerEmail'] ?? ''), ENT_QUOTES, 'UTF-8');
        $orderDate = htmlspecialchars((string) ($order['orderDate'] ?? ''), ENT_QUOTES, 'UTF-8');
        $completedDate = htmlspecialchars((string) (OrderCompletion::reportDate($order) ?? $orderDate), ENT_QUOTES, 'UTF-8');

        $rowsHtml = '';
        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = htmlspecialchars((string) ($item['name'] ?? $item['productName'] ?? 'Furniture Item'), ENT_QUOTES, 'UTF-8');
            $color = htmlspecialchars((string) ($item['selectedColor'] ?? 'Standard Finish'), ENT_QUOTES, 'UTF-8');
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $unitPrice = number_format((float) ($item['unitPrice'] ?? $item['price'] ?? 0), 2);
            $lineTotal = number_format((float) ($item['subtotal'] ?? ($qty * (float) ($item['unitPrice'] ?? 0))), 2);

            $rowsHtml .= "<tr>
                <td class=\"py-2 px-3 border-b border-slate-200 text-xs font-semibold text-slate-900\">{$name} <span class=\"text-slate-500 font-normal\">({$color})</span></td>
                <td class=\"py-2 px-3 border-b border-slate-200 text-xs text-center text-slate-700\">{$qty}</td>
                <td class=\"py-2 px-3 border-b border-slate-200 text-xs text-right text-slate-700\">₱{$unitPrice}</td>
                <td class=\"py-2 px-3 border-b border-slate-200 text-xs text-right font-semibold text-slate-900\">₱{$lineTotal}</td>
            </tr>";
        }

        $subtotalFmt = number_format($summary['subtotalAmount'], 2);
        $discountFmt = number_format($summary['discountAmount'], 2);
        $totalFmt = number_format($summary['totalAmount'], 2);
        $paidFmt = number_format($summary['amountPaid'], 2);

        return "<div class=\"receipt-card bg-white border border-slate-200 rounded-2xl p-6 shadow-xs\" data-receipt-order-id=\"{$orderId}\">
            <div class=\"flex items-center justify-between border-b border-slate-200 pb-4 mb-4\">
                <div>
                    <h3 class=\"text-base font-bold text-slate-900\">Lillia &amp; Rowena Furniture Atelier</h3>
                    <p class=\"text-xs text-slate-500\">Official Completed Transaction Receipt · {$orderId}</p>
                </div>
                <span class=\"px-2.5 py-1 rounded-md bg-emerald-50 text-emerald-700 text-xs font-bold\">Completed ({$completedDate})</span>
            </div>
            <div class=\"grid grid-cols-2 gap-4 text-xs mb-4\">
                <div><span class=\"text-slate-500\">Client:</span> <strong class=\"text-slate-900\">{$customerName}</strong> ({$customerEmail})</div>
                <div class=\"text-right\"><span class=\"text-slate-500\">Order Date:</span> <strong class=\"text-slate-900\">{$orderDate}</strong></div>
            </div>
            <table class=\"w-full border-collapse mb-4\">
                <thead>
                    <tr class=\"bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500\">
                        <th class=\"py-2 px-3 text-left\">Item &amp; Finish</th>
                        <th class=\"py-2 px-3 text-center\">Qty</th>
                        <th class=\"py-2 px-3 text-right\">Unit Price</th>
                        <th class=\"py-2 px-3 text-right\">Subtotal</th>
                    </tr>
                </thead>
                <tbody>{$rowsHtml}</tbody>
            </table>
            <div class=\"flex flex-col items-end gap-1 text-xs\">
                <div>Subtotal: <strong>₱{$subtotalFmt}</strong></div>
                <div>Voucher Discount: <strong class=\"text-emerald-700\">-₱{$discountFmt}</strong></div>
                <div class=\"text-sm font-bold text-slate-900\">Total Paid: ₱{$paidFmt} (Net: ₱{$totalFmt})</div>
            </div>
        </div>";
    }
}
