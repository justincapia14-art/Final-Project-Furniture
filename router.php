<?php

declare(strict_types=1);

/**
 * PHP Built-in Web Server Router (`php -S 0.0.0.0:3000 router.php`)
 *
 * Routes all storefront, catalog, authentication, dashboard, and REST API
 * requests directly through the PHP 8.2 MVC controllers and views.
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$pathname = is_string($uri) ? rawurldecode($uri) : '/';

if ($pathname === '/api.php' || $pathname === '/src/api.php' || $pathname === '/api/store') {
    require __DIR__ . '/src/api.php';
    return true;
}

if ($pathname === '/logout.php' || $pathname === '/src/logout.php') {
    require __DIR__ . '/src/logout.php';
    return true;
}

$pageRoutes = [
    '/'               => __DIR__ . '/index.php',
    '/index.html'     => __DIR__ . '/index.php',
    '/index.php'      => __DIR__ . '/index.php',
    '/catalog.html'   => __DIR__ . '/catalog.php',
    '/catalog.php'    => __DIR__ . '/catalog.php',
    '/login.html'     => __DIR__ . '/login.php',
    '/login.php'      => __DIR__ . '/login.php',
    '/src/index.php'  => __DIR__ . '/login.php',
    '/dashboard.html' => __DIR__ . '/dashboard.php',
    '/dashboard.php'  => __DIR__ . '/dashboard.php',
    '/src/home.php'   => __DIR__ . '/dashboard.php',
];

if (isset($pageRoutes[$pathname])) {
    require $pageRoutes[$pathname];
    return true;
}

$fullPath = realpath(__DIR__ . '/' . ltrim($pathname, '/'));
if ($fullPath !== false && str_starts_with($fullPath, __DIR__) && is_file($fullPath)) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
return true;
