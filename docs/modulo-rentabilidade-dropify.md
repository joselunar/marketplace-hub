# Módulo de rentabilidade pré-publicação (Dropify)

Implementação local da Fase 1 do prompt de rentabilidade. **Não faz push/deploy, não publica anúncios, não compra na Dropify e não emite NF-e.**

## O que foi entregue

1. **Importação CSV Dropify** (`/imports/dropify`) — idempotente por `(supplier_id, supplier_sku)`, cria produto `DFY-{SKU}` + oferta + imagens por URL.
2. **Motor de rentabilidade** (`ProfitabilityEngine`) — classificação `rentavel` / `margem_baixa` / `prejuizo` / `aguardando_dados`.
3. **Cotações ML somente leitura** — `listing_prices` (Clássico/Premium) e tentativa de `/users/{id}/shipping_options/free`. Custos sem evidência ficam **DESCONHECIDO**.
4. **Painel** `/profitability` + detalhe + simulador de preço + histórico.
5. **Checklist Dropify** `/profitability/checklist` (itens iniciam `pendente`; confirmado exige evidência).
6. **Bloqueio de publicação** em `MercadoLivreProductService::publish` e na prévia, para produtos `source_provider=dropify`.
7. **Configurações** nos grupos `profitability`, `fixed_costs` e `dropify` (margem mínima 20%, reservas, mensalidades editáveis).

## Como validar localmente

```bash
# 1) Migration aditiva (ambiente local/homologação apenas)
php spark migrate

# 2) Atualizar defaults de settings
php spark db:seed SettingsSeeder

# 3) Testes
vendor/bin/phpunit --filter Profitability
vendor/bin/phpunit --filter Dropify
```

No painel:

1. Cadastre ou edite um fornecedor (a importação marca `provider_code=dropify`).
2. Em **Importar Dropify**, envie `tests/fixtures/dropify/produtos-amostra.csv` (ou o `produtos.csv` real).
3. Abra **Catálogo > Rentabilidade**.
4. Confira o checklist; não marque confirmado sem evidência.
5. Tentar publicar um produto Dropify com análise `prejuizo`/`aguardando_dados` deve falhar no backend.

## Feature flags (Configurações)

| Chave | Default | Efeito |
|-------|---------|--------|
| `profitability.enabled` | true | Mostra o módulo |
| `profitability.block_unsafe_publish` | true | Bloqueia publicação Dropify insegura |
| `profitability.require_logistics_checklist` | true | Exige checklist 100% confirmado com evidência |
| `dropify.csv_enabled` | true | Libera `/imports/dropify` |
| `dropify.api_enabled` | false | API Dropify desligada (fase 2) |

## Pendências externas

- Arquivo `produtos.csv` real da Dropify (amostra de teste inclusa no repositório).
- Conta ML conectada + `MELI_LOGISTIC_TYPE` / `MELI_SHIPPING_MODE` + categoria MLB mapeada para tarifas/frete **confirmados**.
- Respostas escritas da Dropify no checklist.
- Credenciais/docs da API Dropify para fase 2.
- Aprovação do contador para `profitability.tax_approved`.

## Arquivos principais

- Migration: `app/Database/Migrations/2026-11-13-000020_AddProfitabilityAndDropifyCatalog.php`
- Serviços: `app/Services/Profitability/*`, `app/Services/Suppliers/DropifyImportService.php`, `DropifyCsvConnector.php`
- Controllers/Views: `ProfitabilityController`, `DropifyImportController`, `app/Views/profitability/*`
- Testes: `tests/unit/Profitability/*`, `tests/unit/Suppliers/DropifyCsvConnectorTest.php`, `tests/database/Profitability/*`
