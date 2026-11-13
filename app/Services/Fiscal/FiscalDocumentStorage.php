<?php

namespace App\Services\Fiscal;

use App\Exceptions\BusinessRuleException;

class FiscalDocumentStorage
{
    public function storeXml(string $accessKeyOrId, string $xml, string $kind): string
    {
        $dir = WRITEPATH . 'fiscal/xml/' . $kind;
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new BusinessRuleException('Não foi possível criar o diretório de XML fiscal.');
        }
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $accessKeyOrId) ?: ('doc_' . time());
        $path = $dir . DIRECTORY_SEPARATOR . $safe . '.xml';
        if (file_put_contents($path, $xml) === false) {
            throw new BusinessRuleException('Falha ao gravar XML fiscal.');
        }

        return $path;
    }

    public function storeDanfe(string $accessKeyOrId, string $pdfBinary): string
    {
        $dir = WRITEPATH . 'fiscal/danfe';
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new BusinessRuleException('Não foi possível criar o diretório de DANFE.');
        }
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $accessKeyOrId) ?: ('danfe_' . time());
        $path = $dir . DIRECTORY_SEPARATOR . $safe . '.pdf';
        if (file_put_contents($path, $pdfBinary) === false) {
            throw new BusinessRuleException('Falha ao gravar DANFE.');
        }

        return $path;
    }

    public function read(string $path): string
    {
        if (! is_file($path)) {
            throw new BusinessRuleException('Arquivo fiscal não encontrado.');
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new BusinessRuleException('Falha ao ler arquivo fiscal.');
        }

        return $content;
    }
}
