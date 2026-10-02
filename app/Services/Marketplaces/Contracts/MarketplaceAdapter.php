<?php

namespace App\Services\Marketplaces\Contracts;

/**
 * Integração com um marketplace, vista pela automação, pelos pedidos e pela
 * tela de marketplaces. Cada marketplace tem o seu adaptador registrado em
 * Config\Marketplaces; telas específicas (conexão, publicação) continuam no
 * módulo do próprio marketplace.
 *
 * Operações fora das capacidades declaradas lançam UnsupportedMarketplaceOperation.
 */
interface MarketplaceAdapter
{
    public const CAPABILITY_TOKEN_REFRESH = 'token_refresh';
    public const CAPABILITY_LISTINGS      = 'listings';
    public const CAPABILITY_STOCK         = 'stock';
    public const CAPABILITY_PRICE         = 'price';
    public const CAPABILITY_ORDERS        = 'orders';
    public const CAPABILITY_NOTIFICATIONS = 'notifications';

    public const CAPABILITY_LABELS = [
        self::CAPABILITY_TOKEN_REFRESH => 'Renovação de token',
        self::CAPABILITY_LISTINGS      => 'Publicação de anúncios',
        self::CAPABILITY_STOCK         => 'Atualização de estoque',
        self::CAPABILITY_PRICE         => 'Atualização de preço',
        self::CAPABILITY_ORDERS        => 'Importação de pedidos',
        self::CAPABILITY_NOTIFICATIONS => 'Notificações',
    ];

    /**
     * Identificador gravado em marketplaces.slug e em webhook_events.provider.
     */
    public function slug(): string;

    public function name(): string;

    /**
     * @return list<string> constantes CAPABILITY_*
     */
    public function capabilities(): array;

    public function supports(string $capability): bool;

    /**
     * Credenciais do aplicativo presentes no .env.
     */
    public function isConfigured(): bool;

    /**
     * Nomes das variáveis de ambiente que faltam (nunca os valores).
     *
     * @return list<string>
     */
    public function missingSettings(): array;

    /**
     * Rotas das telas do marketplace (contas e anúncios), relativas ao site.
     */
    public function accountsPath(): string;

    public function listingsPath(): ?string;

    /**
     * Antecedência, em segundos, com que o token é renovado antes de vencer.
     */
    public function refreshBeforeSeconds(): int;

    /**
     * @return array<string, mixed> conta atualizada (com token_expires_at)
     */
    public function refreshToken(int $accountId, bool $force = false): array;

    /**
     * @return array<string, mixed> anúncio como está gravado
     */
    public function findListing(int $listingId): array;

    /**
     * Envia o estoque calculado ao anúncio. Com $skipUnchanged, não chama a
     * API quando o valor gravado já é o correto.
     *
     * @return array<string, mixed> anúncio atualizado
     */
    public function updateListingStock(int $listingId, ?int $userId = null, bool $skipUnchanged = false): array;

    /**
     * @return array<string, mixed> anúncio atualizado
     */
    public function updateListingPrice(int $listingId, ?int $userId = null, bool $skipUnchanged = false): array;

    /**
     * Relê um pedido no marketplace.
     *
     * @return array<string, mixed>
     */
    public function importOrder(int $accountId, string $externalOrderId): array;

    /**
     * @return array{created: int, updated: int, unchanged: int, failed: int, errors: list<string>}
     */
    public function importRecentOrders(int $accountId, int $days, ?int $userId = null): array;

    /**
     * Notificações recebidas e ainda não processadas.
     */
    public function pendingNotifications(): int;

    /**
     * @return array{processed: int, failed: int, retry: int}
     */
    public function processNotifications(int $limit): array;
}
