<?php

namespace App\Services\Pricing;

use DomainException;

/**
 * Não existe preço que atenda aos parâmetros informados
 * (ex.: taxas, impostos e margem somam 100% ou mais).
 */
class PricingException extends DomainException
{
}
