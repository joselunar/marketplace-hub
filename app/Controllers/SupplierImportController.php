<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\SupplierImportModel;
use App\Models\SupplierModel;
use App\Services\Suppliers\Connectors\CsvSupplierConnector;
use App\Services\Suppliers\SupplierImportService;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\RedirectResponse;

class SupplierImportController extends BaseController
{
    private const MAX_FILE_KB = 5120;

    public function index(): string
    {
        $filters = $this->filters(['supplier_id', 'status']);
        $model   = new SupplierImportModel();
        $model->withRelations();

        if (isset($filters['supplier_id'])) {
            $model->where('supplier_imports.supplier_id', (int) $filters['supplier_id']);
        }

        if (isset($filters['status'])) {
            $model->where('supplier_imports.status', $filters['status']);
        }

        return view('imports/index', [
            'title'       => 'Importações',
            'imports'     => $model->orderBy('supplier_imports.id', 'DESC')->paginate($this->perPage()),
            'pager'       => $model->pager,
            'filters'     => $filters,
            'suppliers'   => (new SupplierModel())->dropdownOptions(),
            'breadcrumbs' => ['Fornecedores' => null, 'Importações' => null],
        ]);
    }

    public function new(): string
    {
        return view('imports/new', [
            'title'       => 'Importar planilha CSV',
            'suppliers'   => (new SupplierModel())->dropdownOptions(),
            'supplierId'  => (int) $this->request->getGet('supplier_id'),
            'maxFileKb'   => self::MAX_FILE_KB,
            'maxRows'     => CsvSupplierConnector::MAX_ROWS,
            'aliases'     => CsvSupplierConnector::HEADER_ALIASES,
            'required'    => CsvSupplierConnector::REQUIRED_COLUMNS,
            'breadcrumbs' => ['Importações' => 'imports', 'Nova' => null],
        ]);
    }

    public function create(): RedirectResponse
    {
        $rules = [
            'supplier_id' => ['label' => 'Fornecedor', 'rules' => 'required|is_natural_no_zero|is_not_unique[suppliers.id]'],
            'file'        => [
                'label' => 'Arquivo',
                'rules' => 'uploaded[file]|max_size[file,' . self::MAX_FILE_KB . ']|ext_in[file,csv,txt]'
                    . '|mime_in[file,text/csv,text/plain,application/csv,application/vnd.ms-excel,text/x-csv]',
            ],
        ];

        if (! $this->validate($rules)) {
            return $this->backWithErrors($this->validator->getErrors());
        }

        $file = $this->request->getFile('file');

        if ($file === null || ! $file->isValid() || $file->hasMoved()) {
            return $this->backWithErrors(['file' => 'Falha no envio do arquivo.']);
        }

        $originalName = $file->getClientName();
        $directory    = WRITEPATH . 'uploads/imports';
        $storedName   = $file->getRandomName();
        $file->move($directory, $storedName);
        $path = $directory . DIRECTORY_SEPARATOR . $storedName;

        try {
            $importId = (new SupplierImportService())->importCsv(
                (int) $this->request->getPost('supplier_id'),
                $path,
                $originalName,
                $this->auth()->id(),
            );
        } catch (BusinessRuleException $e) {
            return $this->backWithErrors(['file' => $e->getMessage()], 'A importação falhou.');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        return redirect()->to("/imports/{$importId}")->with('success', 'Importação concluída.');
    }

    public function show(int $id): string|RedirectResponse
    {
        $import = (new SupplierImportModel())->withRelations()->find($id);

        if ($import === null) {
            return redirect()->to('/imports')->with('error', 'Importação não encontrada.');
        }

        $errors = $import['errors'] !== null ? (json_decode($import['errors'], true) ?: []) : [];

        return view('imports/show', [
            'title'       => 'Importação #' . $import['id'],
            'import'      => $import,
            'errors'      => $errors,
            'breadcrumbs' => ['Importações' => 'imports', '#' . $import['id'] => null],
        ]);
    }

    /**
     * Modelo de planilha com as colunas aceitas.
     */
    public function template(): DownloadResponse
    {
        $content = "supplier_sku;name;cost_price;stock_quantity;ean;brand;shipping_cost;shipping_days;product_sku\n"
            . "ABC-001;Fone Bluetooth Exemplo;49,90;25;7890000000001;Marca Exemplo;12,50;2;\n";

        return $this->response->download('modelo-importacao-fornecedor.csv', "\xEF\xBB\xBF" . $content)->setContentType('text/csv');
    }
}
