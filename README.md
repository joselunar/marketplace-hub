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

Status atual: **Fases 1 e 2 concluídas** (base, autenticação, fornecedores, catálogo, ofertas e importação CSV). Veja o [ROADMAP](ROADMAP.md).

## Arquitetura

Monólito modular em CodeIgniter 4, organizado em MVC + camada de serviços:

```
app/
├── Config/            # Configurações (filtros, rotas, menu, catálogo de settings, conectores)
├── Controllers/       # Controllers finos: validam entrada HTTP e delegam aos services
│   └── Api/           # API interna JSON (autenticada por sessão)
├── Database/
│   ├── Migrations/    # Toda a estrutura do banco
│   └── Seeds/         # Dados de desenvolvimento
├── DTO/               # Objetos de transferência (ex.: dados vindos de fornecedores)
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
│   ├── Settings/
│   ├── Suppliers/     # Fornecedores, ofertas, importação e conectores
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
| `categories` | Categorias hierárquicas |
| `suppliers` | Fornecedores |
| `products` | Catálogo interno (SKU, EAN, dimensões, peso) |
| `product_images` | Imagens dos produtos |
| `supplier_offers` | Ofertas dos fornecedores (custo, estoque, frete, prazo) |
| `supplier_imports` | Histórico de importações de arquivos |

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

O `DevelopmentSeeder` cria configurações padrão, usuários admin e operador, categorias, 3 fornecedores, 8 produtos e ofertas de exemplo (incluindo produto sem oferta, produto sem estoque, produto inativo e oferta sem vínculo). Ele se recusa a rodar com `CI_ENVIRONMENT=production`.

Para popular só as configurações padrão (útil em produção):

```bash
php spark db:seed SettingsSeeder
```

## Execução

```bash
php spark serve --port 8080
```

Acesse <http://localhost:8080> e entre com o e-mail e a senha definidos em `SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD`.

Telas disponíveis: dashboard, fornecedores, categorias, produtos, ofertas, importação CSV, usuários, configurações e logs (auditoria e sincronização). Os módulos das próximas fases aparecem desabilitados no menu.

Em produção, aponte o servidor web para a pasta `public/`, use `CI_ENVIRONMENT=production` e `app.forceGlobalSecureRequests=true` (HTTPS).

### Importação CSV

Na tela **Importações**, baixe o modelo de CSV, preencha e envie para um fornecedor. A importação:

- cria ou atualiza ofertas pela chave fornecedor + SKU do fornecedor;
- vincula automaticamente ao produto pelo SKU interno (`product_sku`) ou, na falta dele, pelo EAN;
- aceita valores no formato brasileiro (`1.234,56`) ou americano (`1234.56`);
- registra erros por linha sem interromper as demais linhas.

## Cron

Ainda não há tarefas agendadas nas Fases 1 e 2. A partir da Fase 8 (automação), comandos `spark` de sincronização serão executados por cron, por exemplo:

```cron
*/15 * * * * cd /caminho/marketplace-hub && php spark <comando-de-sincronizacao> >> writable/logs/cron.log 2>&1
```

Os comandos exatos serão documentados quando forem implementados.

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

- **unit**: normalização decimal, conector CSV, fábrica de conectores e limpeza de dados sensíveis na auditoria;
- **database**: importação CSV, produtos, categorias, fornecedores/ofertas e configurações;
- **feature**: login/logout, controle de acesso por papel, API interna e proteção CSRF.

## Roadmap

| Fase | Escopo | Status |
|---|---|---|
| 1 | Base: estrutura, banco, layout, autenticação, configurações | Concluída |
| 2 | Fornecedores, catálogo, ofertas, importação CSV | Concluída |
| 3 | Motor de seleção de fornecedor | Próxima |
| 4 | Precificação | Planejada |
| 5 | Mercado Livre: OAuth | Planejada |
| 6 | Publicações | Planejada |
| 7 | Pedidos | Planejada |
| 8 | Automação (jobs, cron, webhooks) | Planejada |
| 9 | Outros marketplaces | Planejada |

Detalhes em [ROADMAP.md](ROADMAP.md).
