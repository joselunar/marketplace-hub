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

## Fase 3 — Motor de seleção

- `SupplierSelectionService`: escolha do melhor fornecedor por produto
- Estoque de segurança (estoque disponível = estoque − margem de segurança)
- Ranking ponderado (preço, estoque, prazo, confiabilidade, prioridade) com pesos configuráveis
- Fallback automático quando o fornecedor escolhido fica sem estoque
- Explicação da escolha exibida na tela do produto

## Fase 4 — Precificação

- `PricingService`: custo + frete + impostos + tarifas + margem desejada
- Margem mínima e bloqueio de preços abaixo dela
- Regras de arredondamento e simulação de preço
- Tarifas do marketplace obtidas da fonte oficial, nunca fixas no código

## Fase 5 — Mercado Livre: OAuth

- Fluxo OAuth 2.0 conforme a documentação oficial
- Armazenamento criptografado de access/refresh token e renovação automática
- Tela de contas conectadas
- Consulta de categorias e atributos

## Fase 6 — Publicações

- Criação e atualização de anúncios (modelo User Products quando aplicável)
- Vínculo produto ↔ anúncio e histórico de publicações
- Atualização de preço e estoque
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
