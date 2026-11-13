<?php

namespace App\Services\Fiscal;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\FiscalCompanyModel;
use App\Support\Input;

class FiscalCompanyService
{
    public function __construct(
        private readonly FiscalCompanyModel $companies = new FiscalCompanyModel(),
    ) {
    }

    public function getOrEmpty(): array
    {
        return $this->companies->primaryCompany() ?? [];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input, ?int $userId = null): array
    {
        $data = Input::only($input, [
            'legal_name', 'trade_name', 'document', 'state_registration', 'municipal_registration',
            'crt', 'tax_regime_note', 'zip_code', 'street', 'number', 'complement', 'district',
            'city', 'city_ibge', 'uf', 'phone', 'email', 'serie_nfe', 'next_number_nfe',
        ]);

        $data['document'] = Input::digits((string) ($data['document'] ?? '')) ?? '';
        $data['zip_code'] = Input::digits((string) ($data['zip_code'] ?? null));
        $data['uf']       = strtoupper(trim((string) ($data['uf'] ?? '')));
        $data['serie_nfe'] = max(1, (int) ($data['serie_nfe'] ?? 1));
        $data['next_number_nfe'] = max(1, (int) ($data['next_number_nfe'] ?? 1));
        $data['crt'] = ($data['crt'] ?? '') === '' ? null : (int) $data['crt'];

        if ($data['legal_name'] === '' || strlen($data['document']) < 11) {
            throw new ValidationException([
                'legal_name' => 'Informe a razão social.',
                'document'   => 'Informe CPF/CNPJ válido.',
            ]);
        }
        if ($data['uf'] !== '' && ! preg_match('/^[A-Z]{2}$/', $data['uf'])) {
            throw new ValidationException(['uf' => 'UF inválida.']);
        }

        $existing = $this->companies->primaryCompany();
        if ($existing === null) {
            $this->companies->insert($data);
            $id = (int) $this->companies->getInsertID();
        } else {
            // Não sobrescreve validação tributária neste formulário.
            unset($data['tax_params_json'], $data['tax_params_validated']);
            $this->companies->update((int) $existing['id'], $data);
            $id = (int) $existing['id'];
        }

        return $this->companies->find($id);
    }

    /**
     * Parâmetros tributários só avançam com marcação explícita de validação contábil.
     *
     * @param array<string, mixed> $params
     */
    public function saveTaxParams(int $companyId, array $params, bool $validated, ?int $userId): array
    {
        $company = $this->companies->find($companyId);
        if ($company === null) {
            throw new BusinessRuleException('Empresa fiscal não encontrada.');
        }

        // Não inventa CFOP/CSOSN: apenas armazena o que o usuário/contador informar.
        $clean = [];
        foreach (['cfop', 'csosn', 'cst', 'origem', 'ncm_default', 'natureza_operacao', 'informacoes_complementares'] as $key) {
            if (! array_key_exists($key, $params)) {
                continue;
            }
            $value = trim((string) $params[$key]);
            $clean[$key] = $value === '' ? null : $value;
        }

        $this->companies->update($companyId, [
            'tax_params_json'         => json_encode($clean, JSON_UNESCAPED_UNICODE),
            'tax_params_validated'    => $validated ? 1 : 0,
            'tax_params_validated_at' => $validated ? date('Y-m-d H:i:s') : null,
            'tax_params_validated_by' => $validated ? $userId : null,
        ]);

        return $this->companies->find($companyId);
    }
}
