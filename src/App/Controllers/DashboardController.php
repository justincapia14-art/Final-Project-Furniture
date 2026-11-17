<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AnalyticsReport;
use App\Models\StoreService;
use Library\Database;
use Library\OrderCompletion;

/**
 * DashboardController
 *
 * Comprehensive server-side PHP controller and HTML view renderer for both the
 * Customer Self-Service Portal (Home, Orders, Custom Commission Builder, Address/Contact,
 * Completed Transactions, Shopping Cart, and Voucher Wallet) and the Admin Management &
 * Business Analytics Console (Overview, Products, Workshop Material Inventory,
 * Fixed-Amount Vouchers, Active/Completed Orders, Customer Directory, and
 * Monthly Analytics & Business Reports).
 */
final class DashboardController
{
    private Database $db;
    private StoreService $storeService;
    private AnalyticsReport $analyticsReport;
    private AnalyticsController $analyticsController;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? new Database();
        $this->storeService = new StoreService($this->db);
        $this->analyticsReport = new AnalyticsReport($this->db);
        $this->analyticsController = new AnalyticsController($this->db);
    }

    /**
     * Escapes a scalar value for safe HTML output.
     */
    private static function esc(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Formats a numeric value as US Dollars ($).
     */
    private static function usd(int|float $amount): string
    {
        return '$' . number_format((int) round((float) $amount), 0);
    }

    /**
     * Computes the full Admin Overview KPI snapshot in PHP.
     */
    public function getAdminOverviewSnapshot(): array
    {
        $store = $this->db->readStore();
        $orders = array_values(array_filter((array) ($store['orders'] ?? []), 'is_array'));
        $catalog = array_values(array_filter((array) ($store['catalog'] ?? $store['products'] ?? []), 'is_array'));
        $materials = array_values(array_filter((array) ($store['rawMaterials'] ?? $store['materials'] ?? []), 'is_array'));
        $vouchers = array_values(array_filter((array) ($store['vouchers'] ?? []), 'is_array'));
        $customers = StoreService::computeCustomerDirectory($store);

        $totalRevenue = 0.0;
        $completedOrders = 0;
        $activeOrders = 0;
        $cancelledOrders = 0;

        foreach ($orders as $order) {
            if (OrderCompletion::isCancelled($order)) {
                $cancelledOrders++;
                continue;
            }
            if (OrderCompletion::isCompleted($order)) {
                $completedOrders++;
                $totalRevenue += AnalyticsReport::getHistoricalOrderAmount($order);
            } else {
                $activeOrders++;
            }
        }

        $lowStockProducts = 0;
        $outOfStockProducts = 0;
        foreach ($catalog as $item) {
            $stock = (int) ($item['inStockCount'] ?? $item['stock'] ?? 0);
            $minStock = (int) ($item['minStockLevel'] ?? $item['minStock'] ?? 2);
            if ($stock <= 0) {
                $outOfStockProducts++;
            } elseif ($stock <= $minStock) {
                $lowStockProducts++;
            }
        }

        $lowStockMaterials = 0;
        foreach ($materials as $mat) {
            $qty = (int) ($mat['stockQuantity'] ?? $mat['quantity'] ?? 0);
            $min = (int) ($mat['minStockLevel'] ?? $mat['minimumStock'] ?? 5);
            if ($qty <= $min) {
                $lowStockMaterials++;
            }
        }

        return [
            'totalRevenue'       => round($totalRevenue, 2),
            'totalOrders'        => count($orders),
            'completedOrders'    => $completedOrders,
            'activeOrders'       => $activeOrders,
            'cancelledOrders'    => $cancelledOrders,
            'totalCatalogItems'  => count($catalog),
            'lowStockProducts'   => $lowStockProducts,
            'outOfStockProducts' => $outOfStockProducts,
            'totalMaterials'     => count($materials),
            'lowStockMaterials'  => $lowStockMaterials,
            'totalCustomers'     => count($customers),
            'totalVouchers'      => count($vouchers),
        ];
    }

    /**
     * Renders the Customer Portal Orders list in PHP.
     */
    public function renderClientOrdersHtml(string $customerEmail = 'rowena.vance@vancearchitects.com'): string
    {
        $store = $this->db->readStore();
        $orders = array_values(array_filter((array) ($store['orders'] ?? []), 'is_array'));
        $cleanEmail = strtolower(trim($customerEmail));

        $clientOrders = array_values(array_filter($orders, static function (array $o) use ($cleanEmail): bool {
            return strtolower(trim((string) ($o['clientEmail'] ?? ''))) === $cleanEmail;
        }));

        if (empty($clientOrders)) {
            return '<div class="p-8 rounded-3xl border border-[#BCE3FF] bg-white text-center text-xs text-slate-500">No active or historical furniture orders found for this account.</div>';
        }

        ob_start();
        foreach ($clientOrders as $order) {
            $orderNum = self::esc($order['orderNumber'] ?? $order['orderId'] ?? 'ORD-2026');
            $furnitureName = self::esc($order['furnitureName'] ?? 'Handcrafted Furniture Piece');
            $finish = self::esc($order['selectedFinish'] ?? 'Natural Finish');
            $dimensions = self::esc($order['customDimensions'] ?? 'Standard Showroom Dimensions');
            $status = self::esc($order['status'] ?? 'Order Confirmed');
            $orderDate = self::esc($order['orderDate'] ?? '');
            $estDelivery = self::esc($order['estimatedDelivery'] ?? '4–6 Weeks');
            $craftsperson = self::esc($order['assignedCraftsperson'] ?? 'Master Joinery Team');
            $total = (float) ($order['totalAmount'] ?? 0);
            $paid = (float) ($order['depositPaid'] ?? 0);
            $balance = max(0.0, $total - $paid);
            $isCompleted = OrderCompletion::isCompleted($order);
            $badgeClass = $isCompleted
                ? 'bg-emerald-50 text-emerald-800 border-emerald-200'
                : 'bg-[#EBF6FF] text-[#0077CC] border-[#BCE3FF]';
            ?>
            <article class="bg-white rounded-3xl border border-[#BCE3FF] p-6 space-y-4 shadow-2xs" data-php-order-card="<?= $orderNum ?>">
              <div class="flex flex-wrap items-start justify-between gap-4 pb-4 border-b border-slate-100">
                <div>
                  <div class="flex items-center gap-2.5">
                    <span class="font-mono-tech text-xs font-bold text-[#0095FF]">Order #<?= $orderNum ?></span>
                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-semibold border <?= $badgeClass ?>"><?= $status ?></span>
                  </div>
                  <h3 class="font-serif-display text-xl font-bold text-[#0D1015] mt-1"><?= $furnitureName ?></h3>
                  <p class="text-xs text-[#475569] mt-0.5">Finish: <?= $finish ?> · Dimensions: <?= $dimensions ?></p>
                </div>
                <div class="text-left sm:text-right">
                  <p class="font-mono-tech text-lg font-bold text-[#0D1015]"><?= self::usd($total) ?></p>
                  <p class="font-mono-tech text-xs <?= $balance > 0 ? 'text-amber-700' : 'text-emerald-700' ?>">
                    <?= $balance > 0 ? 'Balance Due: ' . self::usd($balance) : 'Paid in Full (' . self::usd($paid) . ')' ?>
                  </p>
                </div>
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs text-[#475569]">
                <div class="p-3 rounded-xl bg-[#F8FBFF] border border-[#E2E8F0]">
                  <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Order Date</span>
                  <span class="font-mono-tech font-semibold text-slate-800 mt-0.5 block"><?= $orderDate ?></span>
                </div>
                <div class="p-3 rounded-xl bg-[#F8FBFF] border border-[#E2E8F0]">
                  <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Assigned Craftsperson</span>
                  <span class="font-semibold text-slate-800 mt-0.5 block"><?= $craftsperson ?></span>
                </div>
                <div class="p-3 rounded-xl bg-[#F8FBFF] border border-[#E2E8F0]">
                  <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-400">Estimated Delivery</span>
                  <span class="font-mono-tech font-semibold text-slate-800 mt-0.5 block"><?= $estDelivery ?></span>
                </div>
              </div>
            </article>
            <?php
        }
        return (string) ob_get_clean();
    }

    /**
     * Renders the Customer Portal Completed Transactions table rows in PHP.
     */
    public function renderClientTransactionsRowsHtml(string $customerEmail = 'rowena.vance@vancearchitects.com'): string
    {
        $store = $this->db->readStore();
        $orders = array_values(array_filter((array) ($store['orders'] ?? []), 'is_array'));
        $cleanEmail = strtolower(trim($customerEmail));

        $completedOrders = array_values(array_filter($orders, static function (array $o) use ($cleanEmail): bool {
            return strtolower(trim((string) ($o['clientEmail'] ?? ''))) === $cleanEmail
                && OrderCompletion::isCompleted($o);
        }));

        ob_start();
        foreach ($completedOrders as $order) {
            $orderNum = self::esc($order['orderNumber'] ?? $order['orderId'] ?? '');
            $txnRef = self::esc($order['transactionReference'] ?? ('TXN-' . $orderNum));
            $compDate = self::esc(OrderCompletion::reportDate($order) ?? ($order['orderDate'] ?? ''));
            $furnitureName = self::esc($order['furnitureName'] ?? 'Furniture Piece');
            $finish = self::esc($order['selectedFinish'] ?? 'Standard Finish');
            $total = self::usd((float) ($order['totalAmount'] ?? 0));
            ?>
            <tr>
              <td class="font-mono-tech text-xs font-bold text-slate-800"><?= $txnRef ?></td>
              <td class="font-mono-tech text-xs font-bold text-[#0095FF]">#<?= $orderNum ?></td>
              <td class="font-mono-tech text-xs text-slate-600"><?= $compDate ?></td>
              <td>
                <p class="admin-table-title"><?= $furnitureName ?></p>
                <p class="admin-table-sub"><?= $finish ?></p>
              </td>
              <td>
                <span class="admin-status-badge admin-status-in-stock">
                  <span class="admin-status-badge-dot"></span>Completed
                </span>
              </td>
              <td class="text-right font-mono-tech text-xs font-bold text-emerald-700"><?= $total ?></td>
            </tr>
            <?php
        }
        return (string) ob_get_clean();
    }

    /**
     * Renders the Customer Portal Voucher Wallet cards in PHP.
     */
    public function renderClientVouchersHtml(string $customerEmail = 'rowena.vance@vancearchitects.com'): string
    {
        $store = $this->db->readStore();
        $vouchers = array_values(array_filter((array) ($store['vouchers'] ?? []), 'is_array'));
        $cleanEmail = strtolower(trim($customerEmail));

        $clientVouchers = array_values(array_filter($vouchers, static function (array $v) use ($cleanEmail): bool {
            $assigned = strtolower(trim((string) ($v['customerEmail'] ?? '')));
            return $assigned === $cleanEmail || $assigned === 'all' || $assigned === '*';
        }));

        ob_start();
        foreach ($clientVouchers as $v) {
            $code = self::esc($v['code'] ?? '');
            $productName = self::esc($v['furnitureName'] ?? $v['productName'] ?? 'Eligible Showroom Piece');
            $discount = self::usd((float) ($v['discountAmount'] ?? $v['discountValue'] ?? 0));
            $status = self::esc($v['status'] ?? 'Active');
            $expiry = self::esc($v['expiryDate'] ?? $v['expiresAt'] ?? 'No expiry');
            ?>
            <article class="bg-white rounded-3xl border border-[#BCE3FF] p-5 space-y-3 shadow-2xs">
              <div class="flex items-center justify-between gap-2">
                <span class="vch-code-chip"><?= $code ?></span>
                <span class="px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200 font-mono-tech text-[11px] font-bold"><?= $status ?></span>
              </div>
              <div>
                <p class="font-mono-tech text-xl font-bold text-[#0095FF]">-<?= $discount ?> OFF</p>
                <p class="text-xs font-semibold text-slate-800 mt-0.5">Valid for: <?= $productName ?></p>
                <p class="font-mono-tech text-[11px] text-slate-500 mt-1">Expires: <?= $expiry ?></p>
              </div>
            </article>
            <?php
        }
        return (string) ob_get_clean();
    }

    /**
     * Renders the Admin Catalog Products table in PHP.
     */
    public function renderAdminCatalogTableHtml(): string
    {
        $store = $this->db->readStore();
        $products = array_values(array_filter((array) ($store['catalog'] ?? $store['products'] ?? []), 'is_array'));

        ob_start();
        ?>
        <div class="admin-card overflow-hidden" data-php-rendered="admin-catalog">
          <div class="overflow-x-auto">
            <table class="admin-table">
              <thead>
                <tr>
                  <th class="admin-col-primary">Product</th>
                  <th>SKU / ProductID</th>
                  <th>Category</th>
                  <th>Price</th>
                  <th>Stock</th>
                  <th class="text-right">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($products as $p):
                    $id = self::esc($p['id'] ?? '');
                    $sku = self::esc($p['productId'] ?? $p['sku'] ?? '');
                    $name = self::esc($p['name'] ?? '');
                    $cat = self::esc($p['categoryName'] ?? $p['category'] ?? 'Showroom');
                    $price = self::usd((float) ($p['effectivePrice'] ?? $p['price'] ?? 0));
                    $stock = (int) ($p['inStockCount'] ?? $p['stock'] ?? 0);
                    $minStock = (int) ($p['minStockLevel'] ?? $p['minStock'] ?? 2);
                    $statusLabel = $stock <= 0 ? 'Out of Stock' : ($stock <= $minStock ? 'Low Stock' : 'In Stock');
                    $statusClass = $stock <= 0 ? 'admin-status-out-of-stock' : ($stock <= $minStock ? 'admin-status-low-stock' : 'admin-status-in-stock');
                ?>
                  <tr data-product-id="<?= $id ?>">
                    <td class="admin-col-primary">
                      <p class="admin-table-title"><?= $name ?></p>
                    </td>
                    <td class="font-mono-tech text-xs text-slate-600"><?= $sku ?></td>
                    <td class="text-xs text-slate-600"><?= $cat ?></td>
                    <td class="font-mono-tech text-xs font-bold text-[#0095FF]"><?= $price ?></td>
                    <td class="font-mono-tech text-xs font-bold text-slate-900"><?= $stock ?> units</td>
                    <td class="text-right">
                      <span class="admin-status-badge <?= $statusClass ?>">
                        <span class="admin-status-badge-dot"></span><?= $statusLabel ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Renders the Admin Workshop Materials Inventory table in PHP.
     */
    public function renderAdminInventoryTableHtml(): string
    {
        $store = $this->db->readStore();
        $materials = array_values(array_filter((array) ($store['rawMaterials'] ?? $store['materials'] ?? []), 'is_array'));

        ob_start();
        ?>
        <div class="admin-card overflow-hidden" data-php-rendered="admin-inventory">
          <div class="overflow-x-auto">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>Material ID</th>
                  <th class="admin-col-primary">Material Name</th>
                  <th>Category</th>
                  <th>Available Stock</th>
                  <th>Unit Cost</th>
                  <th class="text-right">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($materials as $m):
                    $code = self::esc($m['materialId'] ?? $m['code'] ?? 'MAT-01');
                    $name = self::esc($m['name'] ?? 'Workshop Material');
                    $type = self::esc($m['materialType'] ?? 'Solid Timber');
                    $qty = (int) ($m['stockQuantity'] ?? $m['quantity'] ?? 0);
                    $min = (int) ($m['minStockLevel'] ?? $m['minimumStock'] ?? 10);
                    $unit = self::esc($m['unit'] ?? 'bd ft');
                    $cost = self::usd((float) ($m['unitCost'] ?? 0));
                    $statusLabel = $qty <= 0 ? 'Out of Stock' : ($qty <= $min ? 'Low Stock' : 'In Stock');
                    $statusClass = $qty <= 0 ? 'admin-status-out-of-stock' : ($qty <= $min ? 'admin-status-low-stock' : 'admin-status-in-stock');
                ?>
                  <tr>
                    <td class="font-mono-tech text-xs font-bold text-slate-700"><?= $code ?></td>
                    <td class="admin-col-primary"><p class="admin-table-title"><?= $name ?></p></td>
                    <td class="text-xs text-slate-600"><?= $type ?></td>
                    <td class="font-mono-tech text-xs font-bold text-slate-900"><?= $qty ?> <?= $unit ?></td>
                    <td class="font-mono-tech text-xs text-slate-700"><?= $cost ?> / <?= $unit ?></td>
                    <td class="text-right">
                      <span class="admin-status-badge <?= $statusClass ?>">
                        <span class="admin-status-badge-dot"></span><?= $statusLabel ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Renders the Admin Customer Directory table in PHP.
     */
    public function renderAdminCustomersTableHtml(): string
    {
        $store = $this->db->readStore();
        $customers = StoreService::computeCustomerDirectory($store);

        ob_start();
        ?>
        <div class="admin-card overflow-hidden" data-php-rendered="admin-customers">
          <div class="overflow-x-auto">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>Customer ID</th>
                  <th class="admin-col-primary">Client Name &amp; Email</th>
                  <th>Phone</th>
                  <th>Delivery Address</th>
                  <th>Orders</th>
                  <th class="text-right">Total Spend</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($customers as $c):
                    $cid = self::esc($c['customerId'] ?? $c['id'] ?? 'LR-CUS');
                    $name = self::esc($c['name'] ?? 'Client');
                    $email = self::esc($c['email'] ?? '');
                    $phone = self::esc($c['phone'] ?? '');
                    $address = self::esc($c['address'] ?? '');
                    $orderCount = (int) ($c['orderCount'] ?? 0);
                    $spent = self::usd((float) ($c['totalSpent'] ?? 0));
                ?>
                  <tr>
                    <td class="font-mono-tech text-xs font-bold text-slate-700"><?= $cid ?></td>
                    <td class="admin-col-primary">
                      <p class="admin-table-title"><?= $name ?></p>
                      <p class="admin-table-sub font-mono-tech"><?= $email ?></p>
                    </td>
                    <td class="font-mono-tech text-xs text-slate-600"><?= $phone ?></td>
                    <td class="text-xs text-slate-600"><?= $address ?></td>
                    <td class="font-mono-tech text-xs font-bold text-slate-900"><?= $orderCount ?></td>
                    <td class="text-right font-mono-tech text-xs font-bold text-[#0095FF]"><?= $spent ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Renders the complete Admin Monthly Analytics & Business Reports HTML section in PHP.
     */
    public function renderAdminAnalyticsHtml(array $query = []): string
    {
        $report = $this->analyticsReport->generateReport($query);
        $period = (array) ($report['period'] ?? []);
        $summary = (array) ($report['summary'] ?? []);
        $sales = (array) ($report['salesAnalytics'] ?? []);
        $bestSellers = (array) ($report['bestSellingProducts'] ?? []);
        $ordAnalytics = (array) ($report['orderAnalytics'] ?? []);
        $custAnalytics = (array) ($report['customerAnalytics'] ?? []);
        $invAnalytics = (array) ($report['inventoryAnalytics'] ?? []);
        $vchAnalytics = (array) ($report['voucherAnalytics'] ?? []);
        $transactions = (array) ($report['transactions'] ?? []);

        $periodLabel = self::esc($period['label'] ?? 'Selected Period');
        $comparisonLabel = self::esc($period['comparisonLabel'] ?? 'Previous Period');

        $dailyRevSvg = $this->analyticsController->renderDailyRevenueSvg((array) ($sales['dailyRevenue'] ?? $sales['dailySeries'] ?? []));
        $dailyOrdSvg = $this->analyticsController->renderDailyOrdersSvg((array) ($sales['dailyOrders'] ?? $sales['dailySeries'] ?? []));

        $revVal = (float) ($summary['totalRevenue']['value'] ?? 0);
        $ordVal = (int) ($summary['totalOrders']['value'] ?? 0);
        $soldVal = (int) ($summary['totalProductsSold']['value'] ?? 0);
        $aovVal = (float) ($summary['averageOrderValue']['value'] ?? 0);
        $custVal = (int) ($summary['newCustomers']['value'] ?? $summary['newCustomersRegistered']['value'] ?? 0);
        $vchVal = (int) ($vchAnalytics['redeemedInPeriodCount'] ?? 0);

        ob_start();
        ?>
        <div class="space-y-6" data-php-rendered="admin-analytics">
          <div class="admin-card p-6 bg-[#0B0F17] text-white border border-slate-800">
            <div class="flex flex-wrap items-center justify-between gap-4">
              <div>
                <p class="font-mono-tech text-[11px] uppercase tracking-widest text-[#38BDF8]">PHP Server-Computed Business Report</p>
                <h3 class="font-serif-display text-xl font-bold mt-1"><?= $periodLabel ?> — Executive Summary</h3>
                <p class="text-xs text-slate-300 mt-0.5">Compared against <?= $comparisonLabel ?> · Realized revenue from Completed Orders only</p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <a href="src/api.php?action=export_analytics_pdf&amp;preset=<?= urlencode((string) ($period['preset'] ?? 'this_month')) ?>&amp;year=<?= (int) ($period['year'] ?? 2026) ?>&amp;month=<?= (int) ($period['month'] ?? 11) ?>"
                   class="px-3.5 py-2 rounded-xl bg-[#0095FF] hover:bg-[#0077CC] text-white text-xs font-semibold transition-colors">
                  Download PDF Report
                </a>
                <a href="src/api.php?action=export_analytics_csv&amp;preset=<?= urlencode((string) ($period['preset'] ?? 'this_month')) ?>&amp;year=<?= (int) ($period['year'] ?? 2026) ?>&amp;month=<?= (int) ($period['month'] ?? 11) ?>"
                   class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-white border border-slate-700 text-xs font-semibold transition-colors">
                  Export CSV
                </a>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-2 lg:grid-cols-6 gap-3">
            <div class="admin-card p-4">
              <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Total Revenue</span>
              <p class="font-mono-tech text-xl font-bold text-[#0095FF] mt-1"><?= self::usd($revVal) ?></p>
            </div>
            <div class="admin-card p-4">
              <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Completed Orders</span>
              <p class="font-mono-tech text-xl font-bold text-slate-900 mt-1"><?= $ordVal ?></p>
            </div>
            <div class="admin-card p-4">
              <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Products Sold</span>
              <p class="font-mono-tech text-xl font-bold text-slate-900 mt-1"><?= $soldVal ?> units</p>
            </div>
            <div class="admin-card p-4">
              <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Avg Order Value</span>
              <p class="font-mono-tech text-xl font-bold text-emerald-700 mt-1"><?= self::usd($aovVal) ?></p>
            </div>
            <div class="admin-card p-4">
              <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">New Customers</span>
              <p class="font-mono-tech text-xl font-bold text-slate-900 mt-1"><?= $custVal ?></p>
            </div>
            <div class="admin-card p-4">
              <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Vouchers Redeemed</span>
              <p class="font-mono-tech text-xl font-bold text-indigo-700 mt-1"><?= $vchVal ?></p>
            </div>
          </div>

          <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="admin-card p-5 space-y-3">
              <h4 class="text-sm font-bold text-slate-900">Daily Revenue (<?= $periodLabel ?>)</h4>
              <?= $dailyRevSvg ?>
            </div>
            <div class="admin-card p-5 space-y-3">
              <h4 class="text-sm font-bold text-slate-900">Daily Completed Orders (<?= $periodLabel ?>)</h4>
              <?= $dailyOrdSvg ?>
            </div>
          </div>

          <div class="admin-card overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex items-center justify-between">
              <h4 class="text-sm font-bold text-slate-900">Top Best-Selling Products (<?= $periodLabel ?>)</h4>
              <span class="font-mono-tech text-xs text-slate-600"><?= count((array) ($bestSellers['topFive'] ?? [])) ?> Ranked</span>
            </div>
            <div class="overflow-x-auto">
              <table class="admin-table">
                <thead>
                  <tr>
                    <th>Rank</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Qty Sold</th>
                    <th>Revenue</th>
                    <th>Stock</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ((array) ($bestSellers['topFive'] ?? []) as $item): ?>
                    <tr>
                      <td class="font-mono-tech text-xs font-bold">#<?= (int) ($item['rank'] ?? 0) ?></td>
                      <td class="text-xs font-bold text-slate-900"><?= self::esc($item['name'] ?? '') ?></td>
                      <td class="text-xs text-slate-600"><?= self::esc($item['categoryName'] ?? '') ?></td>
                      <td class="font-mono-tech text-xs font-bold"><?= (int) ($item['quantitySold'] ?? 0) ?></td>
                      <td class="font-mono-tech text-xs font-bold text-[#0095FF]"><?= self::usd((float) ($item['totalRevenue'] ?? 0)) ?></td>
                      <td class="font-mono-tech text-xs"><?= self::esc($item['stockStatusLabel'] ?? '') ?> (<?= (int) ($item['currentStock'] ?? 0) ?>)</td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="admin-card overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex items-center justify-between">
              <h4 class="text-sm font-bold text-slate-900">Period Transactions Ledger (<?= count($transactions) ?>)</h4>
              <span class="font-mono-tech text-xs text-slate-600">Order Statuses: <?= (int) ($ordAnalytics['totalOrders'] ?? 0) ?> Completed · <?= (int) ($custAnalytics['totalRegisteredCustomers'] ?? 0) ?> Registered Clients · <?= (int) ($vchAnalytics['totalVouchersIssued'] ?? 0) ?> Vouchers · Inventory Value: <?= self::usd((float) ($invAnalytics['currentSnapshot']['currentInventoryValue'] ?? 0)) ?></span>
            </div>
            <div class="overflow-x-auto">
              <table class="admin-table">
                <thead>
                  <tr>
                    <th>Order #</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Product</th>
                    <th>Qty</th>
                    <th>Status</th>
                    <th class="text-right">Realized Revenue</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($transactions as $t): ?>
                    <tr>
                      <td class="font-mono-tech text-xs font-bold text-[#0095FF]">#<?= self::esc($t['orderNumber'] ?? '') ?></td>
                      <td class="font-mono-tech text-xs text-slate-600"><?= self::esc($t['reportDate'] ?? $t['orderDate'] ?? '') ?></td>
                      <td class="text-xs font-semibold text-slate-900"><?= self::esc($t['clientName'] ?? '') ?></td>
                      <td class="text-xs text-slate-700"><?= self::esc($t['furnitureName'] ?? '') ?></td>
                      <td class="font-mono-tech text-xs"><?= (int) ($t['quantity'] ?? 1) ?></td>
                      <td class="font-mono-tech text-xs"><?= self::esc($t['normalizedStatus'] ?? 'Completed') ?></td>
                      <td class="text-right font-mono-tech text-xs font-bold text-emerald-700"><?= self::usd((float) ($t['realizedRevenue'] ?? 0)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Renders the Admin Orders Ledger table rows in PHP.
     */
    public function renderAdminOrdersTableHtml(string $viewFilter = 'active'): string
    {
        $store = $this->db->readStore();
        $orders = array_values(array_filter((array) ($store['orders'] ?? []), 'is_array'));

        ob_start();
        ?>
        <div class="admin-card overflow-hidden" data-php-rendered="admin-orders">
          <div class="overflow-x-auto">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>Order #</th>
                  <th>Customer</th>
                  <th>Furniture Piece</th>
                  <th>Order Total</th>
                  <th>Balance</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($orders as $order):
                    $isCompleted = OrderCompletion::isCompleted($order);
                    $isCancelled = OrderCompletion::isCancelled($order);
                    if ($viewFilter === 'completed' && !$isCompleted) {
                        continue;
                    }
                    if ($viewFilter === 'active' && ($isCompleted || $isCancelled)) {
                        continue;
                    }
                    $orderId = self::esc($order['orderId'] ?? '');
                    $orderNumber = self::esc($order['orderNumber'] ?? $orderId);
                    $clientName = self::esc($order['clientName'] ?? 'Showroom Client');
                    $furnitureName = self::esc($order['furnitureName'] ?? 'Bespoke Piece');
                    $status = self::esc($order['status'] ?? 'Order Confirmed');
                    $total = self::usd((float) ($order['totalAmount'] ?? 0));
                    $balance = self::usd(max(0.0, (float) ($order['totalAmount'] ?? 0) - (float) ($order['depositPaid'] ?? 0)));
                ?>
                  <tr data-order-id="<?= $orderId ?>">
                    <td class="font-mono-tech text-xs font-bold text-[#0095FF]">#<?= $orderNumber ?></td>
                    <td class="text-xs font-semibold text-slate-900"><?= $clientName ?></td>
                    <td class="text-xs text-slate-700"><?= $furnitureName ?></td>
                    <td class="font-mono-tech text-xs font-bold text-slate-900"><?= $total ?></td>
                    <td class="font-mono-tech text-xs text-amber-700"><?= $balance ?></td>
                    <td class="font-mono-tech text-xs"><?= $status ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
