<?php

namespace Config;

use App\Services\Audit\AuditService;
use App\Services\Auth\AuthService;
use App\Services\Marketplaces\MercadoLivre\Http\CurlTransport;
use App\Services\Marketplaces\MercadoLivre\Http\HttpTransport;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreService;
use App\Services\Pricing\PricingService;
use App\Services\Settings\SettingsService;
use App\Services\Suppliers\SupplierSelectionService;
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

    public static function mercadoLivre(bool $getShared = true): MercadoLivreService
    {
        if ($getShared) {
            return static::getSharedInstance('mercadoLivre');
        }

        return new MercadoLivreService();
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
