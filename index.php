<?php

declare(strict_types=1);

/**
 * Root PHP Storefront Entrypoint (`index.php`)
 */

require_once __DIR__ . '/config/bootstrap.php';

use App\Controllers\StorefrontController;

$storefrontController = new StorefrontController($database);
$hydratedState = $storefrontController->getHydratedStoreState();
$sessionUser = function_exists('currentUser') ? currentUser() : ($hydratedState['sessionUser'] ?? null);

require_once __DIR__ . '/src/views/storefront.php';
