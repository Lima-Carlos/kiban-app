# Kiban App (a "casca")

Sistema que os clientes da Kiban usam: uma "casca" única com os módulos (Frota, Ponto...). Cada empresa entra pelo próprio endereço (`padaria.kibancore.com.br`). O login, as licenças e a cobrança ficam no **Kiban Core** (`C:\Projetos\kiban-core`).

## Ambiente
- Tudo roda no Docker Compose. O PHP **não** está instalado no Windows e não deve ser instalado lá.
- Os comandos rodam dentro do container: `docker compose exec app php artisan ...` e `docker compose exec app composer ...`
- A pasta `vendor/` fica num volume do Docker. A `vendor/` do Windows é só uma cópia para o editor. Depois de mudanças no Composer, atualize a cópia no Git Bash com: `docker compose exec -T app tar -cf - vendor | tar -xf -`
- Subir tudo: `docker compose --profile full up -d` (o worker e o scheduler estão no perfil `full`).
- Endereços (desenvolvimento):
  - central: http://localhost:8090/entrar
  - empresa: http://{endereço}.localhost:8090 (o Chrome entende `*.localhost` sozinho)
  - o Core precisa estar ligado em http://localhost:8080
- Portas próprias (não batem com as do Core): site 8090, banco 5433, Redis 6380.
- Testes: `docker compose exec app php artisan test` (usam o banco separado `kiban_app_test` e um Core simulado, nunca o real).

## Tecnologias
Laravel 13, Filament 5 (as telas), PostgreSQL 18, Redis 7, `firebase/php-jwt` (conferir a licença).

## Regras
- **Comunicação:** responder e documentar sempre em português (pt-BR), de forma simples. O dono do projeto não é programador: explicar cada etapa e esperar a confirmação antes de seguir.
- **Multi-idioma** (pt_BR, en, es): nenhum texto visível fica direto no código. Usar as chaves de `lang/`. Idiomas em `config/kiban.php`. Toda tela tem como trocar o idioma (menu do usuário e topo das páginas simples, rota `locale.switch`). Rótulos de campo/coluna usam chaves com letra maiúscula (`fields.*`), nunca `singular`/`plural`.
- **Marca:** a mesma do Core (logo em `resources/brand/`, imagens em `public/images/brand/`, azul `#044299`).
- **Documentação:** todo arquivo novo ganha uma explicação simples em português em `docs/arquivos/` (listada em `docs/arquivos/README.md`).
- **Senhas:** tudo vai para `CREDENCIAIS.local.md` (no `.gitignore`). O segredo do Core (`KIBAN_CORE_CLIENT_SECRET`) fica só no `.env`, nunca no Git nem no chat.
- **Código:** nomes e comentários em inglês. Documentação para o dono em português.
- **Produção:** desenvolvimento no Windows, produção numa VPS. Manter tudo portável.
- **CI:** o GitHub Actions (`.github/workflows/tests.yml`) roda os testes a cada push. Manter verde. Testes sem Docker e sem internet: o Core é simulado em `tests/TestCase.php` (`Http::fake()` e um par de chaves criado no teste).

## Empresas (multi-tenant) — segurança em primeiro lugar
- Um banco só, e toda tabela de dados de empresa tem `tenant_id` e usa o trait `App\Tenancy\BelongsToTenant`, que filtra sozinho e grava a empresa certa.
- Sem empresa definida, ler ou gravar dá erro (`MissingTenantException`). Nunca "adivinhar" a empresa.
- Em tarefas agendadas ou filas, use `TenantContext::run($tenant, fn () => ...)`.
- Ligações entre tabelas de empresa usam chave composta (`tenant_id` + id), assim o próprio banco impede misturar empresas. Veja `tasks` → `task_lists` em `database/migrations/2026_10_11_100100_create_demo_tables.php`.
- Todo módulo novo precisa de teste de isolamento (veja `tests/Feature/TenancyTest.php`).
- Os botões e formulários das telas mandam pedidos "por trás" (Livewire). Todo porteiro (middleware) que a tela precisa (empresa, licença, idioma, equipe) também fica em `persistentMiddleware` no `AppPanelProvider`, senão esses pedidos dão 403. O teste `tests/Feature/BrowserFlowTest.php` faz esses pedidos como um navegador.

## Pessoas
- Todos da empresa são cadastrados na tela **Pessoas** (`PeoplePage` + `App\People\PeopleService`), que pede ao Core para criar o login (permissão `team`). Celular é o principal; e-mail é opcional (nunca supor que existe). O acesso vai pelo WhatsApp (`AccessNotice`) ou e-mail.
- Cada módulo acrescenta os próprios campos de pessoa (`personFields`/`savePerson`...). Ninguém precisa ir à Área do cliente para cadastrar funcionário.
- Menus por papel: `shouldRegisterNavigation()` nas telas. O app é instalável (manifest + `public/sw.js`).

## Login (sem senhas aqui)
- Todo login passa pelo Core (OAuth2 + PKCE), em `App\Http\Controllers\Auth\SingleSignOnController`. O retorno é sempre no endereço central (`/auth/callback`), e um bilhete de uso único leva a pessoa para o endereço da empresa.
- A cada 10 minutos, `RevalidateMembership` pergunta ao Core (acesso do sistema, `CoreClient::member`) se a pessoa ainda faz parte da empresa. Se o Core estiver fora, a pessoa continua trabalhando. Login fica lembrado por 30 dias.

## Licença
- `App\Licensing\LicenseManager` busca a licença assinada a cada 5 minutos (`kiban:licenses`, e também durante o uso). `LicenseVerifier` confere a assinatura com a chave pública do Core.
- Se o Core cair, vale a última licença até vencer (72 h). Depois disso, a empresa vê "acesso indisponível". Nada é liberado sem licença válida.
- Em produção, fixar a chave pública em `KIBAN_CORE_PUBLIC_KEY`.

## Módulos
- Cada módulo fica em `app/Modules/{Nome}/`. Ele tem uma classe que estende `App\Modules\Module`: a chave é o código do produto no Core; ali ficam os passos, as telas e as regras. A classe é listada em `config/kiban.php` → `modules`.
- As regras de acesso estendem `App\Modules\ModulePolicy`: módulo na licença, "somente consulta", passos obrigatórios (`$requiresSteps`) e limite do plano (`$limit`).
- As telas estendem `App\Filament\Support\ModuleResource`, e as páginas de lista estendem `ModuleRecordsPage` (que explica por que não dá para cadastrar).
- **Primeiros passos:** cada `Step` diz se está feito olhando os dados reais, e de quais passos depende. Os modelos usam `ReportsFirstSteps` para avisar o Core depois de cada mudança.
- O módulo `demo` (Tarefas) é o modelo a copiar.

## Frota (`app/Modules/Fleet`, chave `frota`)
- Todo registro com km passa por `OdometerService::record()` (nunca alterar `odometer` direto). O km nunca volta; só o gestor corrige com `correct()`.
- Cadastro, placa/chassi e situação passam por `VehicleService` (travas contra reaproveitar vaga do plano, limite `vehicles`, histórico em `fleet_vehicle_events`).
- Papéis (`FleetAccess` / `FleetRole`): gestor (dono/admin ou dado na Equipe da frota), mecânico, motorista (ligado pelo e-mail; "qualquer veículo" = reserva) e consulta. Cada policy diz quais papéis veem a tela (`$viewRoles`). Motorista só vê os veículos dele (`visibleVehicleIds`).
- Abastecimentos só por `FuelService` (código de uso único, página do posto `/posto` com limite de tentativas, finalizar, lançar, cancelar). Nunca alterar nem apagar um abastecimento. A média é de tanque cheio a tanque cheio. Painel: `FleetReports`.
- Os módulos podem ter páginas, widgets e um bloco próprio na página Início para cada pessoa (`Module::pages()`, `widgets()`, `personalHome()`, `showsSetupTo()`).
- Arquivos e fotos: `FleetFields::photo()` / `file()` (disco privado, pasta da empresa, foto reduzida no navegador).
- Explicação para o dono: `docs/arquivos/frota.md`.
