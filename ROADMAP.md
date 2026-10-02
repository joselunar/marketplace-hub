# Roadmap

## Fase 1 — Base ✅

- Estrutura CodeIgniter 4 e configuração por `.env`
- MySQL via Docker Compose (bancos de aplicação e de testes)
- Layout administrativo com Bootstrap 5
- Autenticação com sessão, papéis (admin/operador), throttle de login
- CSRF, escape de saída, cabeçalhos de segurança
- Usuários, configurações do sistema, auditoria e logs de sincronização
- README, seeders e testes automatizados

## Fase 2 — Fornecedores e catálogo ✅

- CRUD de fornecedores (tipo de integração, prioridade, prazo, status)
- CRUD de categorias hierárquicas
- CRUD de produtos com imagens, dimensões e peso
- Ofertas de fornecedor (custo, estoque, frete, prazo) com vínculo a produtos
- Arquitetura de conectores (manual, CSV; API/XML por adaptador registrado)
- Importação CSV com vínculo automático por SKU/EAN e histórico
- API interna de produtos e fornecedores

## Fase 3 — Motor de seleção ✅

- `SupplierSelectionService`: fornecedor principal e duas reservas por produto
- Estoque de segurança global, por fornecedor e por produto (o mais específico prevalece)
- Critérios eliminatórios, incluindo bloqueio manual da oferta com motivo
- Ranking ponderado (preço, estoque, prazo, confiabilidade, prioridade) com pesos configuráveis
- Confiabilidade calculada a partir do histórico de sincronizações
- Troca automática quando o principal fica inelegível, com histórico e auditoria
- Explicação da escolha na tela do produto, alerta no dashboard e endpoint `GET /api/products/{id}/selection`
- Comando `php spark suppliers:select` para o cron

## Fase 4 — Precificação ✅

- `PriceCalculator` (puro) e `PricingService`: custo + frete + comissão e tarifa fixa do marketplace + impostos + custos extras + margem sobre o preço de venda
- Margens desejada e mínima por produto, categoria (herdada pelas subcategorias) e global
- Tarifa fixa por faixa de preço e arredondamento configurável, sempre para cima
- Preço fixo opcional no produto, recusado quando nenhum fornecedor atinge a margem mínima
- Critério eliminatório de margem mínima no motor de seleção, com troca automática de fornecedor
- Composição do preço na tela do produto, simulador de preços, filtro e alerta de margem abaixo da mínima
- Endpoints `GET /api/products/{id}/pricing` e `GET /api/pricing/simulate`
- Tarifas do marketplace como configuração (nunca fixas no código)

## Fase 5 — Mercado Livre: OAuth ✅

- Estrutura genérica de marketplaces (`marketplaces`, `marketplace_accounts`)
- Fluxo OAuth 2.0 (Authorization Code) conforme a documentação oficial, com `state` de uso único e PKCE S256
- Access/refresh token cifrados em repouso, mascarados na tela e fora de logs, auditoria e API
- Renovação automática antes do vencimento e em 401, com trava por conta por causa do refresh token de uso único
- Contas que precisam de nova autorização marcadas como "Reconectar", com alerta no dashboard
- Tela de contas conectadas (conectar, renovar, desconectar com revogação no Mercado Livre)
- Explorador de categorias, atributos (obrigatórios destacados) e preditor de categoria
- Comando `php spark meli:refresh-token` para o cron e endpoint `GET /api/marketplaces/accounts`

## Fase 6 — Publicações

- Criação e atualização de anúncios (modelo User Products quando aplicável)
- Vínculo produto ↔ anúncio e histórico de publicações
- Atualização de preço e estoque
- Tarifas do anúncio consultadas na API oficial do Mercado Livre e usadas no cálculo do preço
- Publicação automática desabilitada por padrão

## Fase 7 — Pedidos

- Recebimento e listagem de pedidos
- Associação do pedido ao fornecedor selecionado
- Status e acompanhamento

## Fase 8 — Automação

- Comandos `spark` de sincronização executados por cron
- Fila de jobs com novas tentativas e registro em `sync_logs`
- Webhooks do Mercado Livre com validação de origem
- Atualização automática de estoque e preço (opcional, via configurações)

## Fase 9 — Outros marketplaces

- Interface comum de marketplace
- Novos adaptadores (ex.: Shopee, Amazon, Magalu) seguindo as APIs oficiais
