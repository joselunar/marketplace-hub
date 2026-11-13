<?php

namespace App\Services\Fiscal;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\FiscalCertificateModel;
use App\Models\FiscalCompanyModel;
use App\Services\Security\TokenCipher;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Armazena certificado A1 fora do webroot e cifra a senha.
 * Nunca expõe o conteúdo do .pfx nas respostas.
 */
class CertificateStorageService
{
    public function __construct(
        private readonly FiscalCertificateModel $certificates = new FiscalCertificateModel(),
        private readonly FiscalCompanyModel $companies = new FiscalCompanyModel(),
        private readonly ?TokenCipher $cipher = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function store(int $companyId, UploadedFile $file, string $password, ?int $userId, string $label = 'A1'): array
    {
        if (! $file->isValid() || $file->hasMoved()) {
            throw new ValidationException(['certificate' => 'Envie um arquivo .pfx/.p12 válido.']);
        }
        $ext = strtolower($file->getExtension() ?: '');
        if (! in_array($ext, ['pfx', 'p12'], true)) {
            throw new ValidationException(['certificate' => 'Formato inválido. Use certificado A1 (.pfx ou .p12).']);
        }
        if ($password === '') {
            throw new ValidationException(['password' => 'Informe a senha do certificado.']);
        }

        $company = $this->companies->find($companyId);
        if ($company === null) {
            throw new BusinessRuleException('Empresa fiscal não encontrada.');
        }

        $dir = WRITEPATH . 'fiscal/certificates';
        $this->ensurePrivateDir($dir);

        $name = 'company_' . $companyId . '_' . date('YmdHis') . '.' . $ext;
        $file->move($dir, $name, true);
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        @chmod($path, 0600);

        try {
            $meta = $this->probe($path, $password, (string) $company['document']);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        $previous = $this->certificates->activeForCompany($companyId);
        $this->certificates->where('fiscal_company_id', $companyId)->set(['active' => 0])->update();

        $this->certificates->insert([
            'fiscal_company_id'  => $companyId,
            'label'              => mb_substr($label !== '' ? $label : 'A1', 0, 120),
            'storage_path'       => $path,
            'password_encrypted' => $this->cipher()->encrypt($password),
            'subject'            => $meta['subject'],
            'serial_number'      => $meta['serial'],
            'valid_from'         => $meta['valid_from'],
            'valid_to'           => $meta['valid_to'],
            'active'             => 1,
            'created_by'         => $userId,
        ]);

        // Remove arquivo anterior do disco somente após gravar o novo com sucesso.
        if ($previous !== null && ! empty($previous['storage_path']) && is_file($previous['storage_path'])) {
            $old = (string) $previous['storage_path'];
            if ($old !== $path && str_starts_with($old, WRITEPATH . 'fiscal/certificates')) {
                @unlink($old);
            }
        }

        $row = $this->certificates->find($this->certificates->getInsertID());
        // Resposta administrativa sem caminhos internos nem senha.
        unset($row['password_encrypted'], $row['storage_path']);

        return $row;
    }

    /**
     * @return array{content: string, password: string, meta: array<string, mixed>}
     */
    public function loadActive(int $companyId): array
    {
        $row = $this->certificates->activeForCompany($companyId);
        if ($row === null) {
            throw new BusinessRuleException('Nenhum certificado A1 ativo cadastrado.');
        }
        if (! is_file($row['storage_path'])) {
            throw new BusinessRuleException('Arquivo do certificado não encontrado no armazenamento seguro.');
        }
        $password = $this->cipher()->decrypt($row['password_encrypted']);
        $content  = file_get_contents($row['storage_path']);
        if ($content === false || $content === '') {
            throw new BusinessRuleException('Não foi possível ler o certificado A1.');
        }

        return [
            'content'  => $content,
            'password' => $password,
            'meta'     => $row,
        ];
    }

    /**
     * Metadados públicos do A1 ativo (sem senha/caminho).
     *
     * @return array<string, mixed>|null
     */
    public function publicMeta(int $companyId): ?array
    {
        $row = $this->certificates->activeForCompany($companyId);
        if ($row === null) {
            return null;
        }

        return [
            'label'      => $row['label'] ?? 'A1',
            'subject'    => $row['subject'] ?? null,
            'valid_from' => $row['valid_from'] ?? null,
            'valid_to'   => $row['valid_to'] ?? null,
            'active'     => (int) ($row['active'] ?? 0) === 1,
        ];
    }

    /**
     * @return array{subject: ?string, serial: ?string, valid_from: ?string, valid_to: ?string}
     */
    private function probe(string $path, string $password, string $companyDocument): array
    {
        if (! class_exists(\NFePHP\Common\Certificate::class)) {
            throw new BusinessRuleException('Biblioteca NFePHP Common ausente para validar o certificado.');
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new BusinessRuleException('Falha ao ler o arquivo do certificado.');
        }

        try {
            $cert = \NFePHP\Common\Certificate::readPfx($raw, $password);
        } catch (\Throwable $e) {
            throw new ValidationException(['certificate' => 'Certificado ou senha inválidos.']);
        }

        if (method_exists($cert, 'isExpired') && $cert->isExpired()) {
            throw new ValidationException(['certificate' => 'O certificado A1 está vencido.']);
        }

        $companyDoc = preg_replace('/\D+/', '', $companyDocument) ?? '';
        $certCnpj = method_exists($cert, 'getCnpj') ? preg_replace('/\D+/', '', (string) $cert->getCnpj()) : '';
        $certCpf  = method_exists($cert, 'getCpf') ? preg_replace('/\D+/', '', (string) $cert->getCpf()) : '';
        $certDoc  = $certCnpj !== '' ? $certCnpj : $certCpf;

        if ($companyDoc !== '' && $certDoc !== '' && $companyDoc !== $certDoc) {
            throw new ValidationException([
                'certificate' => 'O CNPJ/CPF do certificado não confere com o documento cadastrado na empresa fiscal.',
            ]);
        }

        $validFrom = method_exists($cert, 'getValidFrom') && $cert->getValidFrom() instanceof \DateTimeInterface
            ? $cert->getValidFrom()->format('Y-m-d H:i:s')
            : null;
        $validTo = method_exists($cert, 'getValidTo') && $cert->getValidTo() instanceof \DateTimeInterface
            ? $cert->getValidTo()->format('Y-m-d H:i:s')
            : null;

        return [
            'subject'    => method_exists($cert, 'getCompanyName') ? (string) $cert->getCompanyName() : null,
            'serial'     => $certDoc !== '' ? $certDoc : null,
            'valid_from' => $validFrom,
            'valid_to'   => $validTo,
        ];
    }

    private function ensurePrivateDir(string $dir): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new BusinessRuleException('Não foi possível criar o diretório seguro do certificado.');
        }
        @chmod($dir, 0700);
        $index = $dir . DIRECTORY_SEPARATOR . 'index.html';
        if (! is_file($index)) {
            @file_put_contents($index, "<!DOCTYPE html><title>403</title><p>Forbidden</p>\n");
        }
    }

    private function cipher(): TokenCipher
    {
        return $this->cipher ?? new TokenCipher();
    }
}
