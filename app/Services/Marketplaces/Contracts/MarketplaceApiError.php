<?php

namespace App\Services\Marketplaces\Contracts;

use Throwable;

/**
 * Erro devolvido pela API de um marketplace. A fila usa isTemporary() para
 * decidir se vale tentar de novo; as telas mostram userMessage().
 */
interface MarketplaceApiError extends Throwable
{
    /**
     * Falta de resposta, limite de requisições ou instabilidade do marketplace.
     */
    public function isTemporary(): bool;

    /**
     * Só uma nova autorização do vendedor resolve.
     */
    public function requiresReauthorization(): bool;

    /**
     * Texto em português, sem tokens, seguro para exibir.
     */
    public function userMessage(): string;
}
