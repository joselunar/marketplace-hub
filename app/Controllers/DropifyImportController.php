<?php

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\SupplierImportModel;
use App\Models\SupplierModel;
use App\Services\Suppliers\Connectors\DropifyCsvConnector;
use App\Services\Suppliers\DropifyImportService;
use CodeIgniter\HTTP\RedirectResponse;

class DropifyImportController extends BaseController
{
    private const MAX_FILE_KB = 5120;

    public function new(): string|RedirectResponse
    {
        if (! service('settings')->getBool('dropify.csv_enabled')) {
            return redirect()->to('imports')->with('error', 'Importação CSV Dropify desabilitada nas configurações.');
        }

        return view('imports/dropify_new', [
            'title'       => 'Importar CSV Dropify',
            'suppliers'   => $this->dropifySuppliers(),
            'supplierId'  => (int) $this->request->getGet('supplier_id'),
            'maxFileKb'   => self::MAX_FILE_KB,
            'maxRows'     => DropifyCsvConnector::MAX_ROWS,
            'required'    => DropifyCsvConnector::REQUIRED_COLUMNS,
            'breadcrumbs' => ['Importações' => 'imports', 'Dropify CSV' => null],
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
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $storedName = $file->getRandomName();
        $file->move($directory, $storedName);
        $path = $directory . DIRECTORY_SEPARATOR . $storedName;

        try {
            $importId = (new DropifyImportService())->importCsv(
                (int) $this->request->getPost('supplier_id'),
                $path,
                $originalName,
                $this->auth()->id(),
            );
        } catch (BusinessRuleException $e) {
            return $this->backWithErrors(['file' => $e->getMessage()], 'A importação Dropify falhou.');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        return redirect()->to('/imports/' . $importId)->with('success', 'Importação Dropify concluída. Veja a rentabilidade dos produtos.');
    }

    /**
     * @return array<int|string, string>
     */
    private function dropifySuppliers(): array
    {
        $model = new SupplierModel();
        $rows  = $model->where('status', 'active')
            ->groupStart()
            ->where('provider_code', DropifyImportService::PROVIDER)
            ->orWhere('provider_code', null)
            ->orWhere('provider_code', '')
            ->groupEnd()
            ->orderBy('name')
            ->findAll();

        $options = [];
        foreach ($rows as $row) {
            $suffix = ($row['provider_code'] ?? '') === DropifyImportService::PROVIDER ? ' (Dropify)' : '';
            $options[(int) $row['id']] = $row['name'] . $suffix;
        }

        return $options;
    }
}
