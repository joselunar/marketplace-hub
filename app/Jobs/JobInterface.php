<?php

namespace App\Jobs;

/**
 * Um job da fila. handle() recebe o payload gravado e devolve um resumo
 * do resultado; falhas são exceções (o worker decide se tenta de novo).
 */
interface JobInterface
{
    /**
     * Nome exibido na tela de sincronizações.
     */
    public static function label(): string;

    public function handle(array $payload): string;
}
