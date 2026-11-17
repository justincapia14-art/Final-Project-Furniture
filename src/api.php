<?php

declare(strict_types=1);

/**
 * JSON & Document REST API Endpoint (`src/api.php`)
 *
 * Synchronizes the Storefront (`index.php` / `index.html`), Catalog (`catalog.php` / `catalog.html`),
 * Login (`login.php` / `login.html`), and Unified Role-Aware Dashboard (`dashboard.php` / `src/home.php`)
 * with the PHP/MySQL `Database`, `Product`, `StoreService`, `AnalyticsReport`, and `User` models.
 */

require_once __DIR__ . '/../config/bootstrap.php';

use App\Controllers\AnalyticsController;
use App\Controllers\DashboardController;
use App\Controllers\StorefrontController;
use App\Models\AnalyticsReport;
use App\Models\Product;
use App\Models\StoreService;
use App\Models\User;
use Library\Database;
use Library\Exceptions\AuthenticationException;
use Library\Exceptions\DatabaseException;
use Library\Exceptions\ValidationException;

$rawBody = file_get_contents('php://input');
$payload = is_string($rawBody) && $rawBody !== '' ? json_decode($rawBody, true) : [];
if (!is_array($payload)) {
    $payload = [];
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? $payload['action'] ?? 'state');

// Handle binary/CSV download actions before emitting application/json header
if ($action === 'export_analytics_csv' || $action === 'export_analytics_pdf') {
    $db = new Database();
    $store = $db->readStore();
    $filterOptions = is_array($payload['filterOptions'] ?? null)
        ? $payload['filterOptions']
        : [
            'preset'    => $_GET['preset'] ?? 'this_month',
            'year'      => $_GET['year'] ?? null,
            'month'     => $_GET['month'] ?? null,
            'startDate' => $_GET['startDate'] ?? null,
            'endDate'   => $_GET['endDate'] ?? null,
        ];
    $report = AnalyticsReport::generateReport($store, $filterOptions);
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', (string) ($report['period']['label'] ?? 'monthly-report')) ?? 'monthly-report');
    if ($action === 'export_analytics_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="lillia-rowena-analytics-' . $slug . '.csv"');
        echo AnalyticsReport::exportCsv($report);
        exit;
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="lillia-rowena-analytics-' . $slug . '.pdf"');
    echo AnalyticsReport::exportPdfBytes($report);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $db = new Database();
    $productModel = new Product($db);
    $userModel = new User($db);
    $storefrontController = new StorefrontController($db);
    $analyticsController = new AnalyticsController($db);
    $dashboardController = new DashboardController($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'state') {
        $store = $storefrontController->getHydratedStoreState();
        if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            $store['sessionUser'] = $_SESSION['user'];
        }
        echo json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'analytics_report' || $action === 'get_analytics_report' || $action === 'render_admin_analytics') {
        $filterOptions = is_array($payload['filterOptions'] ?? null)
            ? $payload['filterOptions']
            : (is_array($payload['filter'] ?? null)
                ? $payload['filter']
                : [
                    'preset'    => $_GET['preset'] ?? $payload['preset'] ?? 'this_month',
                    'year'      => $_GET['year'] ?? $payload['year'] ?? null,
                    'month'     => $_GET['month'] ?? $payload['month'] ?? null,
                    'startDate' => $_GET['startDate'] ?? $payload['startDate'] ?? null,
                    'endDate'   => $_GET['endDate'] ?? $payload['endDate'] ?? null,
                ]);
        $nowIso = isset($payload['nowIso']) ? (string) $payload['nowIso'] : null;
        $report = $analyticsController->getReport($filterOptions, $nowIso);
        $report['chartsSvg'] = [
            'dailyRevenue' => AnalyticsController::renderDailyRevenueSvg($report['salesAnalytics']['dailySeries'] ?? []),
            'dailyOrders'  => AnalyticsController::renderDailyOrdersSvg($report['salesAnalytics']['dailySeries'] ?? []),
        ];
        $html = $dashboardController->renderAdminAnalyticsHtml($filterOptions);
        echo json_encode(['ok' => true, 'report' => $report, 'html' => $html], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'login' || $action === 'login_user') {
        $email = (string) ($payload['email'] ?? $_POST['email'] ?? '');
        $password = (string) ($payload['password'] ?? $_POST['password'] ?? '');
        $role = (string) ($payload['role'] ?? $_POST['role'] ?? 'client');

        $authenticatedUser = $userModel->authenticate($email, $password, $role);
        $_SESSION['user'] = $authenticatedUser;
        $db->saveSessionUser($authenticatedUser);

        echo json_encode([
            'ok'          => true,
            'user'        => $authenticatedUser,
            'sessionUser' => $authenticatedUser,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'register' || $action === 'register_customer') {
        $registeredUser = $userModel->registerCustomer([
            'fullName'        => (string) ($payload['fullName'] ?? $payload['name'] ?? $_POST['fullName'] ?? ''),
            'email'           => (string) ($payload['email'] ?? $_POST['email'] ?? ''),
            'phoneNumber'     => (string) ($payload['phoneNumber'] ?? $_POST['phoneNumber'] ?? ''),
            'address'         => (string) ($payload['address'] ?? $_POST['address'] ?? ''),
            'password'        => (string) ($payload['password'] ?? $_POST['password'] ?? ''),
            'confirmPassword' => (string) ($payload['confirmPassword'] ?? $payload['password'] ?? $_POST['confirmPassword'] ?? ''),
        ]);
        $_SESSION['user'] = $registeredUser;
        $db->saveSessionUser($registeredUser);

        echo json_encode([
            'ok'          => true,
            'user'        => $registeredUser,
            'sessionUser' => $registeredUser,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'upload_product_image') {
        $dataUrl = trim((string) ($payload['dataUrl'] ?? ''));
        $rawFileName = (string) ($payload['fileName'] ?? 'product.jpg');

        if (!preg_match('/^data:image\/(png|jpe?g|webp|gif);base64,(.+)$/i', $dataUrl, $matches)) {
            throw new ValidationException('Invalid image format. Please upload a PNG, JPG, WEBP, or GIF image.');
        }

        $ext = strtolower($matches[1]) === 'jpeg' ? 'jpg' : strtolower($matches[1]);
        $binary = base64_decode($matches[2], true);
        if ($binary === false || strlen($binary) === 0) {
            throw new ValidationException('Uploaded image file could not be decoded.');
        }

        $baseSlug = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($rawFileName, PATHINFO_FILENAME));
        $baseSlug = trim((string) $baseSlug, '_');
        if ($baseSlug === '') {
            $baseSlug = 'furniture';
        }

        $uploadDir = dirname(__DIR__) . '/assets/images/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $targetFilename = sprintf('prod_%s_%d.%s', substr($baseSlug, 0, 32), time(), $ext);
        $targetFullPath = $uploadDir . '/' . $targetFilename;

        if (file_put_contents($targetFullPath, $binary) === false) {
            throw new DatabaseException('Unable to save uploaded product image to assets/images/uploads.');
        }

        echo json_encode([
            'ok'        => true,
            'imagePath' => 'assets/images/uploads/' . $targetFilename,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'save_product') {
        $productInput = isset($payload['product']) && is_array($payload['product']) ? $payload['product'] : $payload;
        $record = $productModel->createOrUpdateProduct($productInput);
        $db->upsertProduct($record);

        echo json_encode([
            'ok'      => true,
            'product' => $record,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'delete_product') {
        $productId = trim((string) ($payload['productId'] ?? $payload['id'] ?? ''));
        if ($productId === '') {
            throw new ValidationException('Product ID is required to delete a product.');
        }
        $db->deleteProduct($productId);

        echo json_encode([
            'ok'        => true,
            'productId' => $productId,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'save_material') {
        $store = $db->readStore();
        if (isset($payload['materials']) && is_array($payload['materials'])) {
            $store['materials'] = $payload['materials'];
        } elseif (isset($payload['material']) && is_array($payload['material'])) {
            $matRecord = $productModel->createOrUpdateMaterial($payload['material']);
            $materials = isset($store['materials']) && is_array($store['materials']) ? $store['materials'] : [];
            $updated = false;
            foreach ($materials as $idx => $existing) {
                if (($existing['id'] ?? '') === $matRecord['id']) {
                    $materials[$idx] = array_merge($existing, $matRecord);
                    $updated = true;
                    break;
                }
            }
            if (!$updated) {
                array_unshift($materials, $matRecord);
            }
            $store['materials'] = $materials;
        }
        if (isset($payload['inventoryTransactions']) && is_array($payload['inventoryTransactions'])) {
            $store['inventoryTransactions'] = $payload['inventoryTransactions'];
        }
        $db->writeStore($store);

        echo json_encode([
            'ok'        => true,
            'materials' => $store['materials'],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'delete_material') {
        $materialId = trim((string) ($payload['materialId'] ?? $payload['id'] ?? ''));
        if ($materialId === '') {
            throw new ValidationException('Material ID is required to remove a material.');
        }
        $store = $db->readStore();
        $materials = isset($store['materials']) && is_array($store['materials']) ? $store['materials'] : [];
        $store['materials'] = array_values(array_filter($materials, static function (array $m) use ($materialId): bool {
            return (string) ($m['id'] ?? '') !== $materialId;
        }));
        $db->writeStore($store);

        echo json_encode([
            'ok'         => true,
            'materialId' => $materialId,
            'materials'  => $store['materials'],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'create_voucher') {
        $voucherInput = isset($payload['voucher']) && is_array($payload['voucher']) ? $payload['voucher'] : $payload;
        $record = $productModel->createVoucher($voucherInput);
        $vouchersList = method_exists($productModel, 'getAllVouchersWithResolvedStatus')
            ? $productModel->getAllVouchersWithResolvedStatus()
            : ($db->readStore()['vouchers'] ?? []);

        echo json_encode([
            'ok'       => true,
            'voucher'  => $record,
            'vouchers' => $vouchersList,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'revoke_voucher') {
        $voucherId = (string) ($payload['voucherId'] ?? $payload['id'] ?? $payload['code'] ?? '');
        $revoked = $productModel->revokeVoucher($voucherId);
        $vouchersList = method_exists($productModel, 'getAllVouchersWithResolvedStatus')
            ? $productModel->getAllVouchersWithResolvedStatus()
            : ($db->readStore()['vouchers'] ?? []);

        echo json_encode([
            'ok'       => true,
            'voucher'  => $revoked,
            'vouchers' => $vouchersList,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'validate_voucher') {
        $voucherCode = (string) ($payload['voucherCode'] ?? $payload['code'] ?? '');
        $customerEmail = (string) ($payload['customerEmail'] ?? $payload['email'] ?? ($_SESSION['user']['email'] ?? ''));
        $cartItems = isset($payload['cartItems']) && is_array($payload['cartItems']) ? $payload['cartItems'] : [];
        $productId = (string) ($payload['productId'] ?? '');

        if (!empty($cartItems)) {
            $pricing = $productModel->calculateCartOrderWithVoucher($cartItems, $voucherCode, $customerEmail);
            echo json_encode([
                'ok'             => true,
                'valid'          => true,
                'voucher'        => $pricing['validatedVoucher'],
                'discountAmount' => $pricing['voucherDiscountAmount'],
                'pricing'        => $pricing,
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $validatedVoucher = $productModel->validateVoucherForProduct($voucherCode, $customerEmail, $productId);
        echo json_encode([
            'ok'             => true,
            'valid'          => true,
            'voucher'        => $validatedVoucher,
            'discountAmount' => (float) ($validatedVoucher['discountAmount'] ?? 0),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'update_order_status') {
        $orderNumber = trim((string) ($payload['orderNumber'] ?? ''));
        $updates = is_array($payload['updates'] ?? null) ? $payload['updates'] : ['status' => (string) ($payload['status'] ?? '')];
        $store = $db->readStore();
        $orders = is_array($store['orders'] ?? null) ? $store['orders'] : [];
        $updatedOrder = null;
        foreach ($orders as $idx => $ord) {
            if (($ord['orderNumber'] ?? '') === $orderNumber) {
                $updatedOrder = StoreService::applyAdminOrderUpdate($ord, $updates);
                $orders[$idx] = $updatedOrder;
                break;
            }
        }
        if ($updatedOrder === null) {
            throw new ValidationException('Order #' . $orderNumber . ' not found.');
        }
        $store['orders'] = $orders;
        $db->writeStore($store);
        echo json_encode(['ok' => true, 'order' => $updatedOrder, 'orders' => $orders], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'save_offer') {
        $offer = isset($payload['offer']) && is_array($payload['offer']) ? $payload['offer'] : null;
        if ($offer !== null) {
            $store = $db->readStore();
            if (!isset($store['offers']) || !is_array($store['offers'])) {
                $store['offers'] = [];
            }
            array_unshift($store['offers'], $offer);
            $db->writeStore($store);
        }

        echo json_encode([
            'ok'    => true,
            'offer' => $offer,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'save_cart') {
        $cartItems = isset($payload['cartItems']) && is_array($payload['cartItems']) ? $payload['cartItems'] : [];
        $db->saveCartItems($cartItems);

        echo json_encode([
            'ok'        => true,
            'cartItems' => $cartItems,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'save_session') {
        $sessionUser = isset($payload['sessionUser']) && is_array($payload['sessionUser']) ? $payload['sessionUser'] : null;
        if ($sessionUser !== null) {
            $_SESSION['user'] = $sessionUser;
        } else {
            unset($_SESSION['user']);
        }
        $db->saveSessionUser($sessionUser);

        echo json_encode([
            'ok'          => true,
            'sessionUser' => $sessionUser,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'sync_store') {
        $currentStore = $db->readStore();
        $allowedKeys = [
            'categories',
            'products',
            'materials',
            'customers',
            'messengerConversations',
            'inventoryTransactions',
            'vouchers',
            'orders',
            'offers',
            'cartItems',
            'sessionUser',
            'customerProfile',
        ];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $currentStore[$key] = $payload[$key];
            }
        }
        $db->writeStore($currentStore);

        echo json_encode([
            'ok'    => true,
            'store' => $currentStore,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok'    => true,
        'store' => $storefrontController->getHydratedStoreState(),
    ], JSON_UNESCAPED_SLASHES);
} catch (ValidationException | AuthenticationException $e) {
    http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
    echo json_encode([
        'ok'    => false,
        'valid' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
} catch (DatabaseException | Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'valid' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
