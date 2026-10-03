<?php

namespace App\Services\Marketplaces\MercadoLivre;

use App\DTO\MercadoLivre\ListingPreview;
use App\DTO\Pricing\CostInput;
use App\Exceptions\BusinessRuleException;
use App\Models\MarketplaceCategoryMappingModel;
use App\Models\MarketplaceListingEventModel as Events;
use App\Models\MarketplaceListingModel as Listings;
use App\Models\ProductModel;
use App\Models\SyncLogModel;
use App\Services\Audit\AuditService;
use App\Services\Pricing\PricingException;
use App\Services\Pricing\PricingService;
use App\Services\Settings\SettingsService;
use App\Services\Suppliers\SupplierSelectionService;
use App\Support\Decimal;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Anúncios do Mercado Livre: publicação confirmada pelo usuário e
 * manutenção (sincronizar, preço, estoque, pausar e reativar).
 *
 * Recursos usados (documentação "Publicar produtos", "User Products" e
 * "Sincronizar e modificar publicações"):
 * - POST /items e POST /items/{id}/description
 * - GET /items/{id}
 * - PUT /items/{id} com price, available_quantity ou status
 *
 * Cada operação fica no histórico do anúncio (marketplace_listing_events).
 */
class MercadoLivreProductService
{
    public const SETTING_AUTOMATIC_PUBLISH = 'marketplace.automatic_publish';

    private const DESCRIPTION_MAX = 50000;

    private readonly MercadoLivreService $meli;
    private readonly MercadoLivreListingPreviewService $previews;
    private readonly MercadoLivrePriceAutomationService $automation;

    public function __construct(
        ?MercadoLivreService $meli = null,
        ?MercadoLivreListingPreviewService $previews = null,
        private readonly ?SupplierSelectionService $selection = null,
        private readonly ?PricingService $pricing = null,
        private readonly ?SettingsService $settings = null,
        private readonly ?AuditService $audit = null,
        private readonly Listings $listings = new Listings(),
        private readonly Events $events = new Events(),
        private readonly ProductModel $products = new ProductModel(),
        private readonly MarketplaceCategoryMappingModel $mappings = new MarketplaceCategoryMappingModel(),
        private readonly SyncLogModel $syncLogs = new SyncLogModel(),
        ?MercadoLivrePriceAutomationService $automation = null,
    ) {
        $this->meli       = $meli ?? service('mercadoLivre');
        $this->previews   = $previews ?? new MercadoLivreListingPreviewService($this->meli, selection: $this->selection, pricing: $this->pricing);
        $this->automation = $automation ?? new MercadoLivrePriceAutomationService($this->meli, $this->previews, $this->selection, $this->settings, $this->audit, $this->listings, $this->events, $this->products);
    }

    public function previews(): MercadoLivreListingPreviewService
    {
        return $this->previews;
    }

    public function automation(): MercadoLivrePriceAutomationService
    {
        return $this->automation;
    }

    /**
     * Publicação automática fica para uma fase futura e começa desligada:
     * hoje toda publicação passa pela prévia e pela confirmação.
     */
    public function automaticPublishEnabled(): bool
    {
        return (bool) ($this->settings ?? service('settings'))->get(self::SETTING_AUTOMATIC_PUBLISH, false);
    }

    /**
     * Publica o anúncio revisado na prévia.
     *
     * O preço e o estoque confirmados pelo usuário precisam bater com os
     * recalculados agora; se mudaram (oferta, tarifa ou estoque), nada é
     * enviado e a prévia precisa ser revista.
     */
    public function publish(int $productId, array $input, string $confirmedPrice, int $confirmedQuantity, ?int $accountId, ?int $userId): array
    {
        $preview = $this->previews->preview($productId, $input, $accountId);

        if (! $preview->canPublish()) {
            throw new BusinessRuleException('A publicação tem pendências: ' . implode(' ', $preview->errors));
        }

        $confirmed = Decimal::normalize($confirmedPrice);
        if ($confirmed === null || bccomp($confirmed, (string) $preview->price(), 2) !== 0 || $confirmedQuantity !== $preview->quantity) {
            throw new BusinessRuleException(sprintf(
                'O preço ou o estoque mudaram desde a prévia (agora %s e %d unidade(s)). Revise e confirme de novo.',
                'R$ ' . number_format((float) $preview->price(), 2, ',', '.'),
                $preview->quantity,
            ));
        }

        $accountId = (int) $preview->account['id'];
        $listingId = $this->claim($preview, $userId);
        $startedAt = date('Y-m-d H:i:s');

        try {
            $item = $this->meli->post($accountId, '/items', $preview->payload);
        } catch (MercadoLivreApiException $e) {
            $message = $e->status === 0
                ? 'Sem resposta do Mercado Livre. Confira em "Minhas vendas" se o anúncio foi criado antes de publicar de novo.'
                : $e->userMessage();

            $this->listings->update($listingId, ['status' => Listings::STATUS_ERROR, 'last_error' => mb_substr($message, 0, 1000)]);
            $this->event($listingId, Events::ACTION_PUBLISH, Events::STATUS_ERROR, 'Publicação recusada: ' . $message, null, ['payload' => $preview->payload], $userId);
            $this->syncLog($accountId, 'listing_publish', SyncLogModel::STATUS_ERROR, 'Falha ao publicar o produto ' . $preview->product['sku'] . ': ' . $message, $startedAt);

            throw new BusinessRuleException($message, 0, $e);
        }

        $externalId = is_string($item['id'] ?? null) ? $item['id'] : null;
        if ($externalId === null) {
            $this->listings->update($listingId, ['status' => Listings::STATUS_ERROR, 'last_error' => 'O Mercado Livre não devolveu o ID do anúncio.']);

            throw new BusinessRuleException('O Mercado Livre não devolveu o ID do anúncio. Confira em "Minhas vendas" antes de publicar de novo.');
        }

        $now  = date('Y-m-d H:i:s');
        $data = $this->itemColumns($item) + [
            'last_error'          => null,
            'sale_fee_percentage' => $preview->fee?->percentageFee,
            'sale_fixed_fee'      => $preview->fee?->fixedFee,
            'sale_fee_amount'     => $preview->fee?->saleFeeAmount,
            'listing_fee_amount'  => $preview->fee?->listingFeeAmount,
            'published_by'        => $userId,
            'published_at'        => $now,
            'last_sync_at'        => $now,
        ];
        $this->listings->update($listingId, $data);

        $warnings = $this->sendDescription($accountId, $externalId, (string) ($preview->product['description'] ?? ''));
        $this->rememberCategory($preview);

        $message = sprintf('Anúncio %s criado (%s, %s).', $externalId, Listings::MODEL_LABELS[$preview->sellingModel], $preview->listingTypeId);
        $this->event($listingId, Events::ACTION_PUBLISH, $warnings === [] ? Events::STATUS_SUCCESS : Events::STATUS_WARNING, $message . ($warnings === [] ? '' : ' ' . implode(' ', $warnings)), null, [
            'external_listing_id' => $externalId,
            'user_product_id'     => $data['external_product_id'] ?? null,
            'price'               => $preview->price(),
            'available_quantity'  => $preview->quantity,
            'fee'                 => $preview->fee?->toArray(),
        ], $userId);
        $this->syncLog($accountId, 'listing_publish', SyncLogModel::STATUS_SUCCESS, $message, $startedAt, ['listing_id' => $listingId, 'product_id' => $productId]);
        $this->audit()->log('publish', 'marketplace_listing', $listingId, null, ['external_listing_id' => $externalId, 'product_id' => $productId, 'price' => $preview->price(), 'available_quantity' => $preview->quantity], $userId);

        if ($this->automation->enabled()) {
            try {
                $this->automation->enable($listingId, $userId);
            } catch (BusinessRuleException $e) {
                $warnings[] = 'Automação de preço não ativada: ' . $e->getMessage() . ' A conferência automática de preço tenta de novo.';
            }
        }

        return $this->findOrFail($listingId) + ['warnings' => $warnings];
    }

    /**
     * Atualiza o registro local com o estado atual do anúncio (GET /items/{id}).
     */
    public function sync(int $listingId, ?int $userId = null): array
    {
        $listing = $this->publishedOrFail($listingId);

        try {
            $item = $this->meli->get((int) $listing['marketplace_account_id'], '/items/' . rawurlencode($listing['external_listing_id']));
        } catch (MercadoLivreApiException $e) {
            $this->fail($listing, Events::ACTION_SYNC, $e, $userId);
        }

        $data    = $this->itemColumns($item) + ['last_sync_at' => date('Y-m-d H:i:s'), 'last_error' => null];
        $changes = $this->diff($listing, $data, ['title', 'price', 'available_quantity', 'status', 'sub_status', 'external_product_id']);
        $this->listings->update($listingId, $data);

        $warning = null;
        try {
            $automation = $this->automation->pull($listing);
            $changes += $this->diff($listing, $automation, ['price_automation_status', 'price_automation_min', 'price_automation_max', 'price_automation_cause']);
        } catch (MercadoLivreApiException $e) {
            $warning = ' Não foi possível ler a automação de preço: ' . $e->userMessage();
        }

        $this->event(
            $listingId,
            Events::ACTION_SYNC,
            $warning === null ? Events::STATUS_SUCCESS : Events::STATUS_WARNING,
            ($changes === [] ? 'Sem alterações.' : 'Dados atualizados a partir do Mercado Livre.') . ($warning ?? ''),
            array_intersect_key($listing, $changes),
            $changes,
            $userId,
        );

        return $this->findOrFail($listingId);
    }

    /**
     * Recalcula o preço com a tarifa oficial e o fornecedor principal atual e envia ao anúncio.
     *
     * Com automação de preço o Mercado Livre não aceita o preço; o que se
     * atualiza é a faixa da automação. Na conferência automática, anúncios
     * ainda sem automação tentam ativá-la (com a configuração ligada).
     *
     * @param bool $skipUnchanged não registra evento quando o preço já está correto (conferência automática)
     */
    public function updatePrice(int $listingId, ?int $userId = null, bool $skipUnchanged = false): array
    {
        $listing = $this->publishedOrFail($listingId);

        if (Listings::hasPriceAutomation($listing)) {
            return $this->automation->refresh($listingId, $userId, $skipUnchanged);
        }

        if ($skipUnchanged && $this->automation->enabled() && $listing['status'] === Listings::STATUS_ACTIVE) {
            try {
                return $this->automation->enable($listingId, $userId, quiet: true);
            } catch (BusinessRuleException) {
                // Anúncio ainda sem automação disponível: segue enviando o preço calculado.
            }
        }

        $product = $this->productOf($listing);
        $offer   = ($this->selection ?? service('supplierSelection'))->evaluate((int) $product['id'])->primary();

        if ($offer === null) {
            throw new BusinessRuleException('O produto está sem fornecedor elegível; o preço não foi alterado.');
        }

        $account = $this->meli->accounts()->findOrFail((int) $listing['marketplace_account_id']);
        [$fee, $breakdown, $error] = $this->previews->officialPrice($product, $account, $offer, $listing['category_id'], $listing['listing_type'], $listing['currency_id']);

        if ($error !== null || $breakdown === null) {
            throw new BusinessRuleException($error ?? 'Não foi possível calcular o preço.');
        }
        if ($breakdown->isBelowMinimum()) {
            throw new BusinessRuleException('Com a tarifa oficial a margem ficaria abaixo da mínima; o preço não foi alterado.');
        }
        if (bccomp($breakdown->price, (string) $listing['price'], 2) === 0) {
            if ($skipUnchanged) {
                return $listing;
            }
            $this->event($listingId, Events::ACTION_UPDATE_PRICE, Events::STATUS_SUCCESS, 'Preço já estava correto.', null, ['price' => $breakdown->price], $userId);

            return $this->findOrFail($listingId);
        }

        try {
            $item = $this->meli->put((int) $listing['marketplace_account_id'], '/items/' . rawurlencode($listing['external_listing_id']), ['price' => (float) $breakdown->price]);
        } catch (MercadoLivreApiException $e) {
            if ($e->errorCode === 'item.price.not_modifiable') {
                // Automação ligada direto no Mercado Livre: passa a ajustar a faixa dela.
                $this->automation->pull($listing);

                return $this->automation->refresh($listingId, $userId);
            }
            $this->fail($listing, Events::ACTION_UPDATE_PRICE, $e, $userId);
        }

        $applied = isset($item['price']) && is_numeric($item['price']) ? Decimal::round((string) $item['price'], 2) : $breakdown->price;
        $this->listings->update($listingId, [
            'price'               => $applied,
            'sale_fee_percentage' => $fee?->percentageFee,
            'sale_fixed_fee'      => $fee?->fixedFee,
            'sale_fee_amount'     => $fee?->saleFeeAmount,
            'listing_fee_amount'  => $fee?->listingFeeAmount,
            'last_sync_at'        => date('Y-m-d H:i:s'),
            'last_error'          => null,
        ]);

        // Com automação de preços ativa no Mercado Livre o preço enviado é ignorado.
        $ignored = bccomp($applied, $breakdown->price, 2) !== 0;
        $this->event(
            $listingId,
            Events::ACTION_UPDATE_PRICE,
            $ignored ? Events::STATUS_WARNING : Events::STATUS_SUCCESS,
            $ignored ? 'O Mercado Livre manteve outro preço (automação de preços ativa no anúncio?).' : 'Preço atualizado.',
            ['price' => $listing['price']],
            ['price' => $applied, 'requested' => $breakdown->price],
            $userId,
        );

        return $this->findOrFail($listingId);
    }

    /**
     * Envia o estoque seguro do fornecedor principal (zero se não houver fornecedor elegível).
     *
     * @param bool $skipUnchanged não chama a API quando a quantidade gravada já é a esperada (conferência automática)
     */
    public function updateStock(int $listingId, ?int $userId = null, bool $skipUnchanged = false): array
    {
        $listing  = $this->publishedOrFail($listingId);
        $product  = $this->productOf($listing);
        $offer    = ($this->selection ?? service('supplierSelection'))->evaluate((int) $product['id'])->primary();
        $quantity = max(0, $offer?->availableStock ?? 0);

        if ($skipUnchanged && $listing['available_quantity'] !== null && (int) $listing['available_quantity'] === $quantity) {
            return $listing;
        }

        try {
            $item = $this->meli->put((int) $listing['marketplace_account_id'], '/items/' . rawurlencode($listing['external_listing_id']), ['available_quantity' => $quantity]);
        } catch (MercadoLivreApiException $e) {
            $this->fail($listing, Events::ACTION_UPDATE_STOCK, $e, $userId);
        }

        $data = ['available_quantity' => $quantity, 'last_sync_at' => date('Y-m-d H:i:s'), 'last_error' => null];
        if ($item !== []) {
            $data = array_merge($data, array_intersect_key($this->itemColumns($item), array_flip(['status', 'sub_status', 'available_quantity'])));
        }
        $this->listings->update($listingId, $data);

        $message = $quantity === 0 ? 'Estoque zerado (sem fornecedor com estoque disponível); o Mercado Livre pausa o anúncio.' : 'Estoque atualizado.';
        $this->event($listingId, Events::ACTION_UPDATE_STOCK, Events::STATUS_SUCCESS, $message, ['available_quantity' => (int) $listing['available_quantity']], ['available_quantity' => $quantity], $userId);

        return $this->findOrFail($listingId);
    }

    public function pause(int $listingId, ?int $userId = null): array
    {
        return $this->changeStatus($listingId, Listings::STATUS_PAUSED, $userId);
    }

    public function activate(int $listingId, ?int $userId = null): array
    {
        return $this->changeStatus($listingId, Listings::STATUS_ACTIVE, $userId);
    }

    public function findOrFail(int $listingId): array
    {
        $listing = $this->listings->withDetails()->where('marketplace_listings.id', $listingId)->first();

        if ($listing === null) {
            throw new BusinessRuleException('Anúncio não encontrado.');
        }

        return $listing;
    }

    /**
     * Margem atual de cada anúncio: preço do anúncio contra o custo do
     * fornecedor principal de hoje, com a tarifa gravada na publicação.
     *
     * @param list<array<string, mixed>> $rows linhas de withDetails()
     *
     * @return list<array<string, mixed>>
     */
    public function withMargins(array $rows): array
    {
        $pricing  = $this->pricing ?? service('pricing');
        $products = array_map(static fn (array $row): array => [
            'id'                        => (int) $row['product_id'],
            'category_id'               => $row['product_category_id'],
            'desired_margin_percentage' => $row['desired_margin_percentage'],
            'minimum_margin_percentage' => $row['minimum_margin_percentage'],
            'sale_price'                => $row['sale_price'],
            'extra_cost'                => $row['extra_cost'],
        ], array_filter($rows, static fn (array $row): bool => $row['product_id'] !== null));
        $contexts = $pricing->contexts($products);

        foreach ($rows as &$row) {
            $row['margin']        = null;
            $row['below_minimum'] = false;
            $context              = $contexts[(int) $row['product_id']] ?? null;

            if ($context === null || $row['cost_price'] === null || $row['sale_fee_percentage'] === null) {
                continue;
            }

            try {
                $config    = $context->config->withMarketplaceFees((string) $row['sale_fee_percentage'], (string) ($row['sale_fixed_fee'] ?? '0.00'));
                $breakdown = $pricing->calculator()->at((string) $row['price'], new CostInput((string) $row['cost_price'], (string) ($row['shipping_cost'] ?? '0.00'), $context->productExtraCost), $context->margins, $config);
            } catch (PricingException) {
                continue;
            }

            $row['margin']        = $breakdown->marginPercentage;
            $row['below_minimum'] = $breakdown->isBelowMinimum();
        }
        unset($row);

        return $rows;
    }

    public function history(int $listingId): array
    {
        return $this->events->forListing($listingId);
    }

    private function changeStatus(int $listingId, string $status, ?int $userId): array
    {
        $listing = $this->publishedOrFail($listingId);
        $action  = $status === Listings::STATUS_PAUSED ? Events::ACTION_PAUSE : Events::ACTION_ACTIVATE;
        $from    = $status === Listings::STATUS_PAUSED ? Listings::STATUS_ACTIVE : Listings::STATUS_PAUSED;

        if ($listing['status'] !== $from) {
            throw new BusinessRuleException(sprintf(
                'Só é possível %s um anúncio %s (situação atual: %s). Sincronize se o status estiver desatualizado.',
                $status === Listings::STATUS_PAUSED ? 'pausar' : 'reativar',
                mb_strtolower(Listings::STATUS_LABELS[$from]),
                Listings::STATUS_LABELS[$listing['status']] ?? $listing['status'],
            ));
        }

        try {
            $item = $this->meli->put((int) $listing['marketplace_account_id'], '/items/' . rawurlencode($listing['external_listing_id']), ['status' => $status]);
        } catch (MercadoLivreApiException $e) {
            $this->fail($listing, $action, $e, $userId);
        }

        $data = ['status' => $status, 'last_sync_at' => date('Y-m-d H:i:s'), 'last_error' => null];
        if ($item !== []) {
            $data = array_merge($data, array_intersect_key($this->itemColumns($item), array_flip(['status', 'sub_status'])));
        }
        $this->listings->update($listingId, $data);

        $this->event($listingId, $action, Events::STATUS_SUCCESS, $status === Listings::STATUS_PAUSED ? 'Anúncio pausado.' : 'Anúncio reativado.', ['status' => $listing['status']], ['status' => $data['status']], $userId);

        return $this->findOrFail($listingId);
    }

    /**
     * Reserva a linha do anúncio com status "pending" antes de chamar a API.
     * A chave única (conta, produto) e a troca condicional de status impedem
     * duas publicações simultâneas do mesmo produto.
     */
    private function claim(ListingPreview $preview, ?int $userId): int
    {
        $data = [
            'marketplace_account_id' => (int) $preview->account['id'],
            'product_id'             => (int) $preview->product['id'],
            'selling_model'          => $preview->sellingModel,
            'title'                  => $preview->draft->title ?? $preview->familyName,
            'family_name'            => $preview->draft->familyName,
            'category_id'            => $preview->draft->categoryId,
            'listing_type'           => $preview->draft->listingTypeId,
            'item_condition'         => $preview->draft->condition,
            'currency_id'            => $preview->draft->currencyId,
            'price'                  => $preview->draft->price,
            'available_quantity'     => $preview->draft->quantity,
            'status'                 => Listings::STATUS_PENDING,
            'attributes'             => json_encode($preview->attributeValues, JSON_UNESCAPED_UNICODE),
            'last_error'             => null,
        ];

        if ($preview->existing !== null) {
            $claimed = $this->listings->builder()
                ->where('id', $preview->existing['id'])
                ->whereIn('status', [Listings::STATUS_ERROR, Listings::STATUS_CLOSED])
                ->set($data + ['external_listing_id' => null, 'external_product_id' => null, 'permalink' => null, 'sub_status' => null, 'updated_at' => date('Y-m-d H:i:s')])
                ->update();

            if (! $claimed || $this->listings->db->affectedRows() !== 1) {
                throw new BusinessRuleException('Outra publicação deste produto já está em andamento.');
            }

            return (int) $preview->existing['id'];
        }

        try {
            $inserted = $this->listings->insert($data);
        } catch (DatabaseException $e) {
            throw new BusinessRuleException('Outra publicação deste produto já está em andamento.', 0, $e);
        }

        if ($inserted === false) {
            throw new BusinessRuleException('Outra publicação deste produto já está em andamento.');
        }

        return (int) $this->listings->getInsertID();
    }

    /**
     * Colunas atualizadas a partir de um item devolvido pela API.
     */
    private function itemColumns(array $item): array
    {
        $subStatus = array_filter(is_array($item['sub_status'] ?? null) ? $item['sub_status'] : [], 'is_string');

        return array_filter([
            'external_listing_id' => is_string($item['id'] ?? null) ? $item['id'] : null,
            'external_product_id' => is_string($item['user_product_id'] ?? null) ? $item['user_product_id'] : null,
            'title'               => is_string($item['title'] ?? null) ? mb_substr($item['title'], 0, 255) : null,
            'family_name'         => is_string($item['family_name'] ?? null) ? mb_substr($item['family_name'], 0, 255) : null,
            'price'               => isset($item['price']) && is_numeric($item['price']) ? Decimal::round((string) $item['price'], 2) : null,
            'available_quantity'  => isset($item['available_quantity']) && is_numeric($item['available_quantity']) ? max(0, (int) $item['available_quantity']) : null,
            'status'              => is_string($item['status'] ?? null) ? $item['status'] : null,
            'permalink'           => is_string($item['permalink'] ?? null) ? mb_substr($item['permalink'], 0, 500) : null,
        ], static fn ($value): bool => $value !== null) + ['sub_status' => $subStatus === [] ? null : mb_substr(implode(',', $subStatus), 0, 255)];
    }

    /**
     * @return list<string> avisos (a descrição não impede o anúncio de existir)
     */
    private function sendDescription(int $accountId, string $externalId, string $description): array
    {
        $text = trim(html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '') {
            return [];
        }

        try {
            $this->meli->post($accountId, '/items/' . rawurlencode($externalId) . '/description', ['plain_text' => mb_substr($text, 0, self::DESCRIPTION_MAX)]);
        } catch (MercadoLivreApiException $e) {
            return ['A descrição não foi enviada: ' . $e->userMessage()];
        }

        return [];
    }

    /**
     * Guarda a categoria do Mercado Livre usada para a categoria interna do produto, se ainda não houver.
     */
    private function rememberCategory(ListingPreview $preview): void
    {
        $categoryId = $preview->product['category_id'] ?? null;
        if ($categoryId === null || $categoryId === '' || $preview->category === null) {
            return;
        }

        $marketplace = $this->meli->accounts()->marketplace(MercadoLivreAuthService::SLUG);
        if ($this->mappings->findFor((int) $marketplace['id'], (int) $categoryId) !== null) {
            return;
        }

        $this->mappings->insert([
            'marketplace_id'         => (int) $marketplace['id'],
            'category_id'            => (int) $categoryId,
            'external_category_id'   => $preview->category['id'],
            'external_category_name' => mb_substr((string) $preview->category['name'], 0, 255),
        ]);
    }

    private function publishedOrFail(int $listingId): array
    {
        $listing = $this->listings->find($listingId);

        if ($listing === null) {
            throw new BusinessRuleException('Anúncio não encontrado.');
        }
        if (empty($listing['external_listing_id'])) {
            throw new BusinessRuleException('O anúncio ainda não foi criado no Mercado Livre.');
        }

        return $listing;
    }

    private function productOf(array $listing): array
    {
        $product = $listing['product_id'] !== null ? $this->products->find((int) $listing['product_id']) : null;

        if ($product === null) {
            throw new BusinessRuleException('O produto deste anúncio foi excluído do catálogo.');
        }

        return $product;
    }

    /**
     * Registra a falha no histórico e devolve a mensagem ao usuário.
     */
    private function fail(array $listing, string $action, MercadoLivreApiException $e, ?int $userId): never
    {
        $message = $e->userMessage();

        $this->listings->update((int) $listing['id'], ['last_error' => mb_substr($message, 0, 1000)]);
        $this->event((int) $listing['id'], $action, Events::STATUS_ERROR, $message, null, null, $userId);
        $this->syncLog((int) $listing['marketplace_account_id'], 'listing_' . $action, SyncLogModel::STATUS_ERROR, 'Anúncio ' . $listing['external_listing_id'] . ': ' . $message);

        throw new BusinessRuleException($message, 0, $e);
    }

    /**
     * @param list<string> $fields
     */
    private function diff(array $before, array $after, array $fields): array
    {
        $changes = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $after) && (string) ($before[$field] ?? '') !== (string) ($after[$field] ?? '')) {
                $changes[$field] = $after[$field];
            }
        }

        return $changes;
    }

    private function event(int $listingId, string $action, string $status, string $message, ?array $old, ?array $new, ?int $userId): void
    {
        $this->events->insert([
            'marketplace_listing_id' => $listingId,
            'action'                 => $action,
            'status'                 => $status,
            'message'                => mb_substr($message, 0, 1000),
            'old_values'             => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            'new_values'             => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            'user_id'                => $userId ?? $this->audit()->currentUserId(),
        ]);
    }

    private function syncLog(int $accountId, string $action, string $status, string $message, ?string $startedAt = null, ?array $payload = null): void
    {
        $this->syncLogs->insert([
            'integration_type' => MercadoLivreAuthService::SYNC_INTEGRATION,
            'integration_id'   => $accountId,
            'action'           => $action,
            'status'           => $status,
            'message'          => mb_substr($message, 0, 1000),
            'payload'          => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'started_at'       => $startedAt ?? date('Y-m-d H:i:s'),
            'finished_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    private function audit(): AuditService
    {
        return $this->audit ?? service('audit');
    }
}
