<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\MarketplaceAccountModel;
use App\Models\MarketplaceListingModel;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreApiException;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreAuthService;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreProductService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Anúncios do Mercado Livre: listagem, prévia, publicação confirmada e ações.
 */
class MercadoLivreListingController extends BaseController
{
    private const HOME = 'marketplaces/mercadolivre/listings';

    /**
     * Campos da prévia que viajam entre a tela e a confirmação.
     */
    private const PREVIEW_FIELDS = ['category_id', 'listing_type_id', 'condition', 'title', 'family_name', 'warranty_type', 'warranty_time'];

    public function index(): string
    {
        $filters = $this->filters(['q', 'status', 'account']);
        $model   = new MarketplaceListingModel();
        $rows    = $model->withDetails()
            ->applyFilters($filters)
            ->orderBy('marketplace_listings.updated_at', 'DESC')
            ->paginate($this->perPage());

        return view('mercadolivre/listings/index', [
            'title'       => 'Anúncios do Mercado Livre',
            'tab'         => 'listings',
            'listings'    => $this->products()->withMargins($rows),
            'pager'       => $model->pager,
            'filters'     => $filters,
            'accounts'    => service('mercadoLivre')->accounts()->listFor(MercadoLivreAuthService::SLUG),
            'automatic'   => $this->products()->automaticPublishEnabled(),
            'breadcrumbs' => ['Marketplaces' => null, 'Mercado Livre' => 'marketplaces/mercadolivre', 'Anúncios' => null],
        ]);
    }

    /**
     * Prévia: nada é enviado ao Mercado Livre até o usuário confirmar.
     */
    public function new(): string|RedirectResponse
    {
        $productId = (int) $this->request->getGet('product_id');
        if ($productId <= 0) {
            return redirect()->to('products')->with('error', 'Escolha o produto a publicar.');
        }

        $data = [
            'title'       => 'Publicar no Mercado Livre',
            'tab'         => 'listings',
            'preview'     => null,
            'error'       => null,
            'accounts'    => $this->connectedAccounts(),
            'breadcrumbs' => ['Marketplaces' => null, 'Mercado Livre' => 'marketplaces/mercadolivre', 'Anúncios' => self::HOME, 'Nova publicação' => null],
        ];

        try {
            $data['preview'] = $this->products()->previews()->preview($productId, $this->previewInput($this->request->getGet()), $this->accountParam($this->request->getGet('account')));
        } catch (BusinessRuleException $e) {
            $data['error'] = $e->getMessage();
        } catch (MercadoLivreApiException $e) {
            $data['error'] = $e->userMessage();
        }

        return view('mercadolivre/listings/new', $data);
    }

    public function create(): RedirectResponse
    {
        $post      = $this->request->getPost();
        $productId = (int) ($post['product_id'] ?? 0);
        $input     = $this->previewInput($post);
        $accountId = $this->accountParam($post['account'] ?? null);

        try {
            $listing = $this->products()->publish(
                $productId,
                $input,
                (string) ($post['confirm_price'] ?? ''),
                (int) ($post['confirm_quantity'] ?? -1),
                $accountId,
                $this->auth()->id(),
            );
        } catch (BusinessRuleException $e) {
            return redirect()->to($this->previewUrl($productId, $input, $accountId))->with('error', $e->getMessage());
        } catch (MercadoLivreApiException $e) {
            return redirect()->to($this->previewUrl($productId, $input, $accountId))->with('error', $e->userMessage());
        }

        $redirect = redirect()->to(self::HOME . '/' . $listing['id'])
            ->with('success', 'Anúncio ' . $listing['external_listing_id'] . ' publicado no Mercado Livre.');

        return $listing['warnings'] === [] ? $redirect : $redirect->with('warning', implode(' ', $listing['warnings']));
    }

    public function show(int $id): string
    {
        $service = $this->products();
        $listing = $service->withMargins([$service->findOrFail($id)])[0];

        return view('mercadolivre/listings/show', [
            'title'       => 'Anúncio ' . ($listing['external_listing_id'] ?? '#' . $listing['id']),
            'tab'         => 'listings',
            'listing'     => $listing,
            'history'     => $service->history($id),
            'attributes'  => json_decode((string) ($listing['attributes'] ?? ''), true) ?: [],
            'breadcrumbs' => ['Marketplaces' => null, 'Mercado Livre' => 'marketplaces/mercadolivre', 'Anúncios' => self::HOME, $listing['external_listing_id'] ?? 'Anúncio' => null],
        ]);
    }

    public function sync(int $id): RedirectResponse
    {
        return $this->action($id, fn (int $userId) => $this->products()->sync($id, $userId), 'Anúncio sincronizado com o Mercado Livre.');
    }

    public function updatePrice(int $id): RedirectResponse
    {
        return $this->action($id, fn (int $userId) => $this->products()->updatePrice($id, $userId), 'Preço enviado ao Mercado Livre.');
    }

    public function updateStock(int $id): RedirectResponse
    {
        return $this->action($id, fn (int $userId) => $this->products()->updateStock($id, $userId), 'Estoque enviado ao Mercado Livre.');
    }

    public function enableAutomation(int $id): RedirectResponse
    {
        return $this->action($id, fn (int $userId) => $this->products()->automation()->enable($id, $userId), 'Automação de preço ativada no Mercado Livre.');
    }

    public function disableAutomation(int $id): RedirectResponse
    {
        return $this->action($id, fn (int $userId) => $this->products()->automation()->disable($id, $userId), 'Automação de preço desativada.');
    }

    public function pause(int $id): RedirectResponse
    {
        return $this->action($id, fn (int $userId) => $this->products()->pause($id, $userId), 'Anúncio pausado.');
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->action($id, fn (int $userId) => $this->products()->activate($id, $userId), 'Anúncio reativado.');
    }

    /**
     * @param callable(int): array $operation
     */
    private function action(int $id, callable $operation, string $success): RedirectResponse
    {
        $back = $this->request->getPost('return_to') === 'list' ? self::HOME : self::HOME . '/' . $id;

        try {
            $operation((int) $this->auth()->id());
        } catch (BusinessRuleException $e) {
            return redirect()->to($back)->with('error', $e->getMessage());
        } catch (MercadoLivreApiException $e) {
            return redirect()->to($back)->with('error', $e->userMessage());
        }

        return redirect()->to($back)->with('success', $success);
    }

    /**
     * @return array<string, mixed>
     */
    private function previewInput(?array $source): array
    {
        $source = $source ?? [];
        $input  = [];

        foreach (self::PREVIEW_FIELDS as $field) {
            if (is_string($source[$field] ?? null)) {
                $input[$field] = mb_substr($source[$field], 0, 300);
            }
        }

        if (isset($source['attributes']) && is_array($source['attributes'])) {
            $input['attributes'] = [];
            foreach ($source['attributes'] as $id => $value) {
                if (is_string($id) && preg_match('/^[A-Z0-9_]{1,60}$/', $id) && is_string($value)) {
                    $input['attributes'][$id] = mb_substr($value, 0, 300);
                }
            }
        }

        return $input;
    }

    private function previewUrl(int $productId, array $input, ?int $accountId): string
    {
        return site_url(self::HOME . '/new') . '?' . http_build_query(array_filter(['product_id' => $productId, 'account' => $accountId]) + $input);
    }

    private function accountParam(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    private function connectedAccounts(): array
    {
        return array_values(array_filter(
            service('mercadoLivre')->accounts()->listFor(MercadoLivreAuthService::SLUG),
            static fn (array $account): bool => $account['status'] === MarketplaceAccountModel::STATUS_CONNECTED && (int) $account['active'] === 1,
        ));
    }

    private function products(): MercadoLivreProductService
    {
        return service('mercadoLivreProducts');
    }
}
