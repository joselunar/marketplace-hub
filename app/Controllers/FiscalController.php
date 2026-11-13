<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\FiscalCertificateModel;
use App\Models\FiscalInvoiceEventModel;
use App\Models\FiscalInvoiceModel;
use App\Services\Fiscal\CertificateStorageService;
use App\Services\Fiscal\FiscalCompanyService;
use App\Services\Fiscal\FiscalDiagnosticService;
use App\Services\Fiscal\FiscalDocumentStorage;
use App\Services\Fiscal\FiscalGate;
use App\Services\Fiscal\FiscalSafetyService;
use App\Services\Fiscal\MercadoLivreFiscalDocumentService;
use App\Services\Fiscal\NfeEmissionService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class FiscalController extends BaseController
{
    public function index(): string|RedirectResponse
    {
        $gate = new FiscalGate();
        if (! $gate->moduleEnabled()) {
            return redirect()->to('settings')->with(
                'error',
                'Módulo fiscal desativado. Ative em Configurações → Fiscal (sem liberar emissão real).'
            );
        }

        $filters = $this->filters(['status', 'q', 'date_from', 'date_to']);
        $model   = new FiscalInvoiceModel();
        $rows    = $model->applyFilters($filters)
            ->orderBy('id', 'DESC')
            ->paginate($this->perPage());

        return view('fiscal/index', [
            'title'       => 'Fiscal NF-e',
            'rows'        => $rows,
            'pager'       => $model->pager,
            'filters'     => $filters,
            'counts'      => $model->countsByStatus(),
            'labels'      => FiscalInvoiceModel::STATUS_LABELS,
            'gate'        => $gate,
            'company'     => (new FiscalCompanyService())->getOrEmpty(),
            'breadcrumbs' => ['Vendas' => null, 'Fiscal NF-e' => null],
        ]);
    }

    public function show(int $id): string|RedirectResponse
    {
        $invoice = (new FiscalInvoiceModel())->find($id);
        if ($invoice === null) {
            return redirect()->to('fiscal')->with('error', 'Documento fiscal não encontrado.');
        }

        return view('fiscal/show', [
            'title'       => 'NF-e #' . $id,
            'invoice'     => $invoice,
            'events'      => (new FiscalInvoiceEventModel())->forInvoice($id),
            'labels'      => FiscalInvoiceModel::STATUS_LABELS,
            'gate'        => new FiscalGate(),
            'breadcrumbs' => ['Vendas' => null, 'Fiscal NF-e' => site_url('fiscal'), 'Detalhe' => null],
        ]);
    }

    public function diagnostico(): string|RedirectResponse
    {
        $gate = new FiscalGate();
        if (! $gate->moduleEnabled()) {
            return redirect()->to('settings')->with(
                'error',
                'Módulo fiscal desativado. Ative em Configurações → Fiscal (sem liberar emissão real).'
            );
        }

        // Nesta fase, reafirma bloqueio de emissão real / envio ML ao abrir o diagnóstico.
        $forced = (new FiscalSafetyService())->enforceHomologationLocks($this->auth()->id());
        $report = (new FiscalDiagnosticService())->run();

        return view('fiscal/diagnostico', [
            'title'       => 'Diagnóstico fiscal',
            'report'      => $report,
            'forced'      => $forced,
            'gate'        => $gate,
            'breadcrumbs' => ['Vendas' => null, 'Fiscal NF-e' => site_url('fiscal'), 'Diagnóstico' => null],
        ]);
    }

    public function enforceLocks(): RedirectResponse
    {
        $forced = (new FiscalSafetyService())->enforceHomologationLocks($this->auth()->id());
        $msg = $forced === []
            ? 'Emissão real e envio ao Mercado Livre já estavam desligados.'
            : 'Flags reforçadas: ' . implode(', ', $forced);

        return redirect()->to('fiscal/diagnostico')->with('success', $msg);
    }

    public function company(): string
    {
        $company = (new FiscalCompanyService())->getOrEmpty();
        $tax     = [];
        if (! empty($company['tax_params_json'])) {
            $tax = json_decode((string) $company['tax_params_json'], true) ?: [];
        }
        $cert = null;
        if (! empty($company['id'])) {
            $cert = (new FiscalCertificateModel())->activeForCompany((int) $company['id']);
        }

        return view('fiscal/company', [
            'title'       => 'Empresa fiscal',
            'company'     => $company,
            'tax'         => $tax,
            'certificate' => $cert,
            'gate'        => new FiscalGate(),
            'breadcrumbs' => ['Vendas' => null, 'Fiscal NF-e' => site_url('fiscal'), 'Empresa' => null],
        ]);
    }

    public function saveCompany(): RedirectResponse
    {
        try {
            (new FiscalCompanyService())->save((array) $this->request->getPost(), $this->auth()->id());
        } catch (ValidationException $e) {
            return $this->backWithErrors($e->getErrors());
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to('fiscal/company')->with('success', 'Dados fiscais da empresa salvos.');
    }

    public function saveTaxParams(): RedirectResponse
    {
        $company = (new FiscalCompanyService())->getOrEmpty();
        if ($company === []) {
            return redirect()->to('fiscal/company')->with('error', 'Cadastre a empresa antes dos parâmetros tributários.');
        }

        $validated = $this->request->getPost('tax_params_validated') === '1';
        try {
            (new FiscalCompanyService())->saveTaxParams(
                (int) $company['id'],
                (array) ($this->request->getPost('tax') ?? []),
                $validated,
                $this->auth()->id(),
            );
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $msg = $validated
            ? 'Parâmetros tributários salvos e marcados como validados pelo contador.'
            : 'Parâmetros tributários salvos sem validação. Transmissão permanece bloqueada até a marcação.';

        return redirect()->to('fiscal/company')->with('success', $msg);
    }

    public function uploadCertificate(): RedirectResponse
    {
        $company = (new FiscalCompanyService())->getOrEmpty();
        if ($company === []) {
            return redirect()->to('fiscal/company')->with('error', 'Cadastre a empresa antes do certificado.');
        }

        $file = $this->request->getFile('certificate');
        $password = (string) $this->request->getPost('password');
        $label = (string) ($this->request->getPost('label') ?: 'A1');

        try {
            if ($file === null) {
                throw new ValidationException(['certificate' => 'Selecione o arquivo .pfx/.p12.']);
            }
            (new CertificateStorageService())->store((int) $company['id'], $file, $password, $this->auth()->id(), $label);
        } catch (ValidationException $e) {
            return $this->backWithErrors($e->getErrors());
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to('fiscal/company')->with('success', 'Certificado A1 armazenado com senha cifrada. O arquivo não é exposto no painel.');
    }

    public function statusServico(): RedirectResponse
    {
        try {
            $result = (new NfeEmissionService())->statusServico();
            $msg = sprintf(
                'SEFAZ (%s): cStat=%s — %s',
                $result['environment'],
                $result['cStat'],
                $result['xMotivo'],
            );

            return redirect()->to('fiscal')->with('success', $msg);
        } catch (BusinessRuleException $e) {
            return redirect()->to('fiscal')->with('error', $e->getMessage());
        }
    }

    public function prepareFromOrder(int $orderId): RedirectResponse
    {
        try {
            $invoice = (new NfeEmissionService())->prepareFromOrder($orderId, $this->auth()->id());
        } catch (BusinessRuleException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to('fiscal/' . $invoice['id'])->with('success', 'Rascunho fiscal criado. Tributação e transmissão dependem da validação do contador.');
    }

    public function transmit(int $id): RedirectResponse
    {
        try {
            $invoice = (new NfeEmissionService())->transmit($id, $this->auth()->id());
        } catch (BusinessRuleException $e) {
            return redirect()->to('fiscal/' . $id)->with('error', $e->getMessage());
        }

        return redirect()->to('fiscal/' . $id)->with(
            'success',
            'Transmissão concluída: ' . ($invoice['status'] ?? '') . ' — ' . ($invoice['sefaz_message'] ?? ''),
        );
    }

    public function consultar(int $id): RedirectResponse
    {
        try {
            $invoice = (new NfeEmissionService())->consultar($id, $this->auth()->id());
        } catch (BusinessRuleException $e) {
            return redirect()->to('fiscal/' . $id)->with('error', $e->getMessage());
        }

        return redirect()->to('fiscal/' . $id)->with(
            'success',
            'Consulta: cStat=' . ($invoice['sefaz_status_code'] ?? '') . ' — ' . ($invoice['sefaz_message'] ?? ''),
        );
    }

    public function cancelar(int $id): RedirectResponse
    {
        $justificativa = trim((string) $this->request->getPost('justificativa'));
        try {
            (new NfeEmissionService())->cancelar($id, $justificativa, $this->auth()->id());
        } catch (BusinessRuleException $e) {
            return redirect()->to('fiscal/' . $id)->with('error', $e->getMessage());
        }

        return redirect()->to('fiscal/' . $id)->with('success', 'Cancelamento enviado à SEFAZ.');
    }

    public function gerarDanfe(int $id): RedirectResponse
    {
        try {
            (new NfeEmissionService())->gerarDanfe($id);
        } catch (BusinessRuleException $e) {
            return redirect()->to('fiscal/' . $id)->with('error', $e->getMessage());
        }

        return redirect()->to('fiscal/' . $id)->with('success', 'DANFE gerado.');
    }

    public function downloadXml(int $id, string $kind): ResponseInterface|RedirectResponse
    {
        $invoice = (new FiscalInvoiceModel())->find($id);
        if ($invoice === null) {
            return redirect()->to('fiscal')->with('error', 'Documento não encontrado.');
        }
        $path = $kind === 'authorized'
            ? ($invoice['xml_authorized_path'] ?? null)
            : ($invoice['xml_signed_path'] ?? null);
        if (! $path) {
            return redirect()->to('fiscal/' . $id)->with('error', 'XML indisponível.');
        }

        try {
            $xml = (new FiscalDocumentStorage())->read($path);
        } catch (BusinessRuleException $e) {
            return redirect()->to('fiscal/' . $id)->with('error', $e->getMessage());
        }

        $name = ($invoice['access_key'] ?: ('nfe-' . $id)) . '-' . $kind . '.xml';

        return $this->response
            ->setHeader('Content-Type', 'application/xml; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setBody($xml);
    }

    public function downloadDanfe(int $id): ResponseInterface|RedirectResponse
    {
        $invoice = (new FiscalInvoiceModel())->find($id);
        if ($invoice === null || empty($invoice['danfe_path'])) {
            return redirect()->to('fiscal/' . $id)->with('error', 'DANFE indisponível. Gere antes.');
        }

        try {
            $pdf = (new FiscalDocumentStorage())->read((string) $invoice['danfe_path']);
        } catch (BusinessRuleException $e) {
            return redirect()->to('fiscal/' . $id)->with('error', $e->getMessage());
        }

        $name = ($invoice['access_key'] ?: ('nfe-' . $id)) . '-danfe.pdf';

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setBody($pdf);
    }

    public function sendToMercadoLivre(int $id): RedirectResponse
    {
        try {
            (new MercadoLivreFiscalDocumentService())->sendAuthorizedXml($id, $this->auth()->id());
        } catch (BusinessRuleException $e) {
            return redirect()->to('fiscal/' . $id)->with('error', $e->getMessage());
        }

        return redirect()->to('fiscal/' . $id)->with('success', 'XML enviado ao Mercado Livre.');
    }
}
