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

## Um sistema, vários módulos
- Cada pessoa tem, por módulo, um papel ou nenhum acesso (padrão). Tudo passa por `App\Access\AccessManager` (dono/admin = gestor de tudo; `module_access`; `Module::implicitRole()`). `ModulePolicy` já exige acesso ao módulo.
- Todo módulo novo declara `roles()`, `managerRole()` e, se quiser, `implicitRole()`, `homeWidgets()` (resumo no Início) e os campos de pessoa. Novos perfis sugeridos vão em `config/kiban.php` → `access_profiles`.
- Os módulos devem conversar entre si (mesmas pessoas, mesmos veículos...): é um sistema só.

## Avisos (sem e-mail)
- Avisos só dentro do sistema: o sino (`databaseNotifications`) e o quadro "Atenção" do Início (`Module::attention()`, some quando resolvido). Nada por e-mail (decisão do dono).
- Enviar sempre por `App\Notices\Notifier` com um `App\Notices\Notice` (chaves de tradução; enum/closure nos parâmetros são traduzidos no idioma de quem recebe). Cada aviso guarda a empresa (`tenant_id`) e o sino só mostra os da empresa atual (`User::notifications()`).
- Rotinas passam uma chave `once` (tabela `notice_log`) para nunca repetir. Quem fez a ação não é avisado (`except`).

## Pessoas
- Todos da empresa são cadastrados na tela **Pessoas** (`PeoplePage` + `App\People\PeopleService`). O cadastro central é `App\People\Person` (tabela `people`), e **o login é opcional** (`user_id`). Com login, o Core cria o usuário (permissão `team`); sem login, a pessoa existe só na casca e ganha o acesso depois (`grantLogin`), mantendo a ficha. Quem entra pelo Core ganha a ficha sozinho (`CoreSignIn` → `Person::forUser`).
- Celular é o principal; e-mail é opcional (nunca supor que existe). Celular com o código do país da empresa: `App\Support\Country::phone()`. O acesso vai pelo WhatsApp (`AccessNotice`) ou e-mail.
- Documento: CPF no Brasil (`App\Rules\Cpf`, único por empresa) e documento genérico (tipo + número) nos outros países (`Person::setDocument`). Só dono, administradores e quem o módulo permite (`seesPersonDocuments`, ex.: gestor do Ponto) veem documentos.
- Os ganchos de pessoa dos módulos recebem a `Person`: `personFields`, `personData`, `checkPerson` (regras **antes** de criar o login no Core; recusa com `PersonRuleException`), `savePerson`, `removePerson`, `personGotLogin`, `personNeedsLogin` (ex.: Frota: sem login, sem papel), `personRoleLabel`. Cada seção só aparece para quem gerencia aquele módulo (`managesPeople`) ou para dono/admin.
- "Remover" é **desligar** (`deactivate`): a ficha e o histórico ficam, inativos. Equipes compartilhadas: `App\Models\Team` (`TeamsPage`).
- Peças compartilhadas (Ponto e Campo): `App\Support\Country`, `App\Support\Geo` (distância, link do OpenStreetMap), `App\Filament\Support\MapPoint` ("Usar minha localização"). Não copiar: reaproveitar.
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

## Ponto (`app/Modules/TimeClock`, chave `ponto`)
- Perfis de regras por país (`LaborProfile`): "Brasil (CLT)" completo (Portaria 671, AFD/AEJ, CPF obrigatório, PIS opcional) e "Personalizado" (outros países; a empresa informa as regras). O país e o perfil ficam em `TimeClockSetting`. Nada da lei brasileira pode valer para o perfil "Personalizado".
- Funcionário = `Employee` ligado a uma `Person` (com ou sem login). Regras em `EmployeeService` (limite `employees` de funcionários ativos, CPF no Brasil, admissão, local ou "externo"). Funcionário externo não tem local nem raio.
- Locais (`Workplace`) com fuso horário próprio (as batidas seguem o fuso do local), ponto no mapa e raio opcional (fora do raio: marca, nunca bloqueia).
- Horários (`Schedule`: semanal ou escala tipo 12x36) e o horário de cada funcionário a partir de uma data (`EmployeeSchedule`, histórico). Horário em uso nunca muda (duplicar). O que cada dia espera vem de `ScheduleCalendar`; feriados de `HolidayCalendar` (nacionais do Brasil calculados, opcionais, da empresa por local). Regras de cálculo em `TimeClockSetting` (no Brasil, nunca abaixo de `LEGAL`).
- Batidas só por `PunchService` (hora do servidor, NSR e corrente de hash por empresa, nunca bloqueia: só marca `outside_radius`/`no_location`/`no_photo`). `Punch` nunca muda nem é apagada (só `photo_path` pode virar vazio). Tablet: `KioskService` (segredo no cookie, PIN gerado pelo sistema, guardado como HMAC) e `KioskController` (sem login). Comprovante: `ReceiptService` (código HMAC, endereço assinado relativo ao endereço da empresa).
- Cálculo só por `DayCalculator` (nunca guardado; dia vira 4h depois do fim de turno noturno; tolerância CLT; folga/feriado = extra de folga; avisos de intervalo e interjornada só no Brasil) e `HourBank` (FIFO com vencimento; faltas inteiras não saem do banco). Correções só por `AdjustmentService` (à parte das batidas, com motivo; funcionário pede, gestor aprova; nunca em mês fechado: `MonthClosing`).
- Fechamento do mês só por `MonthClosing` (cópia congelada com SHA-256; reabrir só antes da assinatura; mês fechado não aceita correção). A tela e o PDF leem o mês por `TimesheetDocument` (nunca recalcular um mês fechado). AFD (`AfdFile`) é PRÉVIA a validar (faltam tipos 2/5, .p7s e o AEJ). Rotina `kiban:timeclock` (avisos uma vez só e fotos de batida com 12 meses).
- Painel do Ponto: números só por `TimeClockReports` (agora e o mês, que usa a cópia congelada dos meses fechados). Quadros do Início do gestor: `NowWidget` e `MonthStatsWidget`.
- Fotos das batidas (LGPD): antes da primeira foto, aviso com ciência registrada por `PhotoNoticeService` (versão = REVISION + prazo + contato; sem ciência, nenhuma foto é gravada). "Bater sem foto" só se `allow_punch_without_photo` (flag `photo_declined`); a batida nunca é bloqueada.
- Papéis (`TimeClockRole`): gestor (RH), funcionário (implícito para quem tem login e bate ponto) e consulta. Explicação para o dono: `docs/arquivos/ponto.md`.

## Frota (`app/Modules/Fleet`, chave `frota`)
- Todo registro com km passa por `OdometerService::record()` (nunca alterar `odometer` direto). O km nunca volta; só o gestor corrige com `correct()`.
- Cadastro, placa/chassi e situação passam por `VehicleService` (travas contra reaproveitar vaga do plano, limite `vehicles`, histórico em `fleet_vehicle_events`).
- Papéis (`FleetAccess` / `FleetRole`): gestor (dono/admin ou dado na Equipe da frota), mecânico, motorista (ligado pelo e-mail; "qualquer veículo" = reserva) e consulta. Cada policy diz quais papéis veem a tela (`$viewRoles`). Motorista só vê os veículos dele (`visibleVehicleIds`).
- Abastecimentos só por `FuelService` (código de uso único, página do posto `/posto` com limite de tentativas, finalizar, lançar, cancelar). Nunca alterar nem apagar um abastecimento. A média é de tanque cheio a tanque cheio. Painel: `FleetReports`.
- Manutenção, lavagens, agenda, solicitações do motorista e despesas só por `MaintenanceService`. Concluir um agendamento registra a manutenção/lavagem; troca de óleo e revisão recalculam a próxima (`updateNextServices`). Cancelar devolve a solicitação para "esperando". Mecânico não vê painel nem despesas; despesas só o gestor lança. Nada do histórico é apagado (`WorkPolicy`). O painel soma tudo (`FleetReports::otherCosts`).
- Vistorias só por `InspectionService`: itens da empresa (`ChecklistItem`, prontos com `key` traduzida + próprios com `label`), 5 fotos obrigatórias, problema pede comentário (foto opcional) e vira `ServiceRequest` (`inspection_id`); item grave com problema leva o veículo a "Em manutenção" (evento `StoppedByInspection`). A frequência (`inspection_frequency`) nunca bloqueia: só lembra o motorista e marca o abastecimento (`FuelUpFlag::NoInspection`). As respostas guardam nome e "grave" do momento (histórico).
- Avisos da Frota só em `FleetNotices` (na hora: vistoria com problema, pedidos, abastecimento com alerta; rotina `scan()` no `kiban:fleet`: agendamento amanhã, óleo/revisão, documentos, vistorias faltando depois das 11h).
- Relatórios: planilhas em `FleetExporter` (OpenSpout, valores como número) e ficha em PDF em `VehicleRecord` (DomPDF, fonte DejaVu com subconjunto). Só gestor e consulta (mostram gastos). Números iguais ao painel via `FleetReports::totalsBetween()`.
- Arquivos antigos só por `FleetCleanup`: fotos 6 meses, notas/comprovantes 5 anos, foto do veículo e documentos ficam; os dados nunca são apagados (só o caminho do arquivo vira vazio). Aviso mensal ao gestor antes. Arquivo novo com foto precisa entrar na lista de `FleetCleanup`.
- Os módulos podem ter páginas, widgets e um bloco próprio na página Início para cada pessoa (`Module::pages()`, `widgets()`, `personalHome()`, `showsSetupTo()`).
- Arquivos e fotos: `FleetFields::photo()` / `file()` (disco privado, pasta da empresa, foto reduzida no navegador).
- Explicação para o dono: `docs/arquivos/frota.md`.
