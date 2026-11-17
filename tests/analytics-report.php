<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\AnalyticsController;
use App\Controllers\DashboardController;
use App\Controllers\StorefrontController;
use App\Models\AnalyticsReport;
use App\Models\Order;
use App\Models\StoreService;
use Library\Database;
use Library\OrderCompletion;

final class ReportFixtureDatabase extends Database
{
    public function __construct(private array $fixture) {}
    public function readStore(): array { return $this->fixture; }
    public function isMySqlConnected(): bool { return false; }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function report(array $orders, int $month = 11): array
{
    return (new AnalyticsReport(new ReportFixtureDatabase(['orders' => $orders])))
        ->generateReport(['preset' => 'select_month', 'year' => 2026, 'month' => $month]);
}

$pending = [
    'orderId' => 'ORDER-1', 'orderDate' => '2026-10-31',
    'status' => 'Order Confirmed', 'sqlStatus' => 'Pending',
    'totalAmount' => 1000, 'depositPaid' => 1000, 'paymentStatus' => 'Paid in Full',
];
$completed = array_merge($pending, [
    'status' => 'Delivered · Completed', 'sqlStatus' => 'Delivered', 'isCompleted' => true,
    'completedAt' => '2026-10-31T16:30:00Z',
]);

check(!OrderCompletion::isCompleted($pending), 'Payment alone must not complete an order');
check(OrderCompletion::reportDate($completed) === '2026-11-01', 'Use Manila completion date');
check(report([$pending])['summary']['totalRevenue']['value'] === 0.0, 'Pending sale leaked into PHP report');
check(report([$completed])['summary']['totalRevenue']['value'] === 1000.0, 'Completed sale missing');
check(report([$completed])['summary']['totalOrders']['value'] === 1, 'Completed order count incorrect');
check(report([$completed, $completed])['summary']['totalOrders']['value'] === 1, 'Duplicate order counted');
check(report([$completed], 10)['summary']['totalRevenue']['value'] === 0.0, 'Completion shifted into order month');

$cancelled = array_merge($completed, ['sqlStatus' => 'Cancelled', 'status' => 'Order Cancelled']);
check(report([$cancelled])['summary']['totalOrders']['value'] === 0, 'Cancelled sale included');
$transit = array_merge($pending, ['sqlStatus' => 'Delivered', 'status' => 'Dispatched · White-Glove Transit']);
check(!OrderCompletion::isCompleted($transit), 'Legacy transit status incorrectly completed');
$legacy = $completed;
unset($legacy['completedAt']);
check(OrderCompletion::reportDate($legacy) === '2026-10-31', 'Legacy order-date fallback missing');
$free = array_merge($completed, ['totalAmount' => 0]);
check(report([$free])['summary']['totalRevenue']['value'] === 0.0, 'Zero total incorrectly recomputed');

// Verify CSV & PDF export generation in PHP
$novReport = report([$completed], 11);
$csv = AnalyticsReport::exportCsv($novReport);
check(str_contains($csv, 'MONTHLY BUSINESS SUMMARY KPIs'), 'CSV export missing KPI header');
check(str_contains($csv, 'ORDER-1'), 'CSV export missing completed order row');
$pdf = AnalyticsReport::exportPdfBytes($novReport);
check(str_starts_with($pdf, '%PDF-1.4'), 'PDF export must start with %PDF-1.4 header');

// Verify StoreService domain logic in PHP
$entityId = StoreService::generateOwnEntityId('product', '', 'dining');
check(str_starts_with($entityId['codeId'], 'LR-DN-'), 'Product entity ID prefix mismatch');
$variants = StoreService::parseColorVariantsInput('Natural Walnut|#5C3A21|0, Smoked Oak|#3D312A|180');
check(count($variants) === 2 && $variants[1]['priceDelta'] === 180, 'Color variant parsing failed');
$pricing = StoreService::computeVariantPricing(['price' => 2000, 'discountPercent' => 10, 'colorVariants' => $variants], 'Smoked Oak');
check($pricing['effectivePrice'] === 1962, 'Variant pricing calculation failed');

// Verify Controllers & Server-Side Renderers in PHP
$fixtureDb = new ReportFixtureDatabase([
    'orders' => [$completed, $pending],
    'catalog' => [
        [
            'id' => 'furn-lr-dn-101',
            'productId' => 'LR-DN-101',
            'sku' => 'LR-DN-101',
            'name' => 'Atelier Walnut Dining Table',
            'category' => 'dining',
            'price' => 3200,
            'stock' => 4,
            'minStock' => 2,
            'image' => 'assets/images/category_dining_tables.jpg',
        ],
    ],
    'categories' => [
        [
            'id' => 'dining',
            'name' => 'Dining Room',
            'itemCount' => 1,
            'image' => 'assets/images/category_dining_tables.jpg',
        ],
    ],
]);

$analyticsCtrl = new AnalyticsController($fixtureDb);
$svgRev = AnalyticsController::renderDailyRevenueSvg($novReport['salesAnalytics']['dailySeries']);
check(str_contains($svgRev, '<svg'), 'AnalyticsController daily revenue SVG failed');

$storefrontCtrl = new StorefrontController($fixtureDb);
$cardHtml = $storefrontCtrl->renderProductCardHtml($fixtureDb->readStore()['catalog'][0]);
check(str_contains($cardHtml, 'Atelier Walnut Dining Table'), 'StorefrontController product card HTML failed');

$dashboardCtrl = new DashboardController($fixtureDb);
$overview = $dashboardCtrl->getAdminOverviewSnapshot();
check($overview['completedOrders'] === 1 && $overview['activeOrders'] === 1, 'DashboardController overview snapshot failed');
$analyticsHtml = $dashboardCtrl->renderAdminAnalyticsHtml(['preset' => 'select_month', 'year' => 2026, 'month' => 11]);
check(str_contains($analyticsHtml, 'data-php-rendered="admin-analytics"'), 'DashboardController analytics HTML failed');

$builtOrder = Order::buildOrderRecord([
    'customerName' => 'Elena Rostova',
    'customerEmail' => 'elena@example.com',
    'items' => [
        ['ProductID' => 'LR-DN-101', 'name' => 'Atelier Walnut Dining Table', 'quantity' => 1, 'unitPrice' => 3200],
    ],
    'discountAmount' => 200,
    'amountPaid' => 1500,
]);
$finSummary = Order::computeOrderFinancialSummary($builtOrder);
check($finSummary['totalAmount'] === 3000.0 && $finSummary['balanceDue'] === 1500.0, 'Order financial summary calculation failed');
$cancelRes = Order::handleOrderCancellation([$builtOrder], $builtOrder['OrderID'], 'request', 'Change of dimensions');
check($cancelRes['updatedOrder']['cancellationRequested'] === true, 'Order cancellation request failed');
$receiptHtml = Order::renderOfficialReceiptHtml($completed);
check(str_contains($receiptHtml, 'Official Completed Transaction Receipt'), 'Order official receipt HTML failed');

echo "PHP completed-order reporting, StoreService, Order model, and Controller checks passed.\n";
