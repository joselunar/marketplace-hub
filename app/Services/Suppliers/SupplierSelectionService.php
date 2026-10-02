<?php

namespace App\Services\Suppliers;

use App\DTO\Suppliers\OfferEvaluation;
use App\DTO\Suppliers\RankingConfig;
use App\DTO\Suppliers\SelectionResult;
use App\Exceptions\BusinessRuleException;
use App\Models\ProductModel;
use App\Models\SupplierOfferModel;
use App\Models\SupplierSelectionLogModel;
use App\Services\Audit\AuditService;
use App\Services\Pricing\PricingService;
use App\Services\Settings\SettingsService;
use App\Services\Suppliers\Selection\SupplierRanker;
use App\Services\Suppliers\Selection\SupplierReliabilityService;
use CodeIgniter\Database\BaseConnection;

/**
 * Motor de seleção de fornecedores.
 *
 * Avalia as ofertas de cada produto, grava o score/posição de cada uma e o
 * fornecedor principal no produto, junto com o preço e a margem calculados
 * para ele. Toda troca de fornecedor principal fica registrada em
 * supplier_selection_logs e na auditoria.
 */
class SupplierSelectionService
{
    public const TRIGGER_MANUAL   = 'manual';
    public const TRIGGER_OFFER    = 'offer';
    public const TRIGGER_PRODUCT  = 'product';
    public const TRIGGER_SUPPLIER = 'supplier';
    public const TRIGGER_IMPORT   = 'import';
    public const TRIGGER_SETTINGS = 'settings';
    public const TRIGGER_CATEGORY = 'category';
    public const TRIGGER_COMMAND  = 'command';
    public const TRIGGER_SEED     = 'seed';
    public const TRIGGER_ORDER    = 'order';

    public const TRIGGER_LABELS = [
        self::TRIGGER_MANUAL   => 'Recálculo manual',
        self::TRIGGER_OFFER    => 'Alteração de oferta',
        self::TRIGGER_PRODUCT  => 'Alteração do produto',
        self::TRIGGER_SUPPLIER => 'Alteração do fornecedor',
        self::TRIGGER_IMPORT   => 'Importação',
        self::TRIGGER_SETTINGS => 'Alteração de configurações',
        self::TRIGGER_CATEGORY => 'Alteração da categoria',
        self::TRIGGER_COMMAND  => 'Linha de comando',
        self::TRIGGER_SEED     => 'Dados de demonstração',
        self::TRIGGER_ORDER    => 'Pedido recebido',
    ];

    public const PRICE_STATUS_OK            = 'ok';
    public const PRICE_STATUS_BELOW_MINIMUM = 'below_minimum';
    public const PRICE_STATUS_INVALID       = 'invalid';

    /**
     * Colunas do produto que influenciam a seleção e o preço.
     */
    public const PRODUCT_FIELDS = [
        'category_id', 'safety_stock', 'desired_margin_percentage', 'minimum_margin_percentage', 'sale_price', 'extra_cost',
    ];

    private const CHUNK_SIZE = 200;

    private BaseConnection $db;

    public function __construct(
        private readonly ProductModel $products = new ProductModel(),
        private readonly SupplierOfferModel $offers = new SupplierOfferModel(),
        private readonly SupplierSelectionLogModel $logs = new SupplierSelectionLogModel(),
        private readonly SupplierRanker $ranker = new SupplierRanker(),
        private readonly SupplierReliabilityService $reliability = new SupplierReliabilityService(),
        private readonly ?SettingsService $settings = null,
        private readonly ?AuditService $audit = null,
        private readonly ?PricingService $pricing = null,
    ) {
        $this->db = $this->offers->db;
    }

    /**
     * Calcula o ranking sem gravar nada (usado na tela do produto e na API).
     *
     * @param array<string, mixed> $overrides valores ainda não gravados do produto (pré-validação do formulário)
     */
    public function evaluate(int $productId, array $overrides = []): SelectionResult
    {
        $product = array_merge($this->findProduct($productId), array_intersect_key($overrides, array_flip(self::PRODUCT_FIELDS)));

        return $this->rank($product);
    }

    /**
     * Recalcula e grava a seleção de um produto.
     *
     * @param array{offer_id: int, supplier_name: string, score: string|null}|null $removedOffer
     *        oferta principal que acabou de ser excluída (a FK já zerou selected_offer_id)
     */
    public function select(int $productId, string $trigger, ?int $userId = null, ?array $removedOffer = null): SelectionResult
    {
        $result = $this->rank($this->findProduct($productId));

        $this->persist($result, $trigger, $userId, $removedOffer);

        return $result;
    }

    /**
     * @param list<int> $productIds
     *
     * @return array{evaluated: int, changed: int}
     */
    public function selectForProducts(array $productIds, string $trigger, ?int $userId = null): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $stats      = ['evaluated' => 0, 'changed' => 0];

        if ($productIds === []) {
            return $stats;
        }

        $config        = $this->config();
        $reliability   = $this->reliability->scores($config->reliabilityWindowDays);
        $pricingConfig = $this->pricing()->config();

        foreach (array_chunk($productIds, self::CHUNK_SIZE) as $chunk) {
            $products = $this->products->select($this->productColumns())->whereIn('id', $chunk)->findAll();
            $offers   = $this->offersFor($chunk);
            $contexts = $this->pricing()->contexts($products, $pricingConfig);

            foreach ($products as $product) {
                $id     = (int) $product['id'];
                $result = $this->ranker->rank($product, $offers[$id] ?? [], $config, $reliability, $contexts[$id]);
                $stats['evaluated']++;

                if ($this->persist($result, $trigger, $userId)) {
                    $stats['changed']++;
                }
            }
        }

        return $stats;
    }

    /**
     * @return array{evaluated: int, changed: int}
     */
    public function selectForSupplier(int $supplierId, string $trigger, ?int $userId = null): array
    {
        $rows = $this->offers->builder()
            ->distinct()
            ->select('product_id')
            ->where('supplier_id', $supplierId)
            ->where('product_id IS NOT NULL', null, false)
            ->get()
            ->getResultArray();

        return $this->selectForProducts(array_column($rows, 'product_id'), $trigger, $userId);
    }

    /**
     * Produtos das categorias informadas (as margens são herdadas por elas).
     *
     * @param list<int> $categoryIds
     *
     * @return array{evaluated: int, changed: int}
     */
    public function selectForCategories(array $categoryIds, string $trigger, ?int $userId = null): array
    {
        if ($categoryIds === []) {
            return ['evaluated' => 0, 'changed' => 0];
        }

        $rows = $this->products->builder()
            ->select('id')
            ->whereIn('category_id', $categoryIds)
            ->where('EXISTS (' . ProductModel::HAS_OFFER_SQL . ')', null, false)
            ->get()
            ->getResultArray();

        return $this->selectForProducts(array_column($rows, 'id'), $trigger, $userId);
    }

    /**
     * Recalcula todos os produtos com ofertas vinculadas.
     *
     * @return array{evaluated: int, changed: int}
     */
    public function selectAll(string $trigger, ?int $userId = null): array
    {
        $rows = $this->offers->builder()
            ->distinct()
            ->select('product_id')
            ->where('product_id IS NOT NULL', null, false)
            ->get()
            ->getResultArray();

        return $this->selectForProducts(array_column($rows, 'product_id'), $trigger, $userId);
    }

    /**
     * Dados da oferta principal antes de ela ser excluída, para o histórico.
     *
     * @return array{product_id: int, offer_id: int, supplier_name: string, score: string|null}|null
     */
    public function snapshotIfSelected(int $offerId): ?array
    {
        $row = $this->db->table('products p')
            ->select('p.id AS product_id, so.id AS offer_id, so.selection_score, s.name AS supplier_name')
            ->join('supplier_offers so', 'so.id = p.selected_offer_id')
            ->join('suppliers s', 's.id = so.supplier_id')
            ->where('p.selected_offer_id', $offerId)
            ->get()
            ->getRowArray();

        if ($row === null) {
            return null;
        }

        return [
            'product_id'    => (int) $row['product_id'],
            'offer_id'      => (int) $row['offer_id'],
            'supplier_name' => (string) $row['supplier_name'],
            'score'         => $row['selection_score'],
        ];
    }

    /**
     * Limpa o resultado de avaliação de uma oferta que deixou de pertencer ao produto.
     */
    public function resetOffer(int $offerId): void
    {
        $this->db->table('supplier_offers')->where('id', $offerId)->update([
            'available_stock'      => null,
            'selection_score'      => null,
            'selection_rank'       => null,
            'ineligibility_reason' => null,
            'evaluated_at'         => null,
        ]);
    }

    public function history(int $productId, int $limit = 10): array
    {
        return $this->logs->forProduct($productId, $limit);
    }

    /**
     * Grava o ranking e registra a troca de fornecedor principal, se houver.
     *
     * @return bool true quando o fornecedor principal mudou
     */
    private function persist(SelectionResult $result, string $trigger, ?int $userId, ?array $removedOffer = null): bool
    {
        $now       = date('Y-m-d H:i:s');
        $primary   = $result->primary();
        $newId     = $primary?->offerId;
        $productId = $result->productId;

        $this->db->transException(true)->transStart();

        $locked     = $this->db->query('SELECT selected_offer_id FROM products WHERE id = ? FOR UPDATE', [$productId])->getRowArray();
        $previousId = isset($locked['selected_offer_id']) ? (int) $locked['selected_offer_id'] : null;
        $previous   = $previousId !== $newId || $removedOffer !== null
            ? $this->previousInfo($result, $previousId, $removedOffer)
            : null;

        foreach ($result->evaluations as $evaluation) {
            $this->db->table('supplier_offers')->where('id', $evaluation->offerId)->update([
                'available_stock'      => $evaluation->availableStock,
                'selection_score'      => $evaluation->score !== null ? number_format($evaluation->score, 2, '.', '') : null,
                'selection_rank'       => $evaluation->rank,
                'ineligibility_reason' => $evaluation->isEligible() ? null : mb_substr(implode('; ', $evaluation->ineligibilityReasons), 0, 500),
                'evaluated_at'         => $now,
            ]);
        }

        $this->db->table('products')->where('id', $productId)->update([
            'selected_offer_id'    => $newId,
            'selection_updated_at' => $now,
        ] + $this->pricingColumns($result, $now));

        $changed = $previousId !== $newId || ($previousId === null && $removedOffer !== null);

        if ($changed) {
            $this->recordChange($result, $previous, $trigger, $userId);
        }

        $this->db->transComplete();

        return $changed;
    }

    /**
     * Preço e margem gravados no produto a partir do fornecedor principal.
     * Sem principal, mas com oferta barrada só pela margem, grava a melhor
     * delas com status "abaixo da mínima" para o alerta do dashboard.
     */
    private function pricingColumns(SelectionResult $result, string $now): array
    {
        $primary  = $result->primary();
        $blocked  = $result->blockedOnlyByMargin();
        $invalid  = array_filter($result->evaluations, static fn (OfferEvaluation $e) => $e->marginBlocked && $e->pricing === null);

        [$breakdown, $status] = match (true) {
            $primary?->pricing !== null => [$primary->pricing, self::PRICE_STATUS_OK],
            $primary === null && $blocked !== [] => [$blocked[0]->pricing, self::PRICE_STATUS_BELOW_MINIMUM],
            $primary === null && $invalid !== [] => [null, self::PRICE_STATUS_INVALID],
            default => [null, null],
        };

        return [
            'suggested_price'    => $breakdown?->suggestedPrice,
            'minimum_price'      => $breakdown?->minimumPrice,
            'estimated_profit'   => $breakdown?->profit,
            'estimated_margin'   => $breakdown?->marginPercentage,
            'price_status'       => $status,
            'pricing_updated_at' => $status === null ? null : $now,
        ];
    }

    private function recordChange(SelectionResult $result, ?array $previous, string $trigger, ?int $userId): void
    {
        $primary = $result->primary();
        $reason  = $this->changeReason($result, $previous);

        $this->logs->insert([
            'product_id'             => $result->productId,
            'previous_offer_id'      => $previous['offer_id'] ?? null,
            'previous_supplier_name' => $previous['supplier_name'] ?? null,
            'previous_score'         => $previous['score'] ?? null,
            'new_offer_id'           => $primary?->offerId,
            'new_supplier_name'      => $primary?->supplierName,
            'new_score'              => $primary !== null ? number_format((float) $primary->score, 2, '.', '') : null,
            'reason'                 => mb_substr($reason, 0, 500),
            'trigger'                => $trigger,
            'user_id'                => $userId ?? $this->audit()->currentUserId(),
            'ranking'                => json_encode(array_map(static fn (OfferEvaluation $e) => [
                'offer_id' => $e->offerId,
                'supplier' => $e->supplierName,
                'score'    => $e->score,
                'rank'     => $e->rank,
                'reasons'  => $e->ineligibilityReasons,
            ], $result->evaluations), JSON_UNESCAPED_UNICODE),
        ]);

        $this->audit()->log(
            'supplier_change',
            'product',
            $result->productId,
            ['selected_offer_id' => $previous['offer_id'] ?? null, 'supplier' => $previous['supplier_name'] ?? null],
            ['selected_offer_id' => $primary?->offerId, 'supplier' => $primary?->supplierName, 'reason' => $reason, 'trigger' => $trigger],
            $userId,
        );
    }

    /**
     * @return array{offer_id: int, supplier_name: string, score: string|null, status: string}|null
     *         status: evaluated (ainda pertence ao produto), unlinked ou removed
     */
    private function previousInfo(SelectionResult $result, ?int $previousId, ?array $removedOffer): ?array
    {
        if ($previousId === null) {
            return $removedOffer === null ? null : [
                'offer_id'      => $removedOffer['offer_id'],
                'supplier_name' => $removedOffer['supplier_name'],
                'score'         => $removedOffer['score'],
                'status'        => 'removed',
            ];
        }

        $evaluation = $result->find($previousId);

        if ($evaluation !== null) {
            $old = $this->offers->builder()->select('selection_score')->where('id', $previousId)->get()->getRowArray();

            return [
                'offer_id'      => $previousId,
                'supplier_name' => $evaluation->supplierName,
                'score'         => $old['selection_score'] ?? null,
                'status'        => 'evaluated',
            ];
        }

        $row = $this->db->table('supplier_offers so')
            ->select('so.selection_score, s.name AS supplier_name')
            ->join('suppliers s', 's.id = so.supplier_id')
            ->where('so.id', $previousId)
            ->get()
            ->getRowArray();

        return [
            'offer_id'      => $previousId,
            'supplier_name' => (string) ($row['supplier_name'] ?? 'Oferta #' . $previousId),
            'score'         => $row['selection_score'] ?? null,
            'status'        => $row === null ? 'removed' : 'unlinked',
        ];
    }

    private function changeReason(SelectionResult $result, ?array $previous): string
    {
        $primary = $result->primary();

        if ($previous === null) {
            return $primary === null
                ? 'Nenhum fornecedor elegível.'
                : sprintf('Fornecedor principal definido: %s (score %s).', $primary->supplierName, $this->score($primary->score));
        }

        $previousEvaluation = $previous['status'] === 'evaluated' ? $result->find($previous['offer_id']) : null;

        $cause = match (true) {
            $previous['status'] === 'removed'   => sprintf('a oferta de %s foi excluída', $previous['supplier_name']),
            $previous['status'] === 'unlinked'  => sprintf('a oferta de %s foi desvinculada do produto', $previous['supplier_name']),
            $previousEvaluation !== null && ! $previousEvaluation->isEligible() => sprintf(
                '%s ficou inelegível (%s)',
                $previous['supplier_name'],
                implode('; ', $previousEvaluation->ineligibilityReasons),
            ),
            default => null,
        };

        if ($primary === null) {
            return 'Sem fornecedor elegível: ' . ($cause ?? 'nenhuma oferta atende aos critérios') . '.';
        }

        if ($cause !== null) {
            return sprintf('Troca automática para %s (score %s): %s.', $primary->supplierName, $this->score($primary->score), $cause);
        }

        return sprintf(
            '%s passou a ter o maior score (%s contra %s de %s).',
            $primary->supplierName,
            $this->score($primary->score),
            $this->score($previousEvaluation?->score),
            $previous['supplier_name'],
        );
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, list<array<string, mixed>>> por product_id
     */
    private function offersFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = $this->db->table('supplier_offers so')
            ->select('so.id, so.product_id, so.supplier_id, so.supplier_sku, so.cost_price, so.shipping_cost, so.stock_quantity, '
                . 'so.shipping_days, so.active, so.blocked, so.blocked_reason, '
                . 's.name AS supplier_name, s.status AS supplier_status, s.priority AS supplier_priority, '
                . 's.safety_stock AS supplier_safety_stock, s.default_shipping_days')
            ->join('suppliers s', 's.id = so.supplier_id')
            ->whereIn('so.product_id', $productIds)
            ->orderBy('so.id')
            ->get()
            ->getResultArray();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['product_id']][] = $row;
        }

        return $grouped;
    }

    private function findProduct(int $productId): array
    {
        $product = $this->products->select($this->productColumns())->find($productId);

        if ($product === null) {
            throw new BusinessRuleException('Produto não encontrado.');
        }

        return $product;
    }

    private function rank(array $product): SelectionResult
    {
        $config    = $this->config();
        $productId = (int) $product['id'];

        return $this->ranker->rank(
            $product,
            $this->offersFor([$productId])[$productId] ?? [],
            $config,
            $this->reliability->scores($config->reliabilityWindowDays),
            $this->pricing()->contextFor($product),
        );
    }

    private function productColumns(): string
    {
        return 'id, selected_offer_id, ' . implode(', ', self::PRODUCT_FIELDS);
    }

    private function config(): RankingConfig
    {
        return RankingConfig::fromSettings($this->settings ?? service('settings'));
    }

    private function pricing(): PricingService
    {
        return $this->pricing ?? service('pricing');
    }

    private function score(?float $score): string
    {
        return $score === null ? '—' : number_format($score, 1, ',', '.');
    }

    private function audit(): AuditService
    {
        return $this->audit ?? service('audit');
    }
}
