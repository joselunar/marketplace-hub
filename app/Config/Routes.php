<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', static fn () => redirect()->to('/dashboard'));

// Autenticação
$routes->get('login', 'AuthController::login', ['filter' => 'guest', 'as' => 'login']);
$routes->post('login', 'AuthController::attempt', ['filter' => ['guest', 'loginthrottle']]);
$routes->post('logout', 'AuthController::logout', ['filter' => 'auth']);

// Painel administrativo
$routes->group('', ['filter' => 'auth'], static function (RouteCollection $routes): void {
    $routes->get('dashboard', 'DashboardController::index');

    $routes->group('suppliers', static function (RouteCollection $routes): void {
        $routes->get('/', 'SupplierController::index');
        $routes->get('new', 'SupplierController::new');
        $routes->post('/', 'SupplierController::create');
        $routes->get('(:num)', 'SupplierController::show/$1');
        $routes->get('(:num)/edit', 'SupplierController::edit/$1');
        $routes->post('(:num)', 'SupplierController::update/$1');
        $routes->post('(:num)/test-connection', 'SupplierController::testConnection/$1');
        $routes->post('(:num)/delete', 'SupplierController::delete/$1', ['filter' => 'role:admin']);
    });

    $routes->group('categories', static function (RouteCollection $routes): void {
        $routes->get('/', 'CategoryController::index');
        $routes->get('new', 'CategoryController::new');
        $routes->post('/', 'CategoryController::create');
        $routes->get('(:num)/edit', 'CategoryController::edit/$1');
        $routes->post('(:num)', 'CategoryController::update/$1');
        $routes->post('(:num)/delete', 'CategoryController::delete/$1', ['filter' => 'role:admin']);
    });

    $routes->group('products', static function (RouteCollection $routes): void {
        $routes->get('/', 'ProductController::index');
        $routes->get('new', 'ProductController::new');
        $routes->post('/', 'ProductController::create');
        $routes->get('(:num)', 'ProductController::show/$1');
        $routes->get('(:num)/edit', 'ProductController::edit/$1');
        $routes->post('(:num)', 'ProductController::update/$1');
        $routes->post('(:num)/selection', 'ProductController::recalculateSelection/$1');
        $routes->post('(:num)/delete', 'ProductController::delete/$1', ['filter' => 'role:admin']);
    });

    $routes->get('orders', 'OrderController::index');
    $routes->get('orders/(:num)', 'OrderController::show/$1');
    $routes->group('orders', ['filter' => 'marketplacethrottle'], static function (RouteCollection $routes): void {
        $routes->post('import', 'OrderController::import');
        $routes->post('(:num)/sync', 'OrderController::sync/$1');
        $routes->post('(:num)/reallocate', 'OrderController::reallocate/$1');
        $routes->post('supplier-orders/(:num)/send', 'OrderController::sendToSupplier/$1');
        $routes->post('supplier-orders/(:num)', 'OrderController::updateSupplierOrder/$1');
    });

    $routes->get('sync', 'SyncController::index');
    $routes->group('sync', ['filter' => 'marketplacethrottle'], static function (RouteCollection $routes): void {
        $routes->post('tasks/(:segment)/run', 'SyncController::runTask/$1');
        $routes->post('jobs/(:num)/retry', 'SyncController::retry/$1');
        $routes->post('jobs/(:num)/cancel', 'SyncController::cancel/$1');
        $routes->post('jobs/retry-failed', 'SyncController::retryFailed', ['filter' => 'role:admin']);
        $routes->post('jobs/cancel-pending', 'SyncController::cancelPending', ['filter' => 'role:admin']);
    });

    $routes->get('pricing', 'PricingController::index');
    $routes->get('pricing/simulate', 'PricingController::simulate');

    $routes->group('offers', static function (RouteCollection $routes): void {
        $routes->get('/', 'SupplierOfferController::index');
        $routes->get('new', 'SupplierOfferController::new');
        $routes->post('/', 'SupplierOfferController::create');
        $routes->get('(:num)/edit', 'SupplierOfferController::edit/$1');
        $routes->post('(:num)', 'SupplierOfferController::update/$1');
        $routes->post('(:num)/link', 'SupplierOfferController::link/$1');
        $routes->post('(:num)/delete', 'SupplierOfferController::delete/$1', ['filter' => 'role:admin']);
    });

    $routes->group('imports', static function (RouteCollection $routes): void {
        $routes->get('/', 'SupplierImportController::index');
        $routes->get('new', 'SupplierImportController::new');
        $routes->get('template', 'SupplierImportController::template');
        $routes->post('/', 'SupplierImportController::create');
        $routes->get('(:num)', 'SupplierImportController::show/$1');
    });

    $routes->get('marketplaces', 'MarketplaceController::index');
    $routes->post('marketplaces/(:segment)/fees', 'MarketplaceController::updateFees/$1', ['filter' => 'role:admin']);

    $routes->group('marketplaces/mercadolivre', static function (RouteCollection $routes): void {
        $routes->get('/', 'MercadoLivreController::index');
        $routes->get('categories', 'MercadoLivreCategoryController::index');

        $routes->get('listings', 'MercadoLivreListingController::index');
        $routes->get('listings/new', 'MercadoLivreListingController::new');
        $routes->get('listings/(:num)', 'MercadoLivreListingController::show/$1');
        $routes->group('listings', ['filter' => 'marketplacethrottle'], static function (RouteCollection $routes): void {
            $routes->post('/', 'MercadoLivreListingController::create');
            $routes->post('(:num)/sync', 'MercadoLivreListingController::sync/$1');
            $routes->post('(:num)/price', 'MercadoLivreListingController::updatePrice/$1');
            $routes->post('(:num)/stock', 'MercadoLivreListingController::updateStock/$1');
            $routes->post('(:num)/automation', 'MercadoLivreListingController::enableAutomation/$1');
            $routes->post('(:num)/automation/disable', 'MercadoLivreListingController::disableAutomation/$1');
            $routes->post('(:num)/pause', 'MercadoLivreListingController::pause/$1');
            $routes->post('(:num)/activate', 'MercadoLivreListingController::activate/$1');
        });

        $routes->get('notifications', 'MercadoLivreNotificationController::index');
        $routes->group('notifications', ['filter' => 'marketplacethrottle'], static function (RouteCollection $routes): void {
            $routes->post('process', 'MercadoLivreNotificationController::process');
            $routes->post('(:num)/retry', 'MercadoLivreNotificationController::retry/$1');
        });

        // Conectar, renovar e desconectar contas mexe em credenciais: só administradores.
        $routes->group('', ['filter' => 'role:admin'], static function (RouteCollection $routes): void {
            $routes->post('connect', 'MercadoLivreController::connect');
            $routes->get('callback', 'MercadoLivreController::callback');
            $routes->post('accounts/(:num)/refresh', 'MercadoLivreController::refresh/$1');
            $routes->post('accounts/(:num)/disconnect', 'MercadoLivreController::disconnect/$1');
        });
    });

    // Somente administradores
    $routes->group('', ['filter' => 'role:admin'], static function (RouteCollection $routes): void {
        $routes->get('users', 'UserController::index');
        $routes->get('users/new', 'UserController::new');
        $routes->post('users', 'UserController::create');
        $routes->get('users/(:num)/edit', 'UserController::edit/$1');
        $routes->post('users/(:num)', 'UserController::update/$1');

        $routes->get('settings', 'SettingsController::index');
        $routes->post('settings', 'SettingsController::update');

        $routes->get('logs', 'LogController::index');
    });
});

// Notificações dos marketplaces (públicas; validadas no controller/service)
$routes->post('webhooks/mercadolivre', 'WebhookController::mercadolivre', ['filter' => 'webhookthrottle']);

// API interna (JSON, sessão autenticada)
$routes->group('api', ['namespace' => 'App\Controllers\Api', 'filter' => 'auth'], static function (RouteCollection $routes): void {
    $routes->get('products', 'ProductController::index');
    $routes->get('products/(:num)', 'ProductController::show/$1');
    $routes->get('products/(:num)/offers', 'ProductController::offers/$1');
    $routes->get('products/(:num)/selection', 'ProductController::selection/$1');
    $routes->get('products/(:num)/pricing', 'ProductController::pricing/$1');
    $routes->get('pricing/simulate', 'PricingController::simulate');
    $routes->get('suppliers', 'SupplierController::index');
    $routes->get('suppliers/(:num)', 'SupplierController::show/$1');
    $routes->get('marketplaces', 'MarketplaceController::index');
    $routes->get('marketplaces/accounts', 'MarketplaceAccountController::index');
    $routes->get('orders', 'OrderController::index');
    $routes->get('orders/(:num)', 'OrderController::show/$1');
});
