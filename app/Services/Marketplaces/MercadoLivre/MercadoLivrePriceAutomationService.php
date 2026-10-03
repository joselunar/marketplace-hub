<?php

namespace App\Services\Marketplaces\MercadoLivre;

use App\DTO\MercadoLivre\ListingFee;
use App\Exceptions\BusinessRuleException;
use App\Models\MarketplaceListingEventModel as Events;
use App\Models\MarketplaceListingModel as Listings;
use App\Models\ProductModel;
use App\Services\Audit\AuditService;
use App\Services\Settings\SettingsService;
use App\Services\Suppliers\SupplierSelectionService;
use App\Support\Decimal;

/**
 * Automação de preços do Mercado Livre (documentação "Automatizações de preços").
 *
 * O Mercado Livre acompanha os anúncios concorrentes e ajusta o preço
 * sozinho dentro de uma faixa. O sistema só define a faixa: o piso é o
 * preço da margem mínima e o teto o da margem desejada, sempre com a
 * tarifa oficial. Quando o custo do fornecedor muda, a faixa é refeita.
 *
 * Recursos usados:
 * - GET /pricing-automation/items/{id}/rules
 * - POST, GET, PUT e DELETE /pricing-automation/items/{id}/automation
 *
 * Com a automação ativa o Mercado Livre recusa PUT /items com o preço
 * (erro item.price.not_modifiable), então o preço deixa de ser enviado.
 */
class MercadoLivrePriceAutomationService
{
    public const SETTING_ENABLED = 'marketplace.price_automation';

    /**
     * "Preço para ganhar no Mercado Livre": compara só com anúncios do próprio site.
     */
    public const RULE = 'INT';

    /**
     * Erros em que o anúncio simplesmente não tem (ou não aceita) automação.
     */
    private const NO_AUTOMATION = ['automation_not_found', 'item_not_automatizable', 'automation_operation_not_allowed', 'item_not_catalog'];

    private readonly MercadoLivreService $meli;
    private readonly MercadoLivreListingPreviewService $previews;

    public function __construct(
        ?MercadoLivreService $meli = null,
        ?MercadoLivreListingPreviewService $previews = null,
        private readonly ?SupplierSelectionService $selection = null,
        private readonly ?SettingsService $settings = null,
        private readonly ?AuditService $audit = null,
        private readonly Listings $listings = new Listings(),
        private readonly Events $events = new Events(),
        private readonly ProductModel $products = new ProductModel(),
    ) {
        $this->meli     = $meli ?? service('mercadoLivre');
        $this->previews = $previews ?? new MercadoLivreListingPreviewService($this->meli, selection: $this->selection);
    }

    public function enabled(): bool
    {
        return ($this->settings ?? service('settings'))->getBool(self::SETTING_ENABLED);
    }

    /**
     * Atribui a regra ao anúncio com a faixa calculada agora.
     *
     * @param bool $quiet não registra no histórico quando o anúncio ainda não aceita automação
     *                    (anúncio novo, sem concorrentes); usado nas tentativas automáticas
     *
     * @throws BusinessRuleException
     */
    public function enable(int $listingId, ?int $userId = null, bool $quiet = false): array
    {
        $listing = $this->publishedOrFail($listingId);

        if (Listings::hasPriceAutomation($listing)) {
            return $this->refresh($listingId, $userId);
        }

        [$range, $fee] = $this->range($listing);
        $path          = $this->path($listing, '/automation');
        $body          = ['rule_id' => self::RULE, 'min_price' => (float) $range['min'], 'max_price' => (float) $range['max']];

        try {
            $rules = $this->meli->get((int) $listing['marketplace_account_id'], $this->path($listing, '/rules'));
            if (! in_array(self::RULE, array_column(array_filter($rules['rules'] ?? [], 'is_array'), 'rule_id'), true)) {
                $this->unavailable($listing, 'O Mercado Livre não oferece a regra "Preço para ganhar" para este anúncio.', $quiet, $userId);
            }

            try {
                $automation = $this->meli->post((int) $listing['marketplace_account_id'], $path, $body);
            } catch (MercadoLivreApiException $e) {
                if ($e->errorCode !== 'automation_already_created') {
                    throw $e;
                }
                $automation = $this->meli->put((int) $listing['marketplace_account_id'], $path, $body);
            }
        } catch (MercadoLivreApiException $e) {
            if (in_array($e->errorCode, self::NO_AUTOMATION, true)) {
                $this->unavailable($listing, 'O Mercado Livre ainda não permite automação de preço neste anúncio (' . $e->getMessage() . ').', $quiet, $userId);
            }
            $this->fail($listing, $e, $userId);
        }

        $data = $this->columns($automation) + $this->feeColumns($fee);
        $this->listings->update($listingId, $data);

        $this->event($listingId, Events::STATUS_SUCCESS, sprintf(
            'Automação de preço ativada: o Mercado Livre ajusta o preço entre %s e %s.',
            $this->money($data['price_automation_min'] ?? $range['min']),
            $this->money($data['price_automation_max'] ?? $range['max']),
        ), null, ['rule_id' => self::RULE, 'min_price' => $range['min'], 'max_price' => $range['max']], $userId);
        $this->audit()->log('price_automation_enable', 'marketplace_listing', $listingId, null, ['min_price' => $range['min'], 'max_price' => $range['max']], $userId);

        return $this->listings->find($listingId);
    }

    /**
     * Recalcula a faixa (custo, fornecedor ou tarifa mudaram) e envia se mudou.
     *
     * @param bool $skipUnchanged não registra evento quando a faixa já está correta (conferência automática)
     *
     * @throws BusinessRuleException
     */
    public function refresh(int $listingId, ?int $userId = null, bool $skipUnchanged = false): array
    {
        $listing = $this->publishedOrFail($listingId);
        [$range, $fee] = $this->range($listing);

        $unchanged = bccomp($range['min'], (string) ($listing['price_automation_min'] ?? '0'), 2) === 0
            && bccomp($range['max'], (string) ($listing['price_automation_max'] ?? '0'), 2) === 0;

        if ($unchanged) {
            if (! $skipUnchanged) {
                $this->event($listingId, Events::STATUS_SUCCESS, 'Faixa da automação já estava correta.', null, $range, $userId);
            }

            return $listing;
        }

        try {
            $automation = $this->meli->put((int) $listing['marketplace_account_id'], $this->path($listing, '/automation'), [
                'rule_id'   => $listing['price_automation_rule'] ?: self::RULE,
                'min_price' => (float) $range['min'],
                'max_price' => (float) $range['max'],
            ]);
        } catch (MercadoLivreApiException $e) {
            if ($e->errorCode === 'automation_not_found') {
                $this->listings->update($listingId, $this->cleared());
                $this->event($listingId, Events::STATUS_WARNING, 'A automação de preço foi removida no Mercado Livre.', null, null, $userId);

                throw new BusinessRuleException('A automação de preço deste anúncio foi removida no Mercado Livre. Atualize o preço de novo para enviá-lo diretamente.', 0, $e);
            }
            $this->fail($listing, $e, $userId);
        }

        $this->listings->update($listingId, $this->columns($automation) + $this->feeColumns($fee));
        $this->event(
            $listingId,
            Events::STATUS_SUCCESS,
            sprintf('Faixa da automação atualizada: de %s a %s.', $this->money($range['min']), $this->money($range['max'])),
            ['min_price' => $listing['price_automation_min'], 'max_price' => $listing['price_automation_max']],
            $range,
            $userId,
        );

        return $this->listings->find($listingId);
    }

    /**
     * Remove a regra; o preço volta a ser enviado pelo sistema.
     */
    public function disable(int $listingId, ?int $userId = null): array
    {
        $listing = $this->publishedOrFail($listingId);

        try {
            $this->meli->delete((int) $listing['marketplace_account_id'], $this->path($listing, '/automation'));
        } catch (MercadoLivreApiException $e) {
            if ($e->errorCode !== 'automation_not_found') {
                $this->fail($listing, $e, $userId);
            }
        }

        $this->listings->update($listingId, $this->cleared());
        $this->event($listingId, Events::STATUS_SUCCESS, 'Automação de preço desativada. O preço volta a ser enviado pelo sistema.', [
            'min_price' => $listing['price_automation_min'],
            'max_price' => $listing['price_automation_max'],
        ], null, $userId);
        $this->audit()->log('price_automation_disable', 'marketplace_listing', $listingId, null, null, $userId);

        return $this->listings->find($listingId);
    }

    /**
     * Lê a automação atual no Mercado Livre e grava no anúncio.
     *
     * @return array<string, mixed> colunas gravadas
     *
     * @throws MercadoLivreApiException em erros que não sejam "sem automação"
     */
    public function pull(array $listing): array
    {
        try {
            $automation = $this->meli->get((int) $listing['marketplace_account_id'], $this->path($listing, '/automation'));
            $data       = $this->columns($automation);
        } catch (MercadoLivreApiException $e) {
            if (! in_array($e->errorCode, self::NO_AUTOMATION, true)) {
                throw $e;
            }
            $data = $this->cleared();
        }

        $this->listings->update((int) $listing['id'], $data);

        return $data;
    }

    /**
     * @return array{0: array{min: string, max: string}, 1: ?ListingFee}
     */
    private function range(array $listing): array
    {
        $product = $listing['product_id'] !== null ? $this->products->find((int) $listing['product_id']) : null;
        if ($product === null) {
            throw new BusinessRuleException('O produto deste anúncio foi excluído do catálogo.');
        }

        $offer = ($this->selection ?? service('supplierSelection'))->evaluate((int) $product['id'])->primary();
        if ($offer === null) {
            throw new BusinessRuleException('O produto está sem fornecedor elegível; a automação de preço não foi alterada.');
        }

        $account = $this->meli->accounts()->findOrFail((int) $listing['marketplace_account_id']);
        $range   = $this->previews->automationRange($product, $account, $offer, $listing['category_id'], $listing['listing_type'], $listing['currency_id']);

        return [['min' => $range['min']->price, 'max' => $range['max']->price], $range['fee']];
    }

    /**
     * Colunas a partir da resposta da API. Valores fora do formato são descartados.
     */
    private function columns(array $automation): array
    {
        $status = is_string($automation['status'] ?? null) ? strtoupper($automation['status']) : null;
        $rule   = $automation['item_rule']['rule_id'] ?? null;
        $cause  = $automation['status_detail']['cause'] ?? null;

        return [
            'price_automation_status'    => array_key_exists((string) $status, Listings::AUTOMATION_LABELS) ? $status : Listings::AUTOMATION_ACTIVE,
            'price_automation_rule'      => is_string($rule) ? mb_substr($rule, 0, 20) : self::RULE,
            'price_automation_min'       => isset($automation['min_price']) && is_numeric($automation['min_price']) ? Decimal::round((string) $automation['min_price'], 2) : null,
            'price_automation_max'       => isset($automation['max_price']) && is_numeric($automation['max_price']) ? Decimal::round((string) $automation['max_price'], 2) : null,
            'price_automation_cause'     => is_string($cause) ? mb_substr($cause, 0, 30) : null,
            'price_automation_synced_at' => date('Y-m-d H:i:s'),
            'last_error'                 => null,
        ];
    }

    private function feeColumns(?ListingFee $fee): array
    {
        return $fee === null ? [] : [
            'sale_fee_percentage' => $fee->percentageFee,
            'sale_fixed_fee'      => $fee->fixedFee,
        ];
    }

    private function cleared(): array
    {
        return [
            'price_automation_status'    => null,
            'price_automation_rule'      => null,
            'price_automation_min'       => null,
            'price_automation_max'       => null,
            'price_automation_cause'     => null,
            'price_automation_synced_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function unavailable(array $listing, string $message, bool $quiet, ?int $userId): never
    {
        if (! $quiet) {
            $this->event((int) $listing['id'], Events::STATUS_WARNING, $message, null, null, $userId);
        }

        throw new BusinessRuleException($message);
    }

    private function fail(array $listing, MercadoLivreApiException $e, ?int $userId): never
    {
        $message = $e->userMessage();

        $this->listings->update((int) $listing['id'], ['last_error' => mb_substr($message, 0, 1000)]);
        $this->event((int) $listing['id'], Events::STATUS_ERROR, $message, null, null, $userId);

        throw new BusinessRuleException($message, 0, $e);
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

    private function path(array $listing, string $resource): string
    {
        return '/pricing-automation/items/' . rawurlencode($listing['external_listing_id']) . $resource;
    }

    private function money(string $value): string
    {
        return 'R$ ' . number_format((float) $value, 2, ',', '.');
    }

    private function event(int $listingId, string $status, string $message, ?array $old, ?array $new, ?int $userId): void
    {
        $this->events->insert([
            'marketplace_listing_id' => $listingId,
            'action'                 => Events::ACTION_AUTOMATION,
            'status'                 => $status,
            'message'                => mb_substr($message, 0, 1000),
            'old_values'             => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            'new_values'             => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            'user_id'                => $userId ?? $this->audit()->currentUserId(),
        ]);
    }

    private function audit(): AuditService
    {
        return $this->audit ?? service('audit');
    }
}
