<?php

namespace App\Services\Profitability;

use App\Exceptions\BusinessRuleException;
use App\Models\HomologationChecklistItemModel;
use App\Services\Audit\AuditService;
use App\Services\Suppliers\DropifyImportService;

class HomologationChecklistService
{
    public const DEFAULT_ITEMS = [
        ['item_code' => 'api_start_own_system', 'label' => 'O plano Start permite API para sistema próprio (sem ser integrador comercial)?', 'sort_order' => 10],
        ['item_code' => 'integrator_homologation', 'label' => 'É necessária homologação como integrador?', 'sort_order' => 20],
        ['item_code' => 'accepts_ml_label', 'label' => 'O fornecedor aceita etiqueta do Mercado Envios da conta VITRINEHUB?', 'sort_order' => 30],
        ['item_code' => 'origin_compatible', 'label' => 'Origem física e fiscal da mercadoria compatível com a conta ML?', 'sort_order' => 40],
        ['item_code' => 'extra_dispatch_costs', 'label' => 'Há custos adicionais de expedição? (registrar valor e evidência)', 'sort_order' => 50],
        ['item_code' => 'fiscal_docs_required', 'label' => 'Documentação fiscal obrigatória definida (venda à ordem / remessa)?', 'sort_order' => 60],
        ['item_code' => 'returns_sla', 'label' => 'Devoluções, prazos e responsabilidade por atraso documentados?', 'sort_order' => 70],
    ];

    public const OUTREACH_MESSAGE = 'Olá, tenho uma empresa MEI no RN e desenvolvo meu sistema próprio Vitrine Hub (PHP/CodeIgniter 4), integrado à minha conta PJ VITRINEHUB no Mercado Livre. Estou avaliando o Start Semestral. Poderiam confirmar por escrito: (1) o Start permite API para meu sistema particular sem homologação de integrador comercial, ou preciso solicitar homologação? (2) Existem docs técnicas e ambiente de testes para catálogo, preços, estoque, cálculo de frete, pedidos, NF-e, etiqueta e webhooks? (3) Há setup cobrado, comissão, restrição ou custo de integração adicional? (4) Um produto despachado por fornecedor de SP pode usar a etiqueta de Mercado Envios da minha conta PJ registrada no RN? Quais modalidades e origens são suportadas e como ficam etiqueta, coleta, prazo, XML e operação fiscal de venda à ordem? (5) Quem emite cada documento fiscal, qual valor aparece ao comprador e qual a exigência para transmitir NF-e ao Mercado Livre? (6) Como funcionam devoluções, cancelamentos, falta de estoque e custo do frete? (7) Podem demonstrar o ciclo completo de um pedido de teste antes da contratação semestral? Obrigado.';

    public function __construct(
        private readonly HomologationChecklistItemModel $items = new HomologationChecklistItemModel(),
        private readonly ?AuditService $audit = null,
    ) {
    }

    /**
     * Garante os itens padrão (sempre iniciam como pendente).
     *
     * @return list<array<string, mixed>>
     */
    public function ensureDefaults(?int $supplierId = null): array
    {
        foreach (self::DEFAULT_ITEMS as $item) {
            $exists = $this->items
                ->where('provider_code', DropifyImportService::PROVIDER)
                ->where('item_code', $item['item_code'])
                ->first();
            if ($exists !== null) {
                continue;
            }

            $this->items->insert([
                'supplier_id'   => $supplierId,
                'provider_code' => DropifyImportService::PROVIDER,
                'item_code'     => $item['item_code'],
                'label'         => $item['label'],
                'status'        => HomologationChecklistItemModel::STATUS_PENDING,
                'evidence_note' => null,
                'sort_order'    => $item['sort_order'],
            ]);
        }

        return $this->list();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $this->ensureDefaults();

        return $this->items
            ->where('provider_code', DropifyImportService::PROVIDER)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->findAll();
    }

    public function updateItem(int $id, string $status, ?string $evidence, ?int $userId): array
    {
        $item = $this->items->find($id);
        if ($item === null || ($item['provider_code'] ?? '') !== DropifyImportService::PROVIDER) {
            throw new BusinessRuleException('Item do checklist não encontrado.');
        }

        if (! array_key_exists($status, HomologationChecklistItemModel::STATUS_LABELS)) {
            throw new BusinessRuleException('Status inválido.');
        }

        $evidence = $evidence !== null ? trim($evidence) : '';
        if ($status === HomologationChecklistItemModel::STATUS_CONFIRMED && $evidence === '') {
            throw new BusinessRuleException('Não é permitido marcar como confirmado sem evidência documental.');
        }

        $data = [
            'status'        => $status,
            'evidence_note' => $evidence !== '' ? mb_substr($evidence, 0, 5000) : null,
            'updated_by'    => $userId,
        ];
        $this->items->update($id, $data);

        ($this->audit ?? service('audit'))->log(
            'homologation_update',
            'homologation_checklist_item',
            $id,
            ['status' => $item['status']],
            $data + ['item_code' => $item['item_code']],
            $userId,
        );

        return $this->items->find($id);
    }

    public function outreachMessage(): string
    {
        return self::OUTREACH_MESSAGE;
    }
}
