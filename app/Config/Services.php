<?php

namespace Config;

use App\Services\Audit\AuditService;
use App\Services\Auth\AuthService;
use App\Services\Automation\JobQueue;
use App\Services\Automation\ListingAutoSync;
use App\Services\Automation\Scheduler;
use App\Services\Http\CurlTransport;
use App\Services\Http\HttpTransport;
use App\Services\Marketplaces\MarketplaceRegistry;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreNotificationService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreOrderService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreProductService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreService;
use App\Services\Orders\OrderService;
use App\Services\Pricing\PricingService;
use App\Services\Settings\SettingsService;
use App\Services\Suppliers\SupplierSelectionService;
use App\Services\Suppliers\SupplierSyncService;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 */
class Services extends BaseService
{
    public static function auth(bool $getShared = true): AuthService
    {
        if ($getShared) {
            return static::getSharedInstance('auth');
        }

        return new AuthService();
    }

    public static function audit(bool $getShared = true): AuditService
    {
        if ($getShared) {
            return static::getSharedInstance('audit');
        }

        return new AuditService();
    }

    public static function settings(bool $getShared = true): SettingsService
    {
        if ($getShared) {
            return static::getSharedInstance('settings');
        }

        return new SettingsService();
    }

    public static function pricing(bool $getShared = true): PricingService
    {
        if ($getShared) {
            return static::getSharedInstance('pricing');
        }

        return new PricingService();
    }

    public static function supplierSelection(bool $getShared = true): SupplierSelectionService
    {
        if ($getShared) {
            return static::getSharedInstance('supplierSelection');
        }

        return new SupplierSelectionService();
    }

    public static function marketplaces(bool $getShared = true): MarketplaceRegistry
    {
        if ($getShared) {
            return static::getSharedInstance('marketplaces');
        }

        return new MarketplaceRegistry();
    }

    public static function mercadoLivre(bool $getShared = true): MercadoLivreService
    {
        if ($getShared) {
            return static::getSharedInstance('mercadoLivre');
        }

        return new MercadoLivreService();
    }

    public static function mercadoLivreProducts(bool $getShared = true): MercadoLivreProductService
    {
        if ($getShared) {
            return static::getSharedInstance('mercadoLivreProducts');
        }

        return new MercadoLivreProductService();
    }

    public static function mercadoLivreOrders(bool $getShared = true): MercadoLivreOrderService
    {
        if ($getShared) {
            return static::getSharedInstance('mercadoLivreOrders');
        }

        return new MercadoLivreOrderService();
    }

    public static function mercadoLivreNotifications(bool $getShared = true): MercadoLivreNotificationService
    {
        if ($getShared) {
            return static::getSharedInstance('mercadoLivreNotifications');
        }

        return new MercadoLivreNotificationService();
    }

    public static function orders(bool $getShared = true): OrderService
    {
        if ($getShared) {
            return static::getSharedInstance('orders');
        }

        return new OrderService();
    }

    public static function supplierSync(bool $getShared = true): SupplierSyncService
    {
        if ($getShared) {
            return static::getSharedInstance('supplierSync');
        }

        return new SupplierSyncService();
    }

    public static function jobQueue(bool $getShared = true): JobQueue
    {
        if ($getShared) {
            return static::getSharedInstance('jobQueue');
        }

        return new JobQueue();
    }

    public static function scheduler(bool $getShared = true): Scheduler
    {
        if ($getShared) {
            return static::getSharedInstance('scheduler');
        }

        return new Scheduler();
    }

    public static function listingAutoSync(bool $getShared = true): ListingAutoSync
    {
        if ($getShared) {
            return static::getSharedInstance('listingAutoSync');
        }

        return new ListingAutoSync();
    }

    /**
     * Transporte HTTP da integração; os testes injetam um falso via Services::injectMock().
     */
    public static function mercadoLivreTransport(bool $getShared = true): HttpTransport
    {
        if ($getShared) {
            return static::getSharedInstance('mercadoLivreTransport');
        }

        return new CurlTransport(config(MercadoLivre::class)->timeoutSeconds);
    }
}
