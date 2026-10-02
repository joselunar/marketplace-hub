<?php

namespace App\Controllers\Api;

use App\Exceptions\ValidationException;
use CodeIgniter\HTTP\ResponseInterface;

class PricingController extends BaseApiController
{
    /**
     * GET /api/pricing/simulate?cost_price=…&shipping_cost=…&category_id=…&sale_price=…
     */
    public function simulate(): ResponseInterface
    {
        $input = [];

        foreach (['cost_price', 'shipping_cost', 'extra_cost', 'category_id', 'desired_margin_percentage', 'minimum_margin_percentage', 'sale_price'] as $field) {
            $input[$field] = $this->queryString($field);
        }

        try {
            $breakdown = service('pricing')->simulate($input);
        } catch (ValidationException $e) {
            return $this->failValidationErrors($e->getErrors());
        }

        return $this->respond(['data' => $breakdown->toArray()]);
    }
}
