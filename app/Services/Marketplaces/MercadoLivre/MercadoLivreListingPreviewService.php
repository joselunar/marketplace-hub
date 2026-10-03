<?php

namespace App\Services\Marketplaces\MercadoLivre;

use App\DTO\MercadoLivre\ListingDraft;
use App\DTO\MercadoLivre\ListingFee;
use App\DTO\MercadoLivre\ListingPreview;
use App\DTO\Pricing\CostInput;
use App\DTO\Pricing\PriceBreakdown;
use App\DTO\Suppliers\OfferEvaluation;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\MarketplaceAccountModel;
use App\Models\MarketplaceCategoryMappingModel;
use App\Models\MarketplaceListingModel;
use App\Models\ProductImageModel;
use App\Models\ProductModel;
use App\Services\Pricing\PricingException;
use App\Services\Pricing\PricingService;
use App\Services\Suppliers\SupplierSelectionService;
use UnexpectedValueException;

/**
 * Prévia de publicação no Mercado Livre. Não grava nada.
 *
 * Segue o fluxo: produto → validações → fornecedor principal → estoque
 * seguro → preço com a tarifa oficial → categoria e atributos → corpo do
 * POST /items. Os problemas encontrados ficam em $errors e bloqueiam a
 * confirmação; nada é enviado sem o usuário revisar.
 */
class MercadoLivreListingPreviewService
{
    public const DEFAULT_LISTING_TYPE = 'gold_special';
    public const DEFAULT_CONDITION    = 'new';

    /**
     * Atributos mostrados mesmo quando não são obrigatórios na categoria.
     */
    private const SUGGESTED_ATTRIBUTES = ['BRAND', 'MODEL', 'GTIN'];

    private const MAX_ATTRIBUTE_LENGTH = 255;
    private const MAX_FAMILY_NAME      = 255;
    private const MAX_WARRANTY_LENGTH  = 100;

    /**
     * Palavra que o Mercado Livre não aceita em títulos (a publicação é moderada).
     */
    private const FORBIDDEN_TITLE_WORD = 'estoque';

    private readonly MercadoLivreService $meli;
    private readonly MercadoLivreCategoryService $categories;
    private readonly MercadoLivreFeeService $fees;

    public function __construct(
        ?MercadoLivreService $meli = null,
        ?MercadoLivreCategoryService $categories = null,
        ?MercadoLivreFeeService $fees = null,
        private readonly ?SupplierSelectionService $selection = null,
        private readonly ?PricingService $pricing = null,
        private readonly ProductModel $products = new ProductModel(),
        private readonly ProductImageModel $images = new ProductImageModel(),
        private readonly MarketplaceListingModel $listings = new MarketplaceListingModel(),
        private readonly MarketplaceCategoryMappingModel $mappings = new MarketplaceCategoryMappingModel(),
        private readonly ItemPayloadBuilder $builder = new ItemPayloadBuilder(),
    ) {
        $this->meli       = $meli ?? service('mercadoLivre');
        $this->categories = $categories ?? new MercadoLivreCategoryService($this->meli);
        $this->fees       = $fees ?? new MercadoLivreFeeService($this->meli);
    }

    public function fees(): MercadoLivreFeeService
    {
        return $this->fees;
    }

    /**
     * @param array<string, mixed> $input campos do formulário (categoria, tipo, título, atributos...)
     */
    public function preview(int $productId, array $input = [], ?int $accountId = null): ListingPreview
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new BusinessRuleException('Produto não encontrado.');
        }

        $account  = $this->meli->resolveAccount($accountId);
        $errors   = [];
        $warnings = [];

        if ($account['status'] !== MarketplaceAccountModel::STATUS_CONNECTED || (int) $account['active'] !== 1) {
            throw new BusinessRuleException('A conta "' . $account['name'] . '" não está conectada. Reconecte-a antes de publicar.');
        }

        $existing = $this->listings->where('marketplace_account_id', $account['id'])->where('product_id', $productId)->first();
        $errors   = array_merge($errors, $this->existingListingErrors($existing));

        if ((int) $product['active'] !== 1) {
            $errors[] = 'O produto está inativo.';
        }

        // Fornecedor principal e estoque seguro (já descontado o estoque de segurança).
        $selection = ($this->selection ?? service('supplierSelection'))->evaluate($productId);
        $offer     = $selection->primary();
        $quantity  = $offer?->availableStock ?? 0;

        if ($offer === null) {
            $reasons  = array_unique(array_merge(...array_map(static fn ($e): array => $e->ineligibilityReasons, $selection->evaluations)));
            $errors[] = 'Nenhum fornecedor elegível para o produto' . ($reasons !== [] ? ': ' . implode('; ', array_slice($reasons, 0, 3)) : ' (cadastre uma oferta).') . '.';
        } elseif ($quantity <= 0) {
            $errors[] = 'O fornecedor principal não tem estoque disponível depois do estoque de segurança.';
        }

        // Modelo de publicação: User Products para vendedores com a tag user_product_seller.
        $sellingModel = MarketplaceListingModel::MODEL_CLASSIC;
        try {
            if ($this->meli->seller($account)['user_products']) {
                $sellingModel = MarketplaceListingModel::MODEL_USER_PRODUCTS;
            }
        } catch (MercadoLivreApiException $e) {
            $errors[] = 'Não foi possível consultar o perfil do vendedor para definir o modelo de publicação: ' . $e->userMessage();
        }

        // Categoria: a escolhida, a do anúncio existente, o mapeamento da categoria interna ou o preditor.
        [$categoryId, $categorySource] = $this->chooseCategory($input, $existing, $product, $account);
        $predictions = [];
        if ($categorySource === null || $categorySource === 'predictor') {
            try {
                $predictions = $this->categories->predict($account, mb_substr((string) $product['name'], 0, 150));
            } catch (ValidationException|MercadoLivreApiException) {
                $predictions = [];
            }
            if ($categoryId === null && isset($predictions[0]['category_id']) && $predictions[0]['category_id'] !== '') {
                [$categoryId, $categorySource] = [$predictions[0]['category_id'], 'predictor'];
            }
        }

        $category   = null;
        $categoryOk = false;
        $attributes = [];
        if ($categoryId === null) {
            $errors[] = 'Escolha a categoria do Mercado Livre.';
        } else {
            try {
                $category = $this->categories->category($account, $categoryId);
                if (! $category['is_leaf'] || $category['settings']['listing_allowed'] === false) {
                    $errors[] = 'A categoria ' . $category['name'] . ' não aceita anúncios; escolha uma subcategoria.';
                } else {
                    $attributes = $this->categories->attributes($account, $category['id']);
                    $categoryOk = true;
                }
            } catch (BusinessRuleException $e) {
                $errors[] = $e->getMessage();
            } catch (MercadoLivreApiException $e) {
                $errors[] = 'Não foi possível ler a categoria ' . $categoryId . ': ' . $e->userMessage();
            }
        }

        $settings       = $category['settings'] ?? [];
        $maxTitleLength = $settings['max_title_length'] ?? null;
        $currencyId     = ($settings['currencies'] ?? [])[0] ?? null;

        if ($category !== null && $currencyId === null) {
            $errors[] = 'A categoria não informou a moeda aceita.';
        }

        // Atributos: obrigatórios da categoria + sugeridos, com valores do formulário ou do produto.
        [$shownAttributes, $attributeValues] = $this->resolveAttributes($attributes, $input, $existing, $product);
        foreach ($shownAttributes as $attribute) {
            if ($attribute['required'] && ($attributeValues[$attribute['id']] ?? '') === '') {
                $errors[] = 'Preencha o atributo obrigatório "' . $attribute['name'] . '".';
            }
        }

        // Tipo de anúncio disponível para a conta na categoria.
        $listingTypes  = [];
        $listingTypeId = null;
        if ($categoryOk) {
            try {
                $listingTypes = $this->fees->listingTypes($account, $category['id']);
            } catch (MercadoLivreApiException $e) {
                $errors[] = 'Não foi possível consultar os tipos de anúncio: ' . $e->userMessage();
            }

            $available     = array_column($listingTypes, 'id');
            $wanted        = $this->text($input['listing_type_id'] ?? null) ?? $existing['listing_type'] ?? null;
            $listingTypeId = match (true) {
                $wanted !== null && in_array($wanted, $available, true)            => $wanted,
                in_array(self::DEFAULT_LISTING_TYPE, $available, true)            => self::DEFAULT_LISTING_TYPE,
                default                                                           => $available[0] ?? null,
            };

            if ($listingTypes !== [] && $listingTypeId === null) {
                $errors[] = 'Nenhum tipo de anúncio disponível para a conta nesta categoria.';
            }
            if ($wanted !== null && $listingTypeId !== $wanted && $listingTypes !== []) {
                $warnings[] = 'O tipo de anúncio ' . $wanted . ' não está disponível; usando ' . $listingTypeId . '.';
            }
        }

        $conditions = $settings['item_conditions'] ?? [];
        $condition  = $this->text($input['condition'] ?? null) ?? $existing['item_condition'] ?? self::DEFAULT_CONDITION;
        if ($conditions !== [] && ! in_array($condition, $conditions, true)) {
            $errors[] = 'Condição "' . $condition . '" não aceita na categoria (aceitas: ' . implode(', ', $conditions) . ').';
        }

        // Preço com a tarifa oficial do Mercado Livre.
        $fee       = null;
        $breakdown = null;
        if ($offer !== null && $category !== null && $listingTypeId !== null && $currencyId !== null) {
            [$fee, $breakdown, $priceError] = $this->officialPrice($product, $account, $offer, $category['id'], $listingTypeId, $currencyId);
            if ($priceError !== null) {
                $errors[] = $priceError;
            } elseif ($breakdown !== null && $breakdown->isBelowMinimum()) {
                $errors[] = sprintf(
                    'Com a tarifa oficial a margem fica em %s%%, abaixo da mínima de %s%%.',
                    number_format((float) $breakdown->marginPercentage, 2, ',', '.'),
                    number_format((float) $breakdown->margins->minimum, 2, ',', '.'),
                );
            }

            $minimumPrice = $settings['minimum_price'] ?? null;
            if ($breakdown !== null && $minimumPrice !== null && is_numeric($minimumPrice) && bccomp($breakdown->price, (string) $minimumPrice, 2) < 0) {
                $errors[] = 'O preço fica abaixo do mínimo aceito pela categoria.';
            }
        }

        if ($this->meli->config()->logisticType === '' || $this->meli->config()->shippingMode === '') {
            $warnings[] = 'MELI_LOGISTIC_TYPE e MELI_SHIPPING_MODE não estão no .env: a tarifa fixa devolvida pelo Mercado Livre pode não coincidir com a cobrada.';
        }

        // Título (modelo clássico) ou family_name (User Products).
        $title      = $this->text($input['title'] ?? null) ?? $existing['title'] ?? (string) $product['name'];
        $familyName = $this->text($input['family_name'] ?? null) ?? $existing['family_name'] ?? (string) $product['name'];

        if ($sellingModel === MarketplaceListingModel::MODEL_CLASSIC) {
            $errors = array_merge($errors, $this->titleErrors($title, $maxTitleLength));
        } elseif (mb_strlen($familyName) > self::MAX_FAMILY_NAME) {
            $errors[] = 'O family_name pode ter no máximo ' . self::MAX_FAMILY_NAME . ' caracteres.';
        } elseif (mb_stripos($familyName, self::FORBIDDEN_TITLE_WORD) !== false) {
            $errors[] = 'O family_name não pode conter a palavra "estoque" (o título é gerado a partir dele).';
        }

        // Imagens: URLs públicas do produto, no limite da categoria.
        $pictures    = array_column($this->images->forProduct($productId), 'url');
        $maxPictures = $settings['max_pictures_per_item'] ?? null;
        if ($maxPictures !== null && count($pictures) > $maxPictures) {
            $warnings[] = 'A categoria aceita ' . $maxPictures . ' imagens; as demais não serão enviadas.';
            $pictures   = array_slice($pictures, 0, $maxPictures);
        }
        if ($pictures === [] && ($fee === null || $fee->requiresPicture)) {
            $errors[] = 'Cadastre ao menos uma imagem (URL pública) no produto.';
        }
        foreach ($pictures as $url) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            if (in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.local')) {
                $warnings[] = 'A imagem ' . $url . ' não é acessível pela internet; o Mercado Livre não conseguirá baixá-la.';
            }
        }

        $warrantyType = $this->text($input['warranty_type'] ?? null);
        $warrantyTime = $this->text($input['warranty_time'] ?? null);
        foreach (['Tipo de garantia' => $warrantyType, 'Tempo de garantia' => $warrantyTime] as $label => $value) {
            if ($value !== null && mb_strlen($value) > self::MAX_WARRANTY_LENGTH) {
                $errors[] = $label . ': no máximo ' . self::MAX_WARRANTY_LENGTH . ' caracteres.';
            }
        }

        $draft   = null;
        $payload = null;
        if ($category !== null && $listingTypeId !== null && $currencyId !== null && $breakdown !== null) {
            $draft = new ListingDraft(
                sellingModel: $sellingModel,
                categoryId: $category['id'],
                listingTypeId: $listingTypeId,
                condition: $condition,
                currencyId: $currencyId,
                price: $breakdown->price,
                quantity: max(0, $quantity),
                title: $sellingModel === MarketplaceListingModel::MODEL_CLASSIC ? $title : null,
                familyName: $sellingModel === MarketplaceListingModel::MODEL_USER_PRODUCTS ? $familyName : null,
                pictures: $pictures,
                attributes: $attributeValues,
                warrantyType: $warrantyType,
                warrantyTime: $warrantyTime,
            );
            $payload = $this->builder->build($draft);
        }

        return new ListingPreview(
            product: $product,
            account: $account,
            sellingModel: $sellingModel,
            existing: $existing,
            offer: $offer,
            quantity: $quantity,
            category: $category,
            categorySource: $categoryId === null ? null : $categorySource,
            predictions: $predictions,
            attributes: $shownAttributes,
            attributeValues: $attributeValues,
            listingTypes: $listingTypes,
            listingTypeId: $listingTypeId,
            conditions: $conditions,
            condition: $condition,
            currencyId: $currencyId,
            title: $title,
            familyName: $familyName,
            maxTitleLength: $maxTitleLength,
            pictures: $pictures,
            warrantyType: $warrantyType,
            warrantyTime: $warrantyTime,
            fee: $fee,
            breakdown: $breakdown,
            draft: $draft,
            payload: $payload,
            errors: array_values(array_unique($errors)),
            warnings: array_values(array_unique($warnings)),
        );
    }

    /**
     * Preço e composição com a tarifa oficial para o fornecedor principal atual.
     *
     * @return array{0: ?ListingFee, 1: ?PriceBreakdown, 2: ?string} tarifa, composição e erro
     */
    public function officialPrice(array $product, array $account, OfferEvaluation $offer, string $categoryId, string $listingTypeId, string $currencyId): array
    {
        $pricing = $this->pricing ?? service('pricing');
        $context = $pricing->contextFor($product, $pricing->config(MercadoLivreAuthService::SLUG));
        $costs   = new CostInput($offer->costPrice, $offer->shippingCost, $context->productExtraCost);
        $start   = $offer->pricing?->suggestedPrice ?? $offer->pricing?->price ?? $product['suggested_price'] ?? null;

        if ($start === null && ! $context->hasManualPrice()) {
            try {
                $start = $pricing->calculator()->suggest($costs, $context->margins, $context->config)->price;
            } catch (PricingException $e) {
                return [null, null, $e->getMessage()];
            }
        }

        try {
            $result = $this->fees->price($account, $categoryId, $listingTypeId, $currencyId, $costs, $context, (string) ($start ?? $context->manualPrice));
        } catch (PricingException $e) {
            return [null, null, 'Com a tarifa oficial não existe preço que cubra os custos: ' . $e->getMessage()];
        } catch (MercadoLivreApiException $e) {
            return [null, null, 'Não foi possível consultar a tarifa no Mercado Livre: ' . $e->userMessage()];
        } catch (UnexpectedValueException $e) {
            return [null, null, $e->getMessage()];
        }

        return [$result['fee'], $result['breakdown'], null];
    }

    /**
     * Faixa da automação de preços: o piso entrega a margem mínima e o teto
     * a desejada, ambos com a tarifa oficial do próprio preço.
     *
     * @return array{min: PriceBreakdown, max: PriceBreakdown, fee: ?ListingFee}
     *
     * @throws BusinessRuleException quando não há faixa possível
     */
    public function automationRange(array $product, array $account, OfferEvaluation $offer, string $categoryId, string $listingTypeId, string $currencyId): array
    {
        $pricing = $this->pricing ?? service('pricing');
        $context = $pricing->contextFor($product, $pricing->config(MercadoLivreAuthService::SLUG));

        if ($context->hasManualPrice()) {
            throw new BusinessRuleException('O produto tem preço de venda manual. Remova o preço manual para o Mercado Livre ajustar o preço automaticamente.');
        }

        [$fee, $max, $error] = $this->officialPrice($product, $account, $offer, $categoryId, $listingTypeId, $currencyId);
        if ($error !== null || $max === null) {
            throw new BusinessRuleException($error ?? 'Não foi possível calcular o preço máximo.');
        }

        try {
            $min = $this->fees->minimumPrice($account, $categoryId, $listingTypeId, $currencyId, new CostInput($offer->costPrice, $offer->shippingCost, $context->productExtraCost), $context)['breakdown'];
        } catch (PricingException $e) {
            throw new BusinessRuleException('Com a tarifa oficial não existe preço mínimo que cubra os custos: ' . $e->getMessage(), 0, $e);
        } catch (MercadoLivreApiException $e) {
            throw new BusinessRuleException('Não foi possível consultar a tarifa no Mercado Livre: ' . $e->userMessage(), 0, $e);
        } catch (UnexpectedValueException $e) {
            throw new BusinessRuleException($e->getMessage(), 0, $e);
        }

        if (bccomp($min->price, $max->price, 2) > 0) {
            throw new BusinessRuleException('O preço mínimo calculado ficou acima do máximo. Revise as margens do produto.');
        }

        return ['min' => $min, 'max' => $max, 'fee' => $fee];
    }

    /**
     * @return list<string>
     */
    private function existingListingErrors(?array $existing): array
    {
        if ($existing === null) {
            return [];
        }

        return match ($existing['status']) {
            MarketplaceListingModel::STATUS_ERROR, MarketplaceListingModel::STATUS_CLOSED => [],
            MarketplaceListingModel::STATUS_PENDING => ['Há uma publicação deste produto sem confirmação do Mercado Livre. Confira em "Minhas vendas" se o anúncio foi criado antes de tentar de novo.'],
            default => ['O produto já tem o anúncio ' . ($existing['external_listing_id'] ?? '#' . $existing['id']) . ' nesta conta. Use as ações da tela de anúncios para atualizá-lo.'],
        };
    }

    /**
     * @return array{0: ?string, 1: ?string} id da categoria e origem (form, listing, mapping, predictor)
     */
    private function chooseCategory(array $input, ?array $existing, array $product, array $account): array
    {
        $fromInput = $this->text($input['category_id'] ?? null);
        if ($fromInput !== null) {
            return [strtoupper($fromInput), 'form'];
        }

        if (! empty($existing['category_id'])) {
            return [$existing['category_id'], 'listing'];
        }

        if (! empty($product['category_id'])) {
            $marketplace = $this->meli->accounts()->marketplace(MercadoLivreAuthService::SLUG);
            $mapping     = $this->mappings->findFor((int) $marketplace['id'], (int) $product['category_id']);
            if ($mapping !== null) {
                return [$mapping['external_category_id'], 'mapping'];
            }
        }

        return [null, null];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function resolveAttributes(array $attributes, array $input, ?array $existing, array $product): array
    {
        $saved    = json_decode((string) ($existing['attributes'] ?? ''), true);
        $saved    = is_array($saved) ? $saved : [];
        $posted   = is_array($input['attributes'] ?? null) ? $input['attributes'] : null;
        $defaults = array_filter([
            'BRAND' => $this->text($product['brand'] ?? null),
            'GTIN'  => $this->text($product['ean'] ?? null),
        ]);

        $shown  = [];
        $values = [];

        foreach ($attributes as $attribute) {
            $id = $attribute['id'];

            $value = $posted !== null
                ? $this->text($posted[$id] ?? null)
                : $this->text($saved[$id] ?? null) ?? $defaults[$id] ?? null;

            if ($value !== null) {
                $values[$id] = mb_substr($value, 0, $attribute['value_max_length'] ?? self::MAX_ATTRIBUTE_LENGTH);
            }

            if ($attribute['required'] || in_array($id, self::SUGGESTED_ATTRIBUTES, true) || $value !== null) {
                $shown[] = $attribute;
            }
        }

        return [$shown, $values];
    }

    /**
     * @return list<string>
     */
    private function titleErrors(string $title, ?int $maxLength): array
    {
        $errors = [];

        if (mb_strlen($title) < 3) {
            $errors[] = 'Informe o título do anúncio.';
        }
        if ($maxLength !== null && mb_strlen($title) > $maxLength) {
            $errors[] = 'O título tem ' . mb_strlen($title) . ' caracteres; a categoria aceita até ' . $maxLength . '.';
        }
        if (mb_stripos($title, self::FORBIDDEN_TITLE_WORD) !== false) {
            $errors[] = 'O título não pode conter a palavra "estoque" (o Mercado Livre modera o anúncio).';
        }

        return $errors;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : $value;
    }
}
