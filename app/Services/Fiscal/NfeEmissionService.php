<?php

namespace App\Services\Fiscal;

use App\Exceptions\BusinessRuleException;
use App\Models\FiscalCompanyModel;
use App\Models\FiscalInvoiceEventModel;
use App\Models\FiscalInvoiceModel;
use App\Models\OrderModel;
use App\Support\Decimal;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Make;
use stdClass;

/**
 * Emissor NF-e modelo 55 via NFePHP → SEFAZ.
 * Código próprio do Vitrine Hub (não reutiliza fontes proprietárias do Venus).
 */
class NfeEmissionService
{
    public function __construct(
        private readonly FiscalGate $gate = new FiscalGate(),
        private readonly FiscalCompanyModel $companies = new FiscalCompanyModel(),
        private readonly FiscalInvoiceModel $invoices = new FiscalInvoiceModel(),
        private readonly FiscalInvoiceEventModel $events = new FiscalInvoiceEventModel(),
        private readonly OrderModel $orders = new OrderModel(),
        private readonly InvoiceNumberService $numbers = new InvoiceNumberService(),
        private readonly NfeToolsFactory $toolsFactory = new NfeToolsFactory(),
        private readonly FiscalDocumentStorage $storage = new FiscalDocumentStorage(),
        private readonly ?\App\Services\Audit\AuditService $audit = null,
    ) {
    }

    /**
     * Consulta status do serviço SEFAZ (homologação ou produção autorizada).
     *
     * @return array<string, mixed>
     */
    public function statusServico(): array
    {
        $this->gate->assertCanTransmit();
        $tools = $this->toolsFactory->make();
        $response = $tools->sefazStatus();
        $std = (new Standardize($response))->toStd();

        return [
            'environment' => $this->gate->effectiveEnvironment(),
            'tpAmb'       => $this->gate->tpAmb(),
            'cStat'       => (string) ($std->cStat ?? ''),
            'xMotivo'     => (string) ($std->xMotivo ?? ''),
            'tMed'        => (string) ($std->tMed ?? ''),
            'raw'         => $response,
        ];
    }

    /**
     * Prepara rascunho vinculado ao pedido (sem transmitir / sem consumir numeração de produção).
     *
     * @return array<string, mixed>
     */
    public function prepareFromOrder(int $orderId, ?int $userId = null): array
    {
        $this->gate->assertModuleEnabled();
        $company = $this->companies->primaryCompany();
        if ($company === null) {
            throw new BusinessRuleException('Cadastre a empresa fiscal antes de preparar a NF-e.');
        }
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new BusinessRuleException('Pedido não encontrado.');
        }

        $dedupe = hash('sha256', 'order:' . $orderId . ':model:55');
        $existing = $this->invoices->where('dedupe_key', $dedupe)
            ->whereIn('status', [
                FiscalInvoiceModel::STATUS_AUTHORIZED,
                FiscalInvoiceModel::STATUS_SENT,
                FiscalInvoiceModel::STATUS_WAITING,
                FiscalInvoiceModel::STATUS_SIGNED,
            ])->first();
        if ($existing !== null) {
            throw new BusinessRuleException('Já existe NF-e em andamento/autorizada para este pedido (#' . $existing['id'] . ').');
        }

        $payload = [
            'order_id'          => $orderId,
            'external_order_id' => $order['external_order_id'] ?? null,
            'shipping_id'       => $order['shipping_id'] ?? null,
            'pack_id'           => $order['pack_id'] ?? null,
            'total_amount'      => $order['total_amount'] ?? null,
            'note'              => 'Rascunho. Tributação depende de parâmetros validados pelo contador. Dropshipping/venda à ordem não presumidos.',
        ];

        $id = $this->invoices->insert([
            'fiscal_company_id'      => (int) $company['id'],
            'order_id'               => $orderId,
            'marketplace_account_id' => $order['marketplace_account_id'] ?? null,
            'external_order_id'      => $order['external_order_id'] ?? null,
            'shipping_id'            => $order['shipping_id'] ?? null,
            'pack_id'                => $order['pack_id'] ?? null,
            'model'                  => '55',
            'serie'                  => 0,
            'number'                 => 0,
            'environment'            => $this->gate->effectiveEnvironment(),
            'status'                 => FiscalInvoiceModel::STATUS_DRAFT,
            'payload_json'           => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'dedupe_key'             => $dedupe . ':draft:' . time(),
            'created_by'             => $userId,
            'issued_at'              => date('Y-m-d H:i:s'),
        ], true);

        $this->logEvent((int) $id, 'prepare', 'Rascunho fiscal criado a partir do pedido.', null, $userId);

        return $this->invoices->find((int) $id);
    }

    /**
     * Monta, assina e transmite NF-e (homologação por padrão).
     *
     * @return array<string, mixed>
     */
    public function transmit(int $invoiceId, ?int $userId = null): array
    {
        $this->gate->assertCanTransmit();
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) {
            throw new BusinessRuleException('Documento fiscal não encontrado.');
        }
        if (in_array($invoice['status'], [FiscalInvoiceModel::STATUS_AUTHORIZED, FiscalInvoiceModel::STATUS_CANCELLED], true)) {
            throw new BusinessRuleException('Documento já finalizado; não retransmita.');
        }

        $company = $this->companies->find((int) $invoice['fiscal_company_id']);
        if ($company === null) {
            throw new BusinessRuleException('Empresa fiscal ausente.');
        }
        if (empty($company['tax_params_validated'])) {
            throw new BusinessRuleException('Parâmetros tributários não validados pelo contador. Não inventamos CFOP/CSOSN/CRT.');
        }

        $tax = json_decode((string) ($company['tax_params_json'] ?? '{}'), true) ?: [];
        foreach (['cfop', 'csosn', 'natureza_operacao'] as $required) {
            if (empty($tax[$required])) {
                throw new BusinessRuleException("Parâmetro tributário obrigatório ausente: {$required}.");
            }
        }

        $reservation = $this->numbers->reserve((int) $company['id']);
        $tools = $this->toolsFactory->make((int) $company['id']);

        try {
            $xml = $this->buildXml($company, $invoice, $tax, $reservation);
            $signed = $tools->signNFe($xml);
            $signedPath = $this->storage->storeXml(
                ($reservation['serie'] . '-' . $reservation['number']),
                $signed,
                'signed',
            );

            $idLote = substr((string) time(), 0, 15);
            $resp = $tools->sefazEnviaLote([$signed], $idLote, 1);
            $std = (new Standardize($resp))->toStd();

            $cStat = (string) ($std->cStat ?? ($std->protNFe->infProt->cStat ?? ''));
            $xMotivo = (string) ($std->xMotivo ?? ($std->protNFe->infProt->xMotivo ?? ''));
            $protocol = (string) ($std->protNFe->infProt->nProt ?? '');
            $chave = (string) ($std->protNFe->infProt->chNFe ?? '');
            $status = FiscalInvoiceModel::STATUS_SENT;
            $authorizedPath = null;

            if (in_array($cStat, ['100', '150'], true)) {
                $status = FiscalInvoiceModel::STATUS_AUTHORIZED;
                try {
                    $authorized = Complements::toAuthorize($signed, $resp);
                    $authorizedPath = $this->storage->storeXml($chave !== '' ? $chave : ('ok-' . $invoiceId), $authorized, 'authorized');
                } catch (\Throwable) {
                    $authorizedPath = null;
                }
            } elseif (in_array($cStat, ['103', '104', '105'], true)) {
                $status = FiscalInvoiceModel::STATUS_WAITING;
            } elseif (in_array($cStat, ['110', '301', '302'], true)) {
                $status = FiscalInvoiceModel::STATUS_DENIED;
            } else {
                $status = FiscalInvoiceModel::STATUS_REJECTED;
            }

            $this->invoices->update($invoiceId, [
                'serie'               => $reservation['serie'],
                'number'              => $reservation['number'],
                'access_key'          => $chave !== '' ? $chave : null,
                'environment'         => $reservation['environment'],
                'status'              => $status,
                'sefaz_status_code'   => $cStat,
                'sefaz_message'       => mb_substr($xMotivo, 0, 500),
                'protocol'            => $protocol !== '' ? $protocol : null,
                'xml_signed_path'     => $signedPath,
                'xml_authorized_path' => $authorizedPath,
                'authorized_at'       => $status === FiscalInvoiceModel::STATUS_AUTHORIZED ? date('Y-m-d H:i:s') : null,
                'issued_at'           => date('Y-m-d H:i:s'),
            ]);

            $this->logEvent($invoiceId, 'transmit', "SEFAZ cStat={$cStat} {$xMotivo}", [
                'cStat' => $cStat,
                'environment' => $reservation['environment'],
            ], $userId);

            return $this->invoices->find($invoiceId);
        } catch (BusinessRuleException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->invoices->update($invoiceId, [
                'status'            => FiscalInvoiceModel::STATUS_ERROR,
                'sefaz_message'     => mb_substr($e->getMessage(), 0, 500),
                'serie'             => $reservation['serie'],
                'number'            => $reservation['number'],
                'environment'       => $reservation['environment'],
            ]);
            $this->logEvent($invoiceId, 'error', $e->getMessage(), null, $userId);
            throw new BusinessRuleException('Falha na transmissão fiscal: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function consultar(int $invoiceId, ?int $userId = null): array
    {
        $this->gate->assertCanTransmit();
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null || empty($invoice['access_key'])) {
            throw new BusinessRuleException('NF-e sem chave de acesso para consulta.');
        }
        $tools = $this->toolsFactory->make((int) $invoice['fiscal_company_id']);
        $resp = $tools->sefazConsultaChave((string) $invoice['access_key']);
        $std = (new Standardize($resp))->toStd();
        $cStat = (string) ($std->cStat ?? ($std->protNFe->infProt->cStat ?? ''));
        $xMotivo = (string) ($std->xMotivo ?? ($std->protNFe->infProt->xMotivo ?? ''));

        $status = $invoice['status'];
        if (in_array($cStat, ['100', '150'], true)) {
            $status = FiscalInvoiceModel::STATUS_AUTHORIZED;
        } elseif ($cStat === '101') {
            $status = FiscalInvoiceModel::STATUS_CANCELLED;
        }

        $this->invoices->update($invoiceId, [
            'status'            => $status,
            'sefaz_status_code' => $cStat,
            'sefaz_message'     => mb_substr($xMotivo, 0, 500),
        ]);
        $this->logEvent($invoiceId, 'consulta', "Consulta SEFAZ cStat={$cStat}", ['cStat' => $cStat], $userId);

        return $this->invoices->find($invoiceId);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelar(int $invoiceId, string $justificativa, ?int $userId = null): array
    {
        $this->gate->assertCanTransmit();
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null || $invoice['status'] !== FiscalInvoiceModel::STATUS_AUTHORIZED) {
            throw new BusinessRuleException('Somente NF-e autorizada pode ser cancelada.');
        }
        if (strlen(trim($justificativa)) < 15) {
            throw new BusinessRuleException('Justificativa de cancelamento deve ter ao menos 15 caracteres.');
        }
        $tools = $this->toolsFactory->make((int) $invoice['fiscal_company_id']);
        $resp = $tools->sefazCancela((string) $invoice['access_key'], $justificativa, (string) $invoice['protocol']);
        $std = (new Standardize($resp))->toStd();
        $cStat = (string) ($std->retEvento->infEvento->cStat ?? $std->cStat ?? '');
        $xMotivo = (string) ($std->retEvento->infEvento->xMotivo ?? $std->xMotivo ?? '');

        if (in_array($cStat, ['135', '155', '101'], true)) {
            $this->invoices->update($invoiceId, [
                'status'        => FiscalInvoiceModel::STATUS_CANCELLED,
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'sefaz_status_code' => $cStat,
                'sefaz_message' => mb_substr($xMotivo, 0, 500),
            ]);
        }
        $this->logEvent($invoiceId, 'cancelamento', "Cancelamento cStat={$cStat} {$xMotivo}", null, $userId);

        return $this->invoices->find($invoiceId);
    }

    /**
     * Gera DANFE a partir do XML autorizado (ou assinado).
     */
    public function gerarDanfe(int $invoiceId): string
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) {
            throw new BusinessRuleException('Documento não encontrado.');
        }
        $path = $invoice['xml_authorized_path'] ?: $invoice['xml_signed_path'];
        if (! $path) {
            throw new BusinessRuleException('XML indisponível para DANFE.');
        }
        $xml = $this->storage->read($path);
        if (! class_exists(\NFePHP\DA\NFe\Danfe::class)) {
            throw new BusinessRuleException('Biblioteca nfephp-org/sped-da não instalada.');
        }
        $danfe = new \NFePHP\DA\NFe\Danfe($xml);
        $pdf = $danfe->render();
        $out = $this->storage->storeDanfe((string) ($invoice['access_key'] ?: $invoiceId), $pdf);
        $this->invoices->update($invoiceId, ['danfe_path' => $out]);

        return $out;
    }

    /**
     * @param array<string, mixed> $company
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $tax
     * @param array{serie:int,number:int,environment:string} $reservation
     */
    private function buildXml(array $company, array $invoice, array $tax, array $reservation): string
    {
        $make = new Make();
        $std = new stdClass();
        $std->versao = '4.00';
        $make->taginfNFe($std);

        $ide = new stdClass();
        $ide->cUF = $this->cUF((string) ($company['uf'] ?? 'RN'));
        $ide->natOp = (string) $tax['natureza_operacao'];
        $ide->mod = 55;
        $ide->serie = $reservation['serie'];
        $ide->nNF = $reservation['number'];
        $ide->dhEmi = date('c');
        $ide->tpNF = 1;
        $ide->idDest = 1;
        $ide->cMunFG = (string) ($company['city_ibge'] ?? '');
        $ide->tpImp = 1;
        $ide->tpEmis = 1;
        $ide->tpAmb = $this->gate->tpAmb();
        $ide->finNFe = 1;
        $ide->indFinal = 1;
        $ide->indPres = 2;
        $ide->procEmi = 0;
        $ide->verProc = 'VitrineHub-1.0';
        if ($ide->cMunFG === '') {
            throw new BusinessRuleException('Informe o código IBGE do município da empresa fiscal.');
        }
        $make->tagide($ide);

        $emit = new stdClass();
        $emit->xNome = (string) $company['legal_name'];
        $emit->xFant = (string) ($company['trade_name'] ?: $company['legal_name']);
        $emit->IE = (string) ($company['state_registration'] ?? '');
        $emit->CRT = (int) ($company['crt'] ?? 0);
        if ($emit->CRT < 1) {
            throw new BusinessRuleException('Informe o CRT da empresa (validado pelo contador).');
        }
        $doc = preg_replace('/\D+/', '', (string) $company['document']) ?? '';
        if (strlen($doc) === 14) {
            $emit->CNPJ = $doc;
        } else {
            $emit->CPF = $doc;
        }
        $make->tagemit($emit);

        $end = new stdClass();
        $end->xLgr = (string) ($company['street'] ?? '');
        $end->nro = (string) ($company['number'] ?? 'S/N');
        $end->xBairro = (string) ($company['district'] ?? '');
        $end->cMun = (string) ($company['city_ibge'] ?? '');
        $end->xMun = (string) ($company['city'] ?? '');
        $end->UF = (string) ($company['uf'] ?? '');
        $end->CEP = preg_replace('/\D+/', '', (string) ($company['zip_code'] ?? '')) ?? '';
        $end->cPais = '1058';
        $end->xPais = 'Brasil';
        $make->tagenderEmit($end);

        // Destinatário genérico de homologação quando não houver dados completos do comprador.
        $dest = new stdClass();
        $dest->xNome = $this->gate->tpAmb() === 2
            ? 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL'
            : 'DESTINATARIO PENDENTE DE DADOS COMPLETOS';
        $dest->indIEDest = 9;
        $dest->CPF = '00000000000';
        $make->tagdest($dest);

        $endDest = new stdClass();
        $endDest->xLgr = (string) ($company['street'] ?? 'Rua');
        $endDest->nro = 'S/N';
        $endDest->xBairro = (string) ($company['district'] ?? 'Centro');
        $endDest->cMun = (string) ($company['city_ibge'] ?? '');
        $endDest->xMun = (string) ($company['city'] ?? '');
        $endDest->UF = (string) ($company['uf'] ?? '');
        $endDest->CEP = preg_replace('/\D+/', '', (string) ($company['zip_code'] ?? '')) ?? '';
        $endDest->cPais = '1058';
        $endDest->xPais = 'Brasil';
        $make->tagenderDest($endDest);

        $payload = json_decode((string) ($invoice['payload_json'] ?? '{}'), true) ?: [];
        $total = Decimal::normalize((string) ($payload['total_amount'] ?? '0')) ?? '0.00';
        if (bccomp($total, '0', 2) <= 0) {
            $total = '1.00';
        }

        $prod = new stdClass();
        $prod->item = 1;
        $prod->cProd = 'PED' . ($invoice['order_id'] ?? $invoice['id']);
        $prod->cEAN = 'SEM GTIN';
        $prod->xProd = $this->gate->tpAmb() === 2
            ? 'NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL'
            : 'Item do pedido marketplace';
        $prod->NCM = (string) ($tax['ncm_default'] ?? '');
        if ($prod->NCM === '') {
            throw new BusinessRuleException('Informe NCM padrão validado pelo contador.');
        }
        $prod->CFOP = (string) $tax['cfop'];
        $prod->uCom = 'UN';
        $prod->qCom = '1.0000';
        $prod->vUnCom = $total;
        $prod->vProd = $total;
        $prod->cEANTrib = 'SEM GTIN';
        $prod->uTrib = 'UN';
        $prod->qTrib = '1.0000';
        $prod->vUnTrib = $total;
        $prod->indTot = 1;
        $make->tagprod($prod);

        $icms = new stdClass();
        $icms->item = 1;
        $icms->orig = (string) ($tax['origem'] ?? '0');
        $icms->CSOSN = (string) $tax['csosn'];
        $make->tagICMSSN($icms);

        $pis = new stdClass();
        $pis->item = 1;
        $pis->CST = '49';
        $pis->vBC = '0.00';
        $pis->pPIS = '0.00';
        $pis->vPIS = '0.00';
        $make->tagPIS($pis);

        $cofins = new stdClass();
        $cofins->item = 1;
        $cofins->CST = '49';
        $cofins->vBC = '0.00';
        $cofins->pCOFINS = '0.00';
        $cofins->vCOFINS = '0.00';
        $make->tagCOFINS($cofins);

        $icmsTot = new stdClass();
        $icmsTot->vBC = '0.00';
        $icmsTot->vICMS = '0.00';
        $icmsTot->vICMSDeson = '0.00';
        $icmsTot->vFCP = '0.00';
        $icmsTot->vBCST = '0.00';
        $icmsTot->vST = '0.00';
        $icmsTot->vFCPST = '0.00';
        $icmsTot->vFCPSTRet = '0.00';
        $icmsTot->vProd = $total;
        $icmsTot->vFrete = '0.00';
        $icmsTot->vSeg = '0.00';
        $icmsTot->vDesc = '0.00';
        $icmsTot->vII = '0.00';
        $icmsTot->vIPI = '0.00';
        $icmsTot->vIPIDevol = '0.00';
        $icmsTot->vPIS = '0.00';
        $icmsTot->vCOFINS = '0.00';
        $icmsTot->vOutro = '0.00';
        $icmsTot->vNF = $total;
        $make->tagICMSTot($icmsTot);

        $transp = new stdClass();
        $transp->modFrete = 9;
        $make->tagtransp($transp);

        $pag = new stdClass();
        $pag->item = 1;
        $pag->tPag = '99';
        $pag->vPag = $total;
        $make->tagdetPag($pag);

        if (! empty($tax['informacoes_complementares'])) {
            $inf = new stdClass();
            $inf->infCpl = (string) $tax['informacoes_complementares'];
            $make->taginfAdic($inf);
        }

        $xml = $make->getXML();
        if (! is_string($xml) || $xml === '') {
            $errors = method_exists($make, 'getErrors') ? $make->getErrors() : [];
            throw new BusinessRuleException('Falha ao montar XML da NF-e: ' . implode('; ', (array) $errors));
        }

        return $xml;
    }

    private function cUF(string $uf): int
    {
        $map = [
            'RO' => 11, 'AC' => 12, 'AM' => 13, 'RR' => 14, 'PA' => 15, 'AP' => 16, 'TO' => 17,
            'MA' => 21, 'PI' => 22, 'CE' => 23, 'RN' => 24, 'PB' => 25, 'PE' => 26, 'AL' => 27,
            'SE' => 28, 'BA' => 29, 'MG' => 31, 'ES' => 32, 'RJ' => 33, 'SP' => 35, 'PR' => 41,
            'SC' => 42, 'RS' => 43, 'MS' => 50, 'MT' => 51, 'GO' => 52, 'DF' => 53,
        ];

        return $map[strtoupper($uf)] ?? 24;
    }

    private function logEvent(int $invoiceId, string $type, string $message, ?array $payload, ?int $userId): void
    {
        $this->events->insert([
            'fiscal_invoice_id' => $invoiceId,
            'event_type'        => $type,
            'message'           => mb_substr($message, 0, 1000),
            'payload_json'      => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'created_by'        => $userId,
        ]);
    }
}
