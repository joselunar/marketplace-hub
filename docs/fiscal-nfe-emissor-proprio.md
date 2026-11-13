# Emissor próprio de NF-e (Vitrine Hub)

## Abordagem

O Venus (`sistema_asaas` + `venus_vendor`) já emite NF-e com **NFePHP** (`nfephp-org/sped-nfe` ~5.0.x), assinatura A1, SOAP SEFAZ e DANFE via `sped-da`. O Vitrine Hub adota a **mesma família de bibliotecas open source**, em versão atualizada, com código próprio (sem copiar fontes proprietárias do Venus).

| Item | Venus | Vitrine Hub |
|------|-------|-------------|
| Biblioteca | nfephp-org/sped-nfe ~5.0.116 | nfephp-org/sped-nfe **5.2.11** |
| DANFE | sped-da | nfephp-org/sped-da **1.1.6** |
| Common | sped-common | nfephp-org/sped-common **5.1.18** |
| Licença | LGPL-3.0 / GPL-3.0 / MIT (NFePHP) | Idem |
| Framework | PHP legado | CodeIgniter 4 |
| Ambiente padrão | conforme instalação | **Homologação**; produção só com flag |

## O que foi implementado

- Cadastro da empresa fiscal, certificado A1 (fora do webroot, senha cifrada).
- Parâmetros tributários parametrizáveis; transmissão exige marcação de validação do contador.
- Preparação de rascunho a partir de pedido pago (anti-duplicação por status em andamento).
- Montagem XML modelo 55, assinatura, envio SEFAZ, consulta, cancelamento, DANFE, armazenamento de XML.
- Stub de envio ao Mercado Livre (`/shipments/{id}/invoice_data`) **bloqueado** em homologação e sem flags.
- Painel `/fiscal` com resumo, filtros, detalhe, downloads e configurações em Sistema → Fiscal.

## Flags de segurança (Configurações → Fiscal)

- `fiscal.enabled` — painel/rascunhos (default off).
- `fiscal.environment` — homologacao|producao (produção só se autorizada).
- `fiscal.real_emission_authorized` — **default off**.
- `fiscal.ml_xml_send_authorized` — **default off**.
- Numeração de homologação em série separada (`fiscal.homolog_serie` / `fiscal.homolog_next_number`).

## Requisitos VPS

- PHP 8.2+ com extensões: `soap`, `openssl`, `dom`, `simplexml`, `json`, `zlib`, `libxml`.
- Em `srv2029183` (produção Vitrine Hub) a extensão `php8.3-soap` foi instalada (CLI + FPM). O `composer.json` ainda declara `config.platform.ext-soap` para ambientes sem a extensão no CLI.
- Painel **Fiscal → Diagnóstico** (`/fiscal/diagnostico`) verifica bibliotecas, extensões, cadastro, A1, tributação e declaração de credenciamento.
- Após o deploy: `php spark migrate` e `php spark db:seed SettingsSeeder` (backup automático já ocorre no script de deploy).
- Certificado A1 da empresa e credenciamento estadual para NF-e.
- Regras tributárias (CFOP, CSOSN, CRT, NCM, venda à ordem / dropshipping) validadas pelo contador.

## Pendências para emissão real

1. Ativar `fiscal.enabled` e cadastrar empresa + A1.
2. Contador validar parâmetros e operação (incl. Dropify / venda à ordem).
3. Testar apenas em homologação SEFAZ.
4. Credenciamento e numeração de produção.
5. Autorização explícita: `fiscal.real_emission_authorized` e, se aplicável, `fiscal.ml_xml_send_authorized`.
6. Nunca enviar XML de homologação ao Mercado Livre.
