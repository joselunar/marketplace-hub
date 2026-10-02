# Marketplace Hub

## Descrição

Sistema web para gestão de produtos, fornecedores e integração com marketplaces. O primeiro marketplace será o Mercado Livre.

O catálogo é **interno e único**: um produto (SKU interno) pode receber ofertas de vários fornecedores, cada um com seu próprio custo, estoque, frete e prazo. A partir dessas ofertas, fases futuras do sistema escolhem o melhor fornecedor, calculam o preço de venda e publicam/sincronizam os anúncios nos marketplaces.

## Objetivo

- Centralizar o catálogo de produtos e as ofertas de fornecedores.
- Importar dados de fornecedores por API, XML, CSV ou cadastro manual (sem scraping).
- Escolher automaticamente o melhor fornecedor por produto, respeitando estoque de segurança e regras configuráveis.
- Calcular preço de venda com margens, impostos e tarifas configuráveis.
- Publicar e sincronizar anúncios no Mercado Livre e, depois, em outros marketplaces.
- Manter auditoria completa das alterações e das sincronizações.

Status atual: **Fases 1 a 8 concluídas** (base, autenticação, fornecedores, catálogo, ofertas, importação CSV, motor de seleção de fornecedores, precificação, conexão OAuth com o Mercado Livre, publicação de anúncios, notificações, pedidos, pedidos aos fornecedores, fila de jobs com agendador, atualização automática opcional de estoque e preço e dashboard completo). Veja o [ROADMAP](ROADMAP.md).

## Arquitetura

Monólito modular em CodeIgniter 4, organizado em MVC + camada de serviços:

```
app/
├── Commands/          # Comandos spark (agendador, worker, sincronizações, tokens, notificações, pedidos)
├── Config/            # Configurações (filtros, rotas, menu, catálogo de settings, conectores)
├── Controllers/       # Controllers finos: validam entrada HTTP e delegam aos services
│   └── Api/           # API interna JSON (autenticada por sessão)
├── Database/
│   ├── Migrations/    # Toda a estrutura do banco
│   └── Seeds/         # Dados de desenvolvimento
├── DTO/               # Objetos de transferência (fornecedores, ranking, precificação, tokens)
├── Enums/             # Papéis, status e tipos de integração
├── Exceptions/        # ValidationException, BusinessRuleException
├── Filters/           # auth, guest, role, limites de login, ações no marketplace e webhook
├── Helpers/           # Helpers de interface (formatação, badges)
├── Jobs/              # Jobs da fila (sincronizações, tokens, notificações, pedidos)
├── Models/            # Acesso a dados via Query Builder
├── Services/          # Regras de negócio
│   ├── Audit/         # Auditoria com remoção de dados sensíveis
│   ├── Auth/
│   ├── Automation/    # Fila de jobs, agendador e atualização automática dos anúncios
│   ├── Catalog/       # Categorias e produtos
│   ├── Dashboard/
│   ├── Marketplaces/  # Contas conectadas (genérico) e MercadoLivre/ (HTTP, OAuth, categorias, anúncios, pedidos, notificações)
│   ├── Orders/        # Pedidos: reserva de fornecedor, lucro estimado e pedidos aos fornecedores
│   ├── Pricing/       # Calculador de preço e resolução de margens
│   ├── Security/      # Cifragem e mascaramento de tokens
│   ├── Settings/
│   ├── Suppliers/     # Fornecedores, ofertas, importação, conectores e motor de seleção
│   └── Users/
├── Support/           # Utilitários (Decimal, Input)
└── Views/
```

Decisões principais:

- **Controllers finos, regras nos services.** Nenhuma regra de negócio fica em views ou em JavaScript.
- **Conectores de fornecedor por interface** (`SupplierConnectorInterface`). Hoje existem os conectores `manual` e `csv`. Conectores `api` e `xml` são registrados por fornecedor em `Config\SupplierConnectors`; enquanto não houver um adaptador real, o sistema recusa a operação em vez de simular a integração.
- **Ofertas podem existir sem produto vinculado.** Itens importados que não casam por SKU ou EAN ficam pendentes de vínculo manual.
- **Valores monetários em `DECIMAL`** no banco e cálculos com `bcmath` (`App\Support\Decimal`), nunca `float`.
- **Configurações no banco** (`settings`), com catálogo tipado em `Config\SystemSettings`. Margens, estoque de segurança e pesos de ranking não ficam fixos no código.
- **Auditoria** de toda criação, alteração e exclusão, com remoção automática de campos sensíveis (senhas, tokens, segredos).
- **Motor de seleção separado em duas partes**: `SupplierRanker` faz só o cálculo (sem banco, testável isoladamente) e `SupplierSelectionService` carrega os dados, grava o resultado e registra as trocas.
- **Precificação no mesmo padrão**: `PriceCalculator` é puro e `PricingService` resolve margens e parâmetros. O motor de seleção usa o calculador para aplicar a margem mínima a cada oferta e grava o preço do fornecedor escolhido, então seleção e preço nunca ficam dessincronizados.
- **Tarifas de marketplace nunca ficam no código.** Os valores de **Configurações** servem para o preço sugerido e o simulador; na publicação e na atualização de preço de um anúncio, a tarifa é consultada na API oficial do Mercado Livre (`listing_prices`) e o preço é recalculado com ela.
- **Nada pesado em requisição web.** Sincronizações rodam como jobs em fila no banco (`jobs`), executados pelo worker do cron com novas tentativas para falhas temporárias; a tela só enfileira.
- **Marketplaces genéricos**: `marketplaces` e `marketplace_accounts` não sabem nada do Mercado Livre; a integração fica em `Services/Marketplaces/MercadoLivre`, dividida em cliente HTTP, OAuth, provedor de token e categorias. O transporte HTTP é uma interface, então os testes nunca chamam a API real.

## Stack

- PHP 8.2+ (testado com 8.3)
- CodeIgniter 4.7
- Composer
- MySQL 8+ (Docker Compose incluído, imagem `mysql:8.4`)
- Bootstrap 5.3 e Bootstrap Icons (servidos localmente em `public/assets/vendor`)
- JavaScript puro com Fetch API
- PHPUnit 10

## Requisitos

- PHP 8.2+ com as extensões `intl`, `mbstring`, `bcmath`, `mysqli`, `json` e `fileinfo`
- Composer 2
- MySQL 8+ **ou** Docker com Docker Compose
- Git

## Instalação

```bash
git clone git@github.com:joselunar/marketplace-hub.git
cd marketplace-hub
composer install
cp .env.example .env          # PowerShell: Copy-Item .env.example .env
php spark key:generate
```

## Configuração

Edite o `.env` e preencha, no mínimo:

- `MYSQL_ROOT_PASSWORD` e `MYSQL_PASSWORD` (se for usar o Docker Compose);
- `database.default.password` e `database.tests.password` (mesmo valor de `MYSQL_PASSWORD`);
- `MYSQL_PORT`, `database.default.port` e `database.tests.port`, se a porta 3306 já estiver em uso;
- `SEED_ADMIN_PASSWORD` e `SEED_OPERATOR_PASSWORD` (opcional; se ficarem vazias, o seeder gera senhas aleatórias e as mostra no terminal).

O `.env` **nunca** deve ser versionado. Ele já está no `.gitignore`.

## Banco

Com Docker:

```bash
docker compose up -d
docker compose ps             # aguarde o status "healthy"
```

O container cria dois bancos: `marketplace_hub` (aplicação) e `marketplace_hub_test` (testes automatizados), ambos acessíveis pelo usuário definido em `MYSQL_USER`.

Sem Docker, crie manualmente:

```sql
CREATE DATABASE marketplace_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE marketplace_hub_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'marketplace'@'localhost' IDENTIFIED BY 'sua-senha';
GRANT ALL PRIVILEGES ON marketplace_hub.* TO 'marketplace'@'localhost';
GRANT ALL PRIVILEGES ON marketplace_hub_test.* TO 'marketplace'@'localhost';
```

### Tabelas

| Tabela | Conteúdo |
|---|---|
| `users` | Usuários (admin/operador), senha com `password_hash` |
| `settings` | Configurações do sistema |
| `audit_logs` | Auditoria de alterações (valores antigos/novos em JSON) |
| `sync_logs` | Registro de sincronizações e importações |
| `categories` | Categorias hierárquicas, com margens próprias opcionais |
| `suppliers` | Fornecedores |
| `products` | Catálogo interno (SKU, EAN, dimensões, peso, margens, preço fixo e o último preço calculado) |
| `product_images` | Imagens dos produtos |
| `supplier_offers` | Ofertas dos fornecedores (custo, estoque, frete, prazo, bloqueio manual e resultado da última avaliação) |
| `supplier_imports` | Histórico de importações de arquivos |
| `supplier_selection_logs` | Histórico de trocas do fornecedor principal de cada produto |
| `marketplaces` | Marketplaces conhecidos (criados a partir de `Config\Marketplaces`) |
| `marketplace_accounts` | Contas conectadas: usuário externo, site, tokens cifrados, vencimento, status e último erro |
| `marketplace_listings` | Anúncios: vínculo produto ↔ anúncio, IDs externos (item e User Product), modelo, preço, estoque, situação, tarifas e último erro |
| `marketplace_listing_events` | Histórico de cada anúncio (publicação, sincronização, preço, estoque, pausa, reativação) |
| `marketplace_category_mappings` | Categoria interna → categoria do marketplace usada na última publicação |
| `webhook_events` | Notificações recebidas (tópico, recurso, usuário, aplicativo, tentativas, status, resultado e payload), com chave única contra duplicidade |
| `orders` | Pedidos dos marketplaces: status, valores, tarifas, custo, lucro estimado, andamento junto aos fornecedores e pendência |
| `order_items` | Itens do pedido, produto e anúncio ligados, fornecedor/oferta reservados, custo e lucro estimado |
| `supplier_orders` | Pedido a cada fornecedor reservado: status, custo, número no fornecedor, rastreio e quem enviou |
| `jobs` | Fila de jobs: tipo, payload, chave única contra duplicidade, status, tentativas, próxima tentativa, resultado e último erro |
| `scheduled_tasks` | Última execução de cada tarefa agendada e os sinais de vida do agendador e do worker |

## Migrations

```bash
php spark migrate             # aplica
php spark migrate:status      # situação
php spark migrate:rollback    # desfaz o último lote
php spark migrate:refresh     # recria tudo (apaga os dados)
```

## Seeders

```bash
php spark db:seed DevelopmentSeeder
```

O `DevelopmentSeeder` cria configurações padrão, usuários admin e operador, categorias, 3 fornecedores, 8 produtos e ofertas de exemplo (incluindo produto sem oferta, produto sem estoque, produto inativo, oferta sem vínculo, oferta bloqueada, fornecedor e produto com estoque de segurança próprio, categorias com margem própria e produtos com preço fixo dentro e abaixo da margem mínima) e já calcula seleção e preços. Ele se recusa a rodar com `CI_ENVIRONMENT=production`.

Para a demonstração exibir todos os componentes do preço, o seeder preenche tarifas **fictícias** (comissão de 14%, tarifa fixa de R$ 6,00 abaixo de R$ 79,00 e R$ 1,50 de embalagem), apenas se as tarifas ainda estiverem zeradas. Em uso real, ajuste esses valores em **Configurações** conforme a tabela oficial do marketplace.

Para popular só as configurações padrão (útil em produção):

```bash
php spark db:seed SettingsSeeder
```

## Execução

```bash
php spark serve --port 8080
```

Acesse <http://localhost:8080> e entre com o e-mail e a senha definidos em `SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD`.

Telas disponíveis: dashboard, fornecedores, categorias, produtos, ofertas, simulador de preços, importação CSV, Mercado Livre (contas conectadas, categorias, anúncios e notificações), pedidos, sincronizações (fila de jobs e tarefas agendadas), usuários, configurações e logs (auditoria e sincronização).

Em produção, aponte o servidor web para a pasta `public/`, use `CI_ENVIRONMENT=production` e `app.forceGlobalSecureRequests=true` (HTTPS).

### Importação CSV

Na tela **Importações**, baixe o modelo de CSV, preencha e envie para um fornecedor. A importação:

- cria ou atualiza ofertas pela chave fornecedor + SKU do fornecedor;
- vincula automaticamente ao produto pelo SKU interno (`product_sku`) ou, na falta dele, pelo EAN;
- aceita valores no formato brasileiro (`1.234,56`) ou americano (`1234.56`);
- registra erros por linha sem interromper as demais linhas.

### Motor de seleção de fornecedores

Para cada produto, o sistema avalia todas as ofertas vinculadas e escolhe um **fornecedor principal** e até duas **reservas**.

1. **Estoque disponível** = `max(0, estoque informado − estoque de segurança)`. O estoque de segurança vem do produto; se não houver, do fornecedor; se não houver, da configuração global.
2. **Critérios eliminatórios**: fornecedor inativo ou bloqueado, oferta inativa, oferta bloqueada manualmente (com motivo), custo inválido, estoque disponível zero, prazo de postagem não informado ou acima do limite configurado, e margem mínima não atingida (ver [Precificação](#precificação)).
3. **Score de 0 a 100** entre as ofertas elegíveis, ponderado pelos pesos de **Configurações**:
   - preço final (custo + frete): o menor recebe 100 e os demais, proporcionalmente;
   - estoque disponível: proporcional ao maior entre as elegíveis;
   - prazo de postagem: 100 para o mesmo dia, caindo linearmente até o limite;
   - confiabilidade: sincronizações com sucesso na janela configurada (alertas contam meio ponto; sem histórico = 100);
   - prioridade do fornecedor: prioridade × 10.
4. Empates são resolvidos por menor custo total, menor prazo, maior estoque disponível e oferta mais antiga.

A seleção é recalculada automaticamente quando uma oferta é criada, alterada, excluída, bloqueada ou (des)vinculada; quando um fornecedor muda; quando o produto muda algo que afeta a escolha (estoque de segurança, categoria, margens, preço fixo ou custo extra); quando as margens de uma categoria mudam (inclusive para as subcategorias); após cada importação; e quando as configurações de precificação, estoque, fornecedores ou ranking são salvas. Se o principal fica inelegível, outro elegível assume na hora.

Toda troca de fornecedor principal é registrada em `supplier_selection_logs` (com o motivo, a origem e o ranking completo) e na auditoria. A tela do produto mostra o score, os motivos por critério, a comparação com os demais fornecedores e o histórico de trocas. O botão **Recalcular** força um novo cálculo.

### Precificação

O preço considera custo do fornecedor, frete do fornecedor, comissão e tarifa fixa do marketplace, impostos estimados, custos extras (por unidade e percentuais) e a margem. **Margem é o lucro sobre o preço de venda**, então o preço não é "custo + percentual":

```
preço = custos fixos / (1 − (comissão% + impostos% + extras% + margem%) / 100)
custos fixos = custo + frete + custos extras por unidade (+ tarifa fixa, se o preço ficar abaixo da faixa)
```

- **Margens desejada e mínima** podem ser definidas no produto, na categoria (vale também para as subcategorias) ou na configuração global; a mais específica prevalece, campo a campo. Se a mínima efetiva for maior que a desejada, o preço sugerido usa a mínima.
- **Tarifa fixa por faixa**: cobrada só em vendas abaixo do valor configurado. Quando a tarifa empurraria o preço para cima da faixa, o preço fica no limite da faixa, onde ela não se aplica.
- **Arredondamento** configurável (centavo, terminar em ,90 ou ,99, real inteiro), sempre para cima, para nunca reduzir a margem.
- **Preço fixo** opcional no produto. Ao salvar, o preço é recusado se nenhum fornecedor atingir a margem mínima com ele; a mensagem mostra o preço mínimo possível.
- **Margem mínima no motor de seleção**: com preço fixo, ofertas cujo custo não permite a margem mínima ficam inelegíveis (e o motor troca para um fornecedor que permita). Sem preço fixo, uma oferta só é eliminada se os percentuais somarem 100% ou mais, já que o preço sugerido sempre cobre a margem.
- Se um aumento de custo deixar o preço fixo abaixo da mínima com todos os fornecedores, o produto fica sem fornecedor elegível e aparece no alerta **margem abaixo da mínima** do dashboard e no filtro **Precificação** da lista de produtos.

A tela do produto mostra a composição completa (custo, frete, comissão, tarifa fixa, impostos, extras, preço, lucro e margem), de onde veio cada margem e o preço de cada oferta. O **Simulador de preços** (menu Catálogo) calcula sem gravar nada, com as mesmas regras.

## Automação

Toda a automação roda a partir de **uma única linha de cron por minuto**:

```cron
* * * * * cd /caminho/marketplace-hub && php spark schedule:run && php spark jobs:work >> writable/logs/cron.log 2>&1
```

- `schedule:run` (agendador) enfileira os jobs das tarefas vencidas e termina em segundos.
- `jobs:work` (worker) executa a fila até esvaziá-la ou atingir 50 segundos / 200 jobs (`Config\Automation`), para caber no minuto do cron. Vários workers podem rodar juntos: a reserva de cada job é atômica.

Tarefas agendadas (intervalos em **Configurações > Sincronização**; 0 desliga):

| Tarefa | Intervalo | Job |
|---|---|---|
| Processar notificações do Mercado Livre | 1 min (só com notificação na fila) | `ProcessMercadoLivreNotificationsJob` |
| Renovar tokens do Mercado Livre | 15 min (tokens vencendo em até 30 min) | `RefreshMercadoLivreTokenJob`, um por conta |
| Recalcular fornecedores e preços | 60 min (a confiabilidade usa uma janela de tempo) | `RecalculateSupplierSelectionJob` |
| Sincronizar estoque dos fornecedores | 60 min | `SyncSupplierStockJob`, um por fornecedor com adapter |
| Sincronizar custos dos fornecedores | 360 min | `SyncSupplierPricesJob`, um por fornecedor com adapter |
| Conferir estoque dos anúncios | 30 min, só com a atualização automática de estoque ligada | `SyncMercadoLivreStockJob`, um por anúncio diferente do fornecedor |
| Conferir preço dos anúncios | 360 min, só com a atualização automática de preço ligada | `SyncMercadoLivrePricesJob`, um por anúncio |
| Importar pedidos recentes | 360 min (rede de segurança para notificações perdidas) | `ImportMercadoLivreOrdersJob`, um por conta |

Como a fila funciona:

- **Sem duplicidade**: um job igual (mesmo tipo e dados) que ainda espera na fila não é criado de novo.
- **Novas tentativas**: falhas temporárias (sem resposta, HTTP 429 ou 5xx do Mercado Livre, erro de rede) voltam para a fila com espera de 1, 2, 4, 8... minutos (máximo de 1 hora), até 5 tentativas. Regras de negócio (ex.: preço abaixo da margem mínima), autorização revogada e operações que o fornecedor não oferece falham na hora, sem insistir.
- **Falha definitiva** fica registrada no job, em `sync_logs` (integração `automation`, aba **Sincronizações** dos logs) e no alerta do dashboard.
- Jobs presos em "executando" por mais de 15 minutos (processo interrompido) voltam para a fila; concluídos e cancelados com mais de 30 dias são apagados.

A tela **Automação > Sincronizações** mostra quando o agendador e o worker rodaram pela última vez, as tarefas com a próxima execução e o botão **Executar agora**, e a fila com filtros, **repetir** e **cancelar** (em lote só para administradores). Se o agendador ficar mais de 10 minutos sem rodar, a tela e o dashboard avisam.

### Sincronização com fornecedores

Só fornecedores **ativos com adapter registrado** em `Config\SupplierConnectors` são sincronizados. O adapter é escrito a partir da documentação oficial do fornecedor; sem ele, nada é simulado. Fornecedores manuais e por CSV continuam sendo atualizados pela importação de arquivo.

- Estoque usa `fetchStock()` (ou o catálogo, `fetchProducts()`, se o fornecedor só oferecer isso) e altera **apenas** o estoque das ofertas existentes; custos usam `fetchPrices()` e alteram apenas custo e frete. SKUs desconhecidos são contados, nunca criados.
- Cada sincronização grava `sync_logs` (`sync_stock` / `sync_prices`), que alimenta a confiabilidade do fornecedor, e recalcula fornecedor principal e preço dos produtos afetados.

### Atualização automática de estoque e preço (opcional)

Desligadas por padrão em **Configurações > Sincronização**.

- **Estoque**: sempre que o estoque seguro do fornecedor principal mudar (importação, sincronização, edição de oferta, troca de fornecedor, pedido), os anúncios ativos ou pausados do produto recebem a nova quantidade. A conferência periódica corrige o que tiver ficado diferente. Quantidade igual não chama a API.
- **Preço**: quando o preço sugerido muda, o preço é recalculado com a tarifa oficial (`listing_prices`) e enviado. **Preço abaixo da margem mínima nunca é enviado**: o job falha e aparece no dashboard.
- Só anúncios publicados, ativos ou pausados, de contas conectadas entram na atualização.

### Comandos

```bash
php spark schedule:run                     # enfileira as tarefas vencidas
php spark jobs:work                        # executa a fila (--max-jobs 50 --max-time 30)
php spark suppliers:sync                   # estoque e custos de todos os fornecedores com adapter
php spark suppliers:sync --supplier 3 --only stock
php spark suppliers:sync --queue           # só enfileira, com novas tentativas
php spark meli:sync-stock                  # anúncios com estoque diferente (respeita a configuração)
php spark meli:sync-stock --force          # mesmo com a atualização automática desligada
php spark meli:sync-prices --listing 12    # um anúncio
php spark marketplace:sync                 # tokens, notificações, pedidos, estoque e preço agora + worker
php spark marketplace:sync --no-work       # só enfileira
```

Os comandos das fases anteriores continuam disponíveis para uso manual:

```bash
php spark suppliers:select                 # todos os produtos com ofertas
php spark suppliers:select --product 12    # um produto
php spark suppliers:select --supplier 3    # produtos com ofertas do fornecedor 3
```

```bash
php spark meli:refresh-token               # contas com token vencendo em até 30 min
php spark meli:refresh-token --account 2   # uma conta
php spark meli:refresh-token --force       # renova mesmo longe do vencimento
```

```bash
php spark meli:process-notifications              # até 100 notificações da fila
php spark meli:process-notifications --limit 500
php spark meli:import-orders                      # pedidos atualizados nos últimos 2 dias, todas as contas
php spark meli:import-orders --days 7 --account 2
```

## Mercado Livre

A integração segue a documentação oficial ([Autenticação e Autorização](https://developers.mercadolivre.com.br/pt_br/autenticacao-e-autorizacao), [Domínios e Categorias](https://developers.mercadolivre.com.br/pt_br/categorias-e-publicacoes), [Atributos](https://developers.mercadolivre.com.br/pt_br/atributos)).

### Configurar o aplicativo

1. Crie um aplicativo no [portal de desenvolvedores](https://developers.mercadolivre.com.br/) e cadastre como URL de retorno o endereço `.../marketplaces/mercadolivre/callback` deste painel. Ele precisa ser idêntico ao `MELI_REDIRECT_URI`, sem parâmetros variáveis. Se o portal exigir HTTPS, use o domínio de produção ou um túnel HTTPS que encaminhe para essa rota.
2. Preencha no `.env`: `MELI_CLIENT_ID`, `MELI_CLIENT_SECRET` e `MELI_REDIRECT_URI`. `MELI_USE_PKCE` vem ligado; desligue só se o aplicativo não aceitar PKCE.
3. Em **Marketplaces > Mercado Livre**, um administrador clica em **Conectar conta** e autoriza com a conta **administradora** do vendedor (usuários operadores/colaboradores são recusados pelo Mercado Livre).

### Como funciona

- **Autorização** (Authorization Code, server side) com `state` aleatório de uso único, válido por 10 minutos e guardado na sessão, e PKCE S256. Um retorno com `state` diferente é descartado antes de qualquer chamada à API.
- O backend troca o `code` por tokens em `POST /oauth/token`, lê o apelido e o site da conta em `GET /users/{user_id}` e grava a conta. Reconectar o mesmo vendedor atualiza a mesma linha.
- **Tokens cifrados** com `encryption.key` (AES-256-CTR + HMAC-SHA512). A tela mostra só o prefixo e os 4 últimos caracteres; a API interna não devolve token algum; logs e auditoria nunca recebem tokens nem o Client Secret.
- **Renovação automática**: o access token dura 6 horas e é renovado quando faltam menos de 30 minutos, tanto antes de cada chamada à API quanto pelo comando `meli:refresh-token`. Se a API recusar um token ainda dentro do prazo (401), ele é renovado e a chamada é repetida uma vez.
- O refresh token é **de uso único**. Para que duas renovações simultâneas (cron e uma requisição, por exemplo) não invalidem a conta, a renovação trava a linha da conta (`SELECT ... FOR UPDATE`) e confere de novo se ainda precisa renovar.
- Erros `invalid_grant`, `unauthorized_client` e `invalid_operator_user_id` marcam a conta como **Reconectar**; falhas temporárias (rede, 5xx, 429) só registram o erro e mantêm a conta conectada. Contas para reconectar e tokens vencidos aparecem como alerta no dashboard.
- **Desconectar** revoga a permissão do aplicativo no Mercado Livre (`DELETE /users/{user_id}/applications/{app_id}`) e apaga os tokens locais, mesmo que a revogação falhe.
- **Categorias e atributos** (aba da mesma tela): árvore de categorias do site da conta, detalhes de publicação de cada categoria, atributos com os obrigatórios destacados e preditor de categoria pelo título do produto. As respostas ficam em cache por 6 horas.
- Toda conexão, renovação e desconexão é registrada em `sync_logs` (aba **Sincronizações** dos logs).

### Anúncios

A publicação segue [Publicar produtos](https://developers.mercadolivre.com.br/pt_br/publicar-produtos), [User Products](https://developers.mercadolivre.com.br/pt_br/user-products), [Custos por vender](https://developers.mercadolivre.com.br/pt_br/comissao-por-vender) e [Sincronização de publicações](https://developers.mercadolivre.com.br/pt_br/produto-sincronizacao-de-publicacoes). Nada é enviado sem confirmação:

1. Na tela do produto, **Publicar no Mercado Livre** abre a **prévia**. Ela valida o produto (ativo, com fornecedor principal e estoque seguro), a conta, a categoria (folha, aceita publicação e a moeda), os atributos obrigatórios, o título (limite da categoria e sem a palavra "estoque"), as imagens e a garantia.
2. A categoria vem do formulário, do anúncio anterior, do mapeamento da categoria interna ou do preditor, nessa ordem. Os tipos de anúncio são os disponíveis para a conta naquela categoria.
3. O preço é recalculado com a tarifa oficial de `listing_prices` para a categoria, o tipo de anúncio e o preço (até convergir) e precisa atingir a margem mínima. Com preço fixo no produto, ele é mantido e só a margem é conferida.
4. A prévia mostra exatamente o JSON que será enviado a `POST /items`. A confirmação leva o preço e o estoque vistos; se algo mudou até o envio, a publicação é bloqueada e a prévia precisa ser revista.
5. Após a criação, a descrição vai em `POST /items/{id}/description` e os IDs, a situação, o link e as tarifas ficam em `marketplace_listings`.

- **User Products**: se a conta tem a tag `user_product_seller`, o anúncio é enviado com `family_name` (sem `title`) e o `user_product_id` devolvido é guardado. Vendedores com estoque em vários depósitos não são atendidos por esta versão.
- **Ações** (lista e detalhe): visualizar, sincronizar (`GET /items/{id}`), atualizar preço (recalculado com a tarifa oficial e recusado abaixo da mínima), atualizar estoque (estoque seguro do fornecedor principal, ou zero sem fornecedor), pausar e reativar. Toda ação entra no histórico do anúncio, e publicação e sincronização também em `sync_logs`. As ações POST são limitadas a 30 por minuto por usuário.
- Erros da API são mostrados com os motivos devolvidos pelo Mercado Livre e gravados no anúncio; um anúncio com erro pode ser publicado de novo.
- **Publicação automática**: a configuração `marketplace.automatic_publish` existe, mas fica desligada e ainda não dispara nada.
- Desde março de 2026 a tarifa fixa no Brasil depende da logística. Preencha `MELI_LOGISTIC_TYPE` e `MELI_SHIPPING_MODE` para que a tarifa consultada coincida com a cobrada; sem eles, a prévia avisa.

### Notificações

Seguem [Notificações](https://developers.mercadolivre.com.br/pt_br/produto-receba-notificacoes). No aplicativo do Mercado Livre, cadastre `https://seu-dominio/webhooks/mercadolivre` como URL de notificações e assine os tópicos `orders_v2` e `items`.

- O Mercado Livre exige HTTP 200 em até 500 ms. Por isso o endpoint só **valida e grava** a notificação em `webhook_events`; o pedido ou anúncio é consultado depois, pelo agendador (a cada minuto), por `meli:process-notifications` ou pelo botão **Processar fila** na aba **Notificações**.
- Validação: corpo JSON de até 16 KB, `topic`, `resource` e `user_id` obrigatórios, `application_id` igual ao `MELI_CLIENT_ID` quando ele está configurado (senão 403) e, opcionalmente, IP de origem na lista `MELI_WEBHOOK_IPS` (a documentação publica os IPs de envio). A rota fica fora do CSRF e da sessão e tem limite de requisições por IP.
- **Idempotência**: a mesma notificação reenviada (mesmo `_id`, ou mesmo tópico + recurso + envio) não cria outra linha, e o processamento sempre lê o estado atual do recurso. Notificações pendentes do mesmo recurso são resolvidas por uma única consulta.
- Usuário sem conta conectada e tópicos não tratados são guardados como **ignorados** (com resposta 200, para o Mercado Livre não reenviar). Falhas temporárias voltam para a fila até 5 tentativas; 403 e 404 falham na hora. Notificações com falha ou ignoradas podem ser devolvidas à fila pela tela.
- `orders_v2` → `GET /orders/{id}` e gravação do pedido; `items` → sincronização do anúncio, se ele foi publicado pelo sistema.

### Pedidos

Seguem [Gerenciamento de vendas](https://developers.mercadolivre.com.br/pt_br/gerenciamento-de-vendas). Os pedidos chegam pelas notificações ou pela importação manual (**Importar pedidos** na tela de pedidos, ou `meli:import-orders`), que usa `GET /orders/search?seller=...` filtrando pela data de atualização. A busca do vendedor não devolve pedidos cancelados; os cancelamentos chegam pela notificação.

Fluxo de cada pedido:

1. Os dados da API são validados (vendedor da conta, IDs, status, datas, itens) antes de gravar. Uma versão mais antiga que a gravada (`last_updated`) é ignorada, e o mesmo pedido recebido várias vezes atualiza a mesma linha.
2. Cada item é ligado ao produto pelo anúncio publicado pelo sistema ou, na falta dele, pelo SKU do vendedor (`seller_sku` = SKU interno).
3. Quando o pedido está **pago**, os fornecedores são revalidados (motor de seleção com dados atuais) e um deles é **reservado** por item: o melhor colocado com estoque seguro para a quantidade, descontado o que já está reservado em outros pedidos ainda não enviados.
4. Lucro estimado = venda − tarifa do Mercado Livre − (custo + frete do fornecedor + custos extras) × quantidade − impostos e extras percentuais. A tarifa vem do pedido (`sale_fee`); antes de o pagamento ser creditado, é estimada pela comissão do anúncio ou das configurações e aparece como **estimada**.
5. É criado um **pedido ao fornecedor** pendente por fornecedor reservado.

- **Nada é comprado automaticamente.** O botão **Enviar pedido ao fornecedor** (com confirmação) só registra o envio, com o número do pedido no fornecedor e observações; a compra é feita pelo operador. Um fornecedor com adapter próprio que implemente `create_order` em `Config\SupplierConnectors` recebe o pedido pela integração.
- Depois do envio, o acompanhamento é manual: despachado, entregue ou cancelado, código de rastreio e observações. **Revalidar fornecedores** refaz as reservas enquanto nada foi enviado.
- Pedidos com a tag `fraud_risk_detected` não reservam fornecedor e não podem ser enviados (a documentação orienta cancelar a venda). Venda cancelada cancela os pedidos ao fornecedor pendentes; se algum já tinha sido enviado, o pedido fica em **Requer atenção**.
- O andamento interno (aguardando pagamento, requer atenção, aguardando envio ao fornecedor, enviado, entregue, cancelado) alimenta o dashboard: pedidos hoje e nos últimos 7 dias, faturamento, lucro e margem média estimados, e o alerta **Pedidos aguardando fornecedor**.
- Toda importação, mudança de status, revalidação, envio e atualização entra na auditoria. O endereço do comprador não é guardado.

## Variáveis .env

| Variável | Descrição |
|---|---|
| `CI_ENVIRONMENT` | `development`, `testing` ou `production` |
| `app.baseURL` | URL base da aplicação |
| `app.forceGlobalSecureRequests` | Força HTTPS (use `true` em produção) |
| `MYSQL_ROOT_PASSWORD` | Senha do root do MySQL no Docker |
| `MYSQL_DATABASE` / `MYSQL_TEST_DATABASE` | Bancos criados pelo container |
| `MYSQL_USER` / `MYSQL_PASSWORD` | Usuário da aplicação no MySQL |
| `MYSQL_PORT` | Porta exposta pelo container |
| `database.default.*` | Conexão da aplicação |
| `database.tests.*` | Conexão usada pelos testes |
| `encryption.key` | Chave de criptografia (`php spark key:generate`) |
| `SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD` | Usuário admin criado pelo seeder |
| `SEED_OPERATOR_EMAIL` / `SEED_OPERATOR_PASSWORD` | Usuário operador criado pelo seeder |
| `MELI_CLIENT_ID` / `MELI_CLIENT_SECRET` / `MELI_REDIRECT_URI` | Aplicativo do Mercado Livre |
| `MELI_USE_PKCE` | Envia PKCE S256 na autorização (padrão `true`) |
| `MELI_LOGISTIC_TYPE` / `MELI_SHIPPING_MODE` | Logística usada na consulta de tarifas (ex.: `drop_off` / `me2`) |
| `MELI_WEBHOOK_IPS` | Opcional: IPs de origem aceitos no webhook, separados por vírgula (vazio = qualquer origem) |
| `mercadolivre.authUrl` / `mercadolivre.siteId` | Opcional: domínio de autorização e site padrão de outro país |

## Segurança

- CSRF em todos os formulários e requisições POST, exceto o webhook do Mercado Livre, que tem validação própria (aplicativo, tamanho, formato, IP opcional e limite por IP).
- Escape de saída nas views (`esc()`), Query Builder em todas as consultas e validação no backend.
- Senhas com `password_hash` (rehash automático), regeneração de sessão no login e limite de 5 tentativas de login por minuto por IP.
- Rotas protegidas por autenticação e por papel (`admin` / `operador`).
- Uploads validados por tamanho, extensão e MIME, salvos com nome aleatório fora de `public/` e removidos após o processamento.
- Auditoria sem dados sensíveis.
- Tokens de marketplace cifrados em repouso, nunca exibidos por completo e nunca enviados na URL (sempre no cabeçalho `Authorization`). Conectar, renovar e desconectar contas é restrito a administradores.

## Testes

Os testes de banco usam o banco `marketplace_hub_test` (grupo `tests` do `.env`).

```bash
composer test                       # todos os testes
composer test:unit                  # só testes unitários
vendor/bin/phpunit --testsuite database
vendor/bin/phpunit --testsuite feature
vendor/bin/phpunit --coverage-html build/coverage   # requer Xdebug ou PCOV
```

Suítes:

- **unit**: normalização decimal, conector CSV, fábrica de conectores, limpeza de dados sensíveis na auditoria, regras do ranking de fornecedores (incluindo margem mínima), calculador de preço (fórmula, tarifa fixa por faixa, arredondamento, preço manual, percentuais inviáveis), lucro estimado de pedidos e utilitários do OAuth (PKCE com o vetor da RFC 7636, cifragem e mascaramento de tokens, tradução dos erros da API);
- **database**: importação CSV, produtos, categorias, fornecedores/ofertas, configurações, motor de seleção (troca automática, histórico, estoque de segurança, confiabilidade), precificação (hierarquia de margens, bloqueio de preço abaixo da mínima, troca de fornecedor por margem, reprecificação por categoria) e Mercado Livre (state e PKCE, troca do code, tokens cifrados, rotação do refresh token, `invalid_grant`, falha de rede, repetição em 401, revogação, categorias e cache) e anúncios (prévia clássica e User Products, bloqueios, publicação com descrição e mapeamento, preço desatualizado, nova tentativa após erro, convergência da tarifa, preço, estoque, pausa, reativação e sincronização), pedidos (reserva de fornecedor e lucro, idempotência e versão antiga, estoque reservado, pagamento pendente, item sem produto, fraude, cancelamento, envio e acompanhamento, rollback, revalidação, importação) e notificações (gravação única, processamento posterior, agrupamento, validação, falha e nova tentativa), automação (fila sem duplicidade, novas tentativas com espera crescente, classificação de falhas temporárias e definitivas, job interrompido, agendador e intervalos, sincronização de estoque e custos com adapter de teste, atualização automática de estoque e preço dos anúncios e queda do marketplace), com transporte HTTP falso;
- **feature**: login/logout, controle de acesso por papel, API interna, proteção CSRF, tela do produto com a explicação da escolha e a composição do preço, simulador, alertas de margem, conexão com o Mercado Livre de ponta a ponta (sem token completo na tela nem na API), explorador de categorias, webhook (sem sessão nem CSRF, recusas e filtro de IP), telas de pedidos e notificações, envio ao fornecedor, API de pedidos, tela de sincronizações (executar agora, repetir, cancelar), alertas do dashboard e comandos `suppliers:select`, `meli:refresh-token`, `schedule:run`, `jobs:work`, `meli:sync-stock` e `suppliers:sync`.

## API interna

Rotas JSON autenticadas por sessão:

| Rota | Conteúdo |
|---|---|
| `GET /api/products` | Lista paginada (filtros `q`, `sku`, `ean`, `brand`, `category_id`, `status`, `supplier`, `pricing`) |
| `GET /api/products/{id}` | Produto com imagens |
| `GET /api/products/{id}/offers` | Ofertas em ordem de ranking |
| `GET /api/products/{id}/selection` | Ranking com motivos, margens e preço de cada oferta |
| `GET /api/products/{id}/pricing` | Composição do preço com o fornecedor principal |
| `GET /api/pricing/simulate` | Simulação (`cost_price`, `shipping_cost`, `extra_cost`, `category_id`, margens, `sale_price`) |
| `GET /api/suppliers` e `GET /api/suppliers/{id}` | Fornecedores |
| `GET /api/marketplaces/accounts` | Contas conectadas e situação do token, sem tokens (filtro `marketplace`) |
| `GET /api/orders` | Pedidos paginados com valores, lucro e margem (filtros `q`, `status`, `fulfillment`, `account`, `period`) |
| `GET /api/orders/{id}` | Pedido com itens e pedidos aos fornecedores |

## Roadmap

| Fase | Escopo | Status |
|---|---|---|
| 1 | Base: estrutura, banco, layout, autenticação, configurações | Concluída |
| 2 | Fornecedores, catálogo, ofertas, importação CSV | Concluída |
| 3 | Motor de seleção de fornecedor | Concluída |
| 4 | Precificação | Concluída |
| 5 | Mercado Livre: OAuth | Concluída |
| 6 | Publicações | Concluída |
| 7 | Notificações, pedidos e pedidos aos fornecedores | Concluída |
| 8 | Automação (jobs, agendamentos, dashboard completo) | Concluída |
| 9 | Outros marketplaces | Próxima |

Detalhes em [ROADMAP.md](ROADMAP.md).
