<?php

namespace App\Controllers;

use App\Exceptions\ValidationException;
use App\Models\CategoryModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Simulador de preços. Somente leitura: nada é gravado.
 */
class PricingController extends BaseController
{
    private const FIELDS = ['cost_price', 'shipping_cost', 'extra_cost', 'category_id', 'desired_margin_percentage', 'minimum_margin_percentage', 'sale_price'];

    public function index(): string
    {
        $input = $this->input();

        return view('pricing/index', [
            'title'       => 'Simulador de preços',
            'input'       => $input,
            'categories'  => (new CategoryModel())->dropdownOptions(),
            'config'      => service('pricing')->config(),
            'breadcrumbs' => ['Catálogo' => null, 'Simulador de preços' => null],
        ] + $this->simulate($input));
    }

    /**
     * Fragmento HTML com o resultado, usado pelo formulário via Fetch.
     */
    public function simulate(?array $input = null): array|ResponseInterface
    {
        $partial = $input === null;
        $input ??= $this->input();
        $result  = ['breakdown' => null, 'errors' => []];

        if (($input['cost_price'] ?? '') !== '') {
            try {
                $result['breakdown'] = service('pricing')->simulate($input);
            } catch (ValidationException $e) {
                $result['errors'] = $e->getErrors();
            }
        }

        if (! $partial) {
            return $result;
        }

        return $this->response
            ->setStatusCode($result['errors'] === [] ? 200 : 422)
            ->setBody(view('pricing/_result', $result));
    }

    /**
     * @return array<string, string>
     */
    private function input(): array
    {
        $input = [];

        foreach (self::FIELDS as $field) {
            $value         = $this->request->getGet($field);
            $input[$field] = is_string($value) ? trim($value) : '';
        }

        return $input;
    }
}
