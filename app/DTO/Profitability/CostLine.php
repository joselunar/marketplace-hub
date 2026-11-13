<?php

namespace App\DTO\Profitability;

/**
 * Linha de custo na análise de rentabilidade.
 *
 * reliability: confirmado | estimado | desconhecido | nao_aplicado
 */
final readonly class CostLine
{
    public const CONFIRMED = 'confirmado';
    public const ESTIMATED = 'estimado';
    public const UNKNOWN   = 'desconhecido';
    public const SKIPPED   = 'nao_aplicado';

    public function __construct(
        public string $code,
        public string $label,
        public ?string $amount,
        public string $reliability,
        public string $reason = '',
    ) {
    }

    public function toArray(): array
    {
        return [
            'code'         => $this->code,
            'label'        => $this->label,
            'amount'       => $this->amount,
            'reliability'  => $this->reliability,
            'reason'       => $this->reason,
        ];
    }
}
