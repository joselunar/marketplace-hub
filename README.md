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

Status atual: **Fases 1 a 4 concluídas** (base, autenticação, fornecedores, catálogo, ofertas, importação CSV, motor de seleção de fornecedores e precificação). Veja o [ROADMAP](ROADMAP.md).

## Arquitetura

Monólito modular em CodeIgniter 4, organizado em MVC + camada de serviços:

```
app/
├── Commands/          # Comandos spark (ex.: suppliers:select)
├── Config/            # Configurações (filtros, rotas, menu, catálogo de settings, conectores)
├── Controllers/       # Controllers finos: validam entrada HTTP e delegam aos services
│   └── Api/           # API interna JSON (autenticada por sessão)
├── Database/
│   ├── Migrations/    # Toda a estrutura do banco
│   └── Seeds/         # Dados de desenvolvimento
├── DTO/               # Objetos de transferência (fornecedores, ranking, precificação)
├── Enums/             # Papéis, status e tipos de integração
├── Exceptions/        # ValidationException, BusinessRuleException
├── Filters/           # auth, guest, role, throttle de login
├── Helpers/           # Helpers de interface (formatação, badges)
├── Models/            # Acesso a dados via Query Builder
├── Services/          # Regras de negócio
│   ├── Audit/         # Auditoria com remoção de dados sensíveis
│   ├── Auth/
│   ├── Catalog/       # Categorias e produtos
│   ├── Dashboard/
│   ├── Pricing/       # Calculador de preço e resolução de margens
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
- **Tarifas de marketplace são configuração**, não código. Hoje são preenchidas em **Configurações** a partir da tabela oficial; a consulta automática à API de tarifas do Mercado Livre entra com a integração (Fase 6).

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

Telas disponíveis: dashboard, fornecedores, categorias, produtos, ofertas, simulador de preços, importação CSV, usuários, configurações e logs (auditoria e sincronização). Os módulos das próximas fases aparecem desabilitados no menu.

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

## Cron

A confiabilidade usa uma janela de tempo, então ela muda mesmo sem edições. Agende o recálculo (ele também atualiza os preços gravados):

```cron
0 * * * * cd /caminho/marketplace-hub && php spark suppliers:select >> writable/logs/cron.log 2>&1
```

Opções do comando:

```bash
php spark suppliers:select                 # todos os produtos com ofertas
php spark suppliers:select --product 12    # um produto
php spark suppliers:select --supplier 3    # produtos com ofertas do fornecedor 3
```

Os comandos de sincronização com fornecedores e marketplaces chegam na Fase 8.

## Mercado Livre

A integração com o Mercado Livre começa na **Fase 5** e ainda não está implementada. Ela seguirá a documentação oficial vigente ([developers.mercadolivre.com.br](https://developers.mercadolivre.com.br/)): OAuth 2.0 com refresh token, modelo de User Products quando aplicável, publicações, pedidos e notificações (webhooks).

Variáveis já reservadas no `.env`:

- `MELI_CLIENT_ID`
- `MELI_CLIENT_SECRET`
- `MELI_REDIRECT_URI`

Credenciais e tokens nunca são gravados no código nem nos logs; os tokens de acesso serão armazenados criptografados com a chave `encryption.key`.

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
| `MELI_CLIENT_ID` / `MELI_CLIENT_SECRET` / `MELI_REDIRECT_URI` | Aplicativo do Mercado Livre (Fase 5) |

## Segurança

- CSRF em todos os formulários e requisições POST (exceto rotas de webhook, que terão validação própria).
- Escape de saída nas views (`esc()`), Query Builder em todas as consultas e validação no backend.
- Senhas com `password_hash` (rehash automático), regeneração de sessão no login e limite de 5 tentativas de login por minuto por IP.
- Rotas protegidas por autenticação e por papel (`admin` / `operador`).
- Uploads validados por tamanho, extensão e MIME, salvos com nome aleatório fora de `public/` e removidos após o processamento.
- Auditoria sem dados sensíveis.

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

- **unit**: normalização decimal, conector CSV, fábrica de conectores, limpeza de dados sensíveis na auditoria, regras do ranking de fornecedores (incluindo margem mínima) e calculador de preço (fórmula, tarifa fixa por faixa, arredondamento, preço manual, percentuais inviáveis);
- **database**: importação CSV, produtos, categorias, fornecedores/ofertas, configurações, motor de seleção (troca automática, histórico, estoque de segurança, confiabilidade) e precificação (hierarquia de margens, bloqueio de preço abaixo da mínima, troca de fornecedor por margem, reprecificação por categoria);
- **feature**: login/logout, controle de acesso por papel, API interna, proteção CSRF, tela do produto com a explicação da escolha e a composição do preço, simulador, alertas de margem e comando `suppliers:select`.

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

## Roadmap

| Fase | Escopo | Status |
|---|---|---|
| 1 | Base: estrutura, banco, layout, autenticação, configurações | Concluída |
| 2 | Fornecedores, catálogo, ofertas, importação CSV | Concluída |
| 3 | Motor de seleção de fornecedor | Concluída |
| 4 | Precificação | Concluída |
| 5 | Mercado Livre: OAuth | Próxima |
| 6 | Publicações | Planejada |
| 7 | Pedidos | Planejada |
| 8 | Automação (jobs, cron, webhooks) | Planejada |
| 9 | Outros marketplaces | Planejada |

Detalhes em [ROADMAP.md](ROADMAP.md).
