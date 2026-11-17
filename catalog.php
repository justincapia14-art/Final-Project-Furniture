<?php

declare(strict_types=1);

/**
 * Root PHP Catalog Entrypoint (`catalog.php`)
 */

require_once __DIR__ . '/config/bootstrap.php';

use App\Controllers\StorefrontController;

$storefrontController = new StorefrontController($database);
$hydratedState = $storefrontController->getHydratedStoreState();
$sessionUser = function_exists('currentUser') ? currentUser() : ($hydratedState['sessionUser'] ?? null);

require_once __DIR__ . '/src/views/catalog.php';
