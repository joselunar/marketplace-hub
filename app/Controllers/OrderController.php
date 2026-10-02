<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\MarketplaceAccountModel;
use App\Models\OrderModel;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreApiException;
use App\Services\Marketplaces\MercadoLivre\MercadoLivreAuthService;
use App\Services\Orders\OrderProfitCalculator;
use App\Services\Orders\OrderService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Pedidos dos marketplaces e pedidos aos fornecedores.
 */
class OrderController extends BaseController
{
    private const HOME = 'orders';

    public function index(): string
    {
        $filters = $this->filters(['q', 'status', 'fulfillment', 'account', 'period']);
        $model   = new OrderModel();
        $orders  = $model->withAccount()
            ->applyFilters($filters)
            ->orderBy('orders.ordered_at', 'DESC')
            ->orderBy('orders.id', 'DESC')
            ->paginate($this->perPage());

        $calculator = new OrderProfitCalculator();
        foreach ($orders as &$order) {
            $order['margin'] = $calculator->margin($order['estimated_profit'], $order['total_amount']);
        }
        unset($order);

        return view('orders/index', [
            'title'       => 'Pedidos',
            'orders'      => $orders,
            'pager'       => $model->pager,
            'filters'     => $filters,
            'accounts'    => $this->connectedAccounts(),
            'breadcrumbs' => ['Pedidos' => null],
        ]);
    }

    public function show(int $id): string
    {
        $service = $this->orders();
        $order   = $service->findOrFail($id);

        return view('orders/show', [
            'title'          => 'Pedido ' . $order['external_order_id'],
            'order'          => $order,
            'margin'         => (new OrderProfitCalculator())->margin($order['estimated_profit'], $order['total_amount']),
            'items'          => $service->items($id),
            'supplierOrders' => $service->supplierOrders($id),
            'breadcrumbs'    => ['Pedidos' => self::HOME, $order['external_order_id'] => null],
        ]);
    }

    /**
     * Importação manual dos pedidos atualizados nos últimos dias.
     */
    public function import(): RedirectResponse
    {
        $accountId = (int) $this->request->getPost('account');
        $days      = (int) $this->request->getPost('days');

        if ($accountId <= 0) {
            $accounts  = $this->connectedAccounts();
            $accountId = count($accounts) === 1 ? (int) $accounts[0]['id'] : 0;
        }
        if ($accountId <= 0) {
            return redirect()->to(self::HOME)->with('error', 'Escolha a conta do Mercado Livre.');
        }

        try {
            $stats = service('mercadoLivreOrders')->importRecent($accountId, $days > 0 ? $days : 7, (int) $this->auth()->id());
        } catch (BusinessRuleException $e) {
            return redirect()->to(self::HOME)->with('error', $e->getMessage());
        } catch (MercadoLivreApiException $e) {
            return redirect()->to(self::HOME)->with('error', $e->userMessage());
        }

        $message  = sprintf('Pedidos importados: %d novo(s), %d atualizado(s), %d sem mudança.', $stats['created'], $stats['updated'], $stats['unchanged']);
        $redirect = redirect()->to(self::HOME)->with('success', $message);

        return $stats['failed'] > 0
            ? $redirect->with('warning', sprintf('%d pedido(s) não importado(s): %s', $stats['failed'], implode(' ', array_slice($stats['errors'], 0, 3))))
            : $redirect;
    }

    /**
     * Relê o pedido no Mercado Livre.
     */
    public function sync(int $id): RedirectResponse
    {
        return $this->action($id, function () use ($id): string {
            $order = $this->orders()->findOrFail($id);
            service('mercadoLivreOrders')->import((int) $order['marketplace_account_id'], $order['external_order_id']);

            return 'Pedido atualizado com os dados do Mercado Livre.';
        });
    }

    public function reallocate(int $id): RedirectResponse
    {
        return $this->action($id, function (int $userId) use ($id): string {
            $this->orders()->reallocate($id, $userId);

            return 'Fornecedores revalidados.';
        });
    }

    public function sendToSupplier(int $supplierOrderId): RedirectResponse
    {
        $orderId = $this->orderIdFromPost();

        return $this->action($orderId, function (int $userId) use ($supplierOrderId): string {
            $row = $this->orders()->sendToSupplier(
                $supplierOrderId,
                $this->postString('external_supplier_order_id'),
                $this->postString('notes'),
                $userId,
            );

            return $row['via'] === 'integration'
                ? 'Pedido enviado ao fornecedor pela integração.'
                : 'Envio ao fornecedor registrado. Lembre-se de concluir a compra com ele, se ainda não fez.';
        });
    }

    public function updateSupplierOrder(int $supplierOrderId): RedirectResponse
    {
        $orderId = $this->orderIdFromPost();

        return $this->action($orderId, function (int $userId) use ($supplierOrderId): string {
            $input = ['status' => $this->postString('status') ?? ''];
            foreach (['tracking_code', 'external_supplier_order_id', 'notes'] as $field) {
                $input[$field] = $this->postString($field) ?? '';
            }
            $this->orders()->updateSupplierOrder($supplierOrderId, $input, $userId);

            return 'Pedido ao fornecedor atualizado.';
        });
    }

    /**
     * @param callable(int): string $operation
     */
    private function action(int $orderId, callable $operation): RedirectResponse
    {
        $back = $orderId > 0 ? self::HOME . '/' . $orderId : self::HOME;

        try {
            $message = $operation((int) $this->auth()->id());
        } catch (BusinessRuleException $e) {
            return redirect()->to($back)->with('error', $e->getMessage());
        } catch (MercadoLivreApiException $e) {
            return redirect()->to($back)->with('error', $e->userMessage());
        }

        return redirect()->to($back)->with('success', $message);
    }

    private function orderIdFromPost(): int
    {
        $value = $this->request->getPost('order_id');

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }

    private function postString(string $field): ?string
    {
        $value = $this->request->getPost($field);

        return is_string($value) ? mb_substr($value, 0, 1000) : null;
    }

    private function connectedAccounts(): array
    {
        return array_values(array_filter(
            service('mercadoLivre')->accounts()->listFor(MercadoLivreAuthService::SLUG),
            static fn (array $account): bool => $account['status'] === MarketplaceAccountModel::STATUS_CONNECTED && (int) $account['active'] === 1,
        ));
    }

    private function orders(): OrderService
    {
        return service('orders');
    }
}
