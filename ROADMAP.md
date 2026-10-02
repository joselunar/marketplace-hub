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

## Fase 6 — Publicações ✅

- Publicação com prévia obrigatória (validações, payload, preço, estoque e tarifa) e confirmação explícita
- Modelo User Products para vendedores com a tag `user_product_seller` (`family_name` em vez de `title`)
- Tarifas consultadas em `listing_prices` e usadas no cálculo do preço, com cache curto
- Vínculo produto ↔ anúncio (`marketplace_listings`) e histórico por anúncio (`marketplace_listing_events`)
- Sincronizar, atualizar preço, atualizar estoque, pausar e reativar
- Mapeamento categoria interna → categoria do Mercado Livre lembrado após cada publicação
- Publicação automática prevista nas configurações e desabilitada por padrão

## Fase 7 — Notificações, pedidos e pedidos aos fornecedores ✅

- Webhook `POST /webhooks/mercadolivre`: valida e grava em `webhook_events` (resposta rápida), com idempotência, validação do aplicativo, limite por IP e filtro de IP opcional
- Processamento posterior da fila (`meli:process-notifications` e tela de notificações), com novas tentativas, falha definitiva em 403/404 e agrupamento por recurso
- Pedidos (`orders`, `order_items`) lidos em `GET /orders/{id}` e importação manual por `GET /orders/search` (`meli:import-orders`)
- Item ligado ao produto pelo anúncio ou pelo SKU; fornecedores revalidados e reservados quando o pedido é pago, descontando o estoque já reservado
- Lucro estimado com a tarifa do pedido (ou estimada antes do crédito), custos, impostos e extras
- Pedido ao fornecedor (`supplier_orders`) pendente, botão "Enviar pedido ao fornecedor" sem compra automática e acompanhamento (despacho, entrega, rastreio)
- Tratamento de cancelamento e de risco de fraude
- Dashboard com pedidos, faturamento, lucro, margem e alerta de pedidos aguardando fornecedor; endpoints `GET /api/orders` e `GET /api/orders/{id}`

## Fase 8 — Automação ✅

- Fila de jobs no banco (`jobs`) com chave única contra duplicidade, reserva atômica, novas tentativas com espera crescente para falhas temporárias e falha definitiva registrada em `sync_logs`
- Agendador (`schedule:run`) e worker (`jobs:work`) em uma única linha de cron por minuto, com sinal de vida de cada um (`scheduled_tasks`)
- Jobs: estoque e custos dos fornecedores, recálculo de fornecedores e preços, estoque e preço dos anúncios, renovação de tokens, notificações e importação de pedidos
- Sincronização com fornecedores só via adapter registrado, alterando apenas estoque ou custos das ofertas existentes
- Atualização automática de estoque e preço dos anúncios (opcional, desligada por padrão), disparada por mudanças e por conferência periódica; preço abaixo da mínima nunca é enviado
- Tela de sincronizações (tarefas, executar agora, fila, repetir, cancelar) e comandos `suppliers:sync`, `meli:sync-stock`, `meli:sync-prices` e `marketplace:sync`
- Dashboard completo: anúncios ativos, vendas dos últimos 14 dias, pedidos recentes, produtos com maior lucro e alertas de agendador parado, jobs com falha, anúncios com erro e tokens prestes a vencer

## Fase 9 — Outros marketplaces ✅

- Interface comum (`MarketplaceAdapter`) com capacidades declaradas: renovação de token, anúncios, estoque, preço, pedidos e notificações; o que o adaptador não oferece é recusado com mensagem clara e falha definitiva na fila
- Registro de adaptadores em `Config\Marketplaces`, com o Mercado Livre como primeiro adaptador
- Jobs e tarefas genéricos (`RefreshMarketplaceTokenJob`, `ImportMarketplaceOrdersJob`, `ProcessMarketplaceNotificationsJob`, `SyncListingStockJob`, `SyncListingPriceJob`) que resolvem o adaptador pela conta ou pelo anúncio; nenhuma conta, anúncio ou notificação vai para o código de outro marketplace
- Erros de API e transporte HTTP independentes do Mercado Livre (`MarketplaceApiError`, `App\Services\Http`)
- Tarifas padrão por marketplace (comissão, tarifa fixa e faixa), herdando as globais, usadas no simulador, na prévia e no lucro estimado dos pedidos
- Tela **Marketplaces** (situação da integração, variáveis que faltam, contas, anúncios ativos, capacidades e tarifas) e endpoint `GET /api/marketplaces`
- Pedidos com importação e atualização pelo adaptador da conta, telas e mensagens sem referência fixa ao Mercado Livre
- Guia "Adicionando um marketplace" no README; Shopee, Amazon e Magalu entram por adaptadores escritos a partir da documentação oficial de cada um, quando houver credenciais de parceiro
