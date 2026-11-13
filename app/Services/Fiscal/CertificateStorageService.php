<?php

namespace App\Services\Fiscal;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Models\FiscalCertificateModel;
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

        $dir = WRITEPATH . 'fiscal/certificates';
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new BusinessRuleException('Não foi possível criar o diretório seguro do certificado.');
        }

        $name = 'company_' . $companyId . '_' . date('YmdHis') . '.' . $ext;
        $file->move($dir, $name, true);
        $path = $dir . DIRECTORY_SEPARATOR . $name;

        $meta = $this->probe($path, $password);

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

        return $this->certificates->find($this->certificates->getInsertID());
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
     * @return array{subject: ?string, serial: ?string, valid_from: ?string, valid_to: ?string}
     */
    private function probe(string $path, string $password): array
    {
        if (! class_exists(\NFePHP\Common\Certificate::class)) {
            return ['subject' => null, 'serial' => null, 'valid_from' => null, 'valid_to' => null];
        }

        try {
            $raw = file_get_contents($path);
            if ($raw === false) {
                throw new BusinessRuleException('Falha ao ler o arquivo do certificado.');
            }
            $cert = \NFePHP\Common\Certificate::readPfx($raw, $password);

            return [
                'subject'    => method_exists($cert, 'getCompanyName') ? (string) $cert->getCompanyName() : null,
                'serial'     => null,
                'valid_from' => null,
                'valid_to'   => method_exists($cert, 'getValidTo') && $cert->getValidTo() instanceof \DateTimeInterface
                    ? $cert->getValidTo()->format('Y-m-d H:i:s')
                    : null,
            ];
        } catch (\Throwable $e) {
            @unlink($path);
            throw new ValidationException(['certificate' => 'Certificado ou senha inválidos: ' . $e->getMessage()]);
        }
    }

    private function cipher(): TokenCipher
    {
        return $this->cipher ?? new TokenCipher();
    }
}
