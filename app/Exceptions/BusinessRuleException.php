<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Operação válida tecnicamente, mas proibida por uma regra de negócio
 * (ex.: excluir um fornecedor que ainda possui ofertas).
 * A mensagem é segura para ser exibida ao usuário.
 */
class BusinessRuleException extends RuntimeException
{
}
