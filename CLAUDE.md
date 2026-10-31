# Kiban App (a "casca")

Sistema que os clientes da Kiban usam: uma "casca" única com os módulos (Frota, Ponto...). Todas as empresas usam um endereço só, com a empresa no caminho (`app.kibancore.com.br/padaria/...`). O login, as licenças e a cobrança ficam no **Kiban Core** (`C:\Projetos\kiban-core`).

## Ambiente
- Tudo roda no Docker Compose. O PHP **não** está instalado no Windows e não deve ser instalado lá.
- Os comandos rodam dentro do container: `docker compose exec app php artisan ...` e `docker compose exec app composer ...`
- A pasta `vendor/` fica num volume do Docker. A `vendor/` do Windows é só uma cópia para o editor. Depois de mudanças no Composer, atualize a cópia no Git Bash com: `docker compose exec -T app tar -cf - vendor | tar -xf -`
- Subir tudo: `docker compose --profile full up -d` (o worker e o scheduler estão no perfil `full`).
- Endereços (desenvolvimento):
  - central: http://localhost:8090/entrar
  - empresa: http://localhost:8090/{empresa} (a empresa no caminho; `App\Http\Kernel` + `App\Tenancy\TenantPath` a separam do resto do endereço)
  - o Core precisa estar ligado em http://localhost:8080
- Portas próprias (não batem com as do Core): site 8090, banco 5433, Redis 6380.
- Testes: `docker compose exec app php artisan test` (usam o banco separado `kiban_app_test` e um Core simulado, nunca o real).

## Tecnologias
Laravel 13, Filament 5 (as telas), PostgreSQL 18, Redis 7, `firebase/php-jwt` (conferir a licença).

## Regras
- **Comunicação:** responder e documentar sempre em português (pt-BR), de forma simples. O dono do projeto não é programador e tem pouco tempo.
- **Autonomia (29/10/2026):** decidir sozinho tudo o que segue `docs/planejamento/diretrizes.md` do Core (`kiban-core`) (leia antes de decidir). Continua com o dono: gastar dinheiro, criar contas em serviços, publicar fora do PC, apagar dados reais e mudar o negócio.
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
- **Conexões entre sistemas:** toda função nova que liga dois sistemas entra no catálogo `App\Connections\ConnectionCatalog` (chave, sistemas, quem vê pelo papel, "Ver onde", textos em `lang/*/connections.php`); a conta fica numa ponte em `app/Connections/Bridges/` (`FieldTimeClock`, `FieldFleet`, `FleetTimeClock`, `FieldEpi`). O catálogo alimenta a janela "seus sistemas agora trabalham juntos" (dispensa por pessoa, empresa e conexão em `connection_dismissals`; sem a caixa volta no dia seguinte) e a página Empresa → Sistemas juntos. Nunca bater o ponto por alguém: só lembrar. `kiban:video-demo` marca todas como vistas. Explicação: `docs/arquivos/conexoes.md`.

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
- Peças compartilhadas (Ponto e Campo): `App\Support\Country`, `App\Support\Geo` (distância, link do OpenStreetMap), `App\Filament\Support\MapPoint` ("Usar minha localização"), `Country::formatPhone()` e `Country::currency()`, `App\Models\CompanyProfile` (identidade da empresa nos documentos para os clientes dela: logo, cor, dados, rodapé). Não copiar: reaproveitar.
- **Documentos e vencimentos** (de todas as pessoas, comum a todos os sistemas; não é módulo): CNH, ASO, NR... Regras só em `App\People\Documents\PersonDocumentService` (tipos prontos por país em `DocumentTemplates`; vencimento pela validade do tipo; renovar = novo documento, o antigo ganha `replaced_at` e fica no histórico; situação calculada no fuso da empresa). Ver e cadastrar: a mesma regra do CPF (`canSeeDocuments`: dono, admin, RH); dado de saúde e anexo nunca para gestores da Frota/Campo (eles só veem "vencido" no "Atenção"); a pessoa vê os dela ("Meus documentos", menu do usuário). Anexo só pela rota `people.documents.file`. Avisos pela rotina diária `kiban:documents` (uma vez por marco). Apagar a pessoa apaga documentos e anexos. Telas: Vencimentos (`DocumentsPage`), `PersonDocumentsPage`, `DocumentTypesPage`, `MyDocumentsPage`. Explicação: `docs/arquivos/documentos.md`.
- **EPI e ferramentas** (de todas as pessoas, comum a todos os sistemas; não é módulo): regras só em `App\People\Epi\EpiService` (itens prontos por país em `EpiTemplates`; estoque simples que pode ficar negativo, nunca bloqueia; vida útil → `replace_on`; devolução/troca; avisos `kiban:epi` uma vez por marco; "Atenção"). Entrega assinada com o dedo (`signature-pad`) guarda imagem, IP, aparelho, texto de ciência e hash, e **nunca muda nem é apagada** (os modelos recusam; só `purgePerson` apaga, quando o sistema apaga a pessoa). Quem cuida: dono, admin e RH (tudo); gestores da Frota/Campo só ferramentas (`kindsFor`); a pessoa assina em "Minhas entregas". Interruptor único para vender por licença no futuro: `EpiService::enabled()`. Campo: `ServiceType::required_epi` + ponte `FieldEpi` (só aviso). Telas: `EpiPage`, `EpiItemsPage`, `PersonEpiPage`, `MyEpiPage`. Ficha PDF: `EpiRecord`. Explicação: `docs/arquivos/epi.md`.
- Menus por papel: `shouldRegisterNavigation()` nas telas. O app é instalável (manifest + `public/sw.js`, um app para todas as empresas).
- Seletor de sistemas: o menu e o Início mostram só o sistema atual (`App\Access\CurrentModule`, lembrado em `tenant_user.current_module`; `?sistema=` ou a tela aberta trocam). Toda tela de módulo usa `InModuleMenu` (os `ModuleResource` já usam) e nunca define grupo no menu. Pessoas, Perfis e Equipes ficam no grupo "Empresa". Ícones em `ModuleSwitcher` (render hook `SIDEBAR_NAV_START`).

## Login (sem senhas aqui)
- Todo login passa pelo Core (OAuth2 + PKCE), em `App\Http\Controllers\Auth\SingleSignOnController`. O retorno é sempre `/auth/callback` (sem empresa). Um login vale para todas as empresas da pessoa; cada empresa confere a sua participação (`RevalidateMembership`, por empresa). Página central `/entrar`: login sem saber a empresa, última empresa lembrada (cookie `kiban_empresa`).
- Endereço único: nunca montar endereço de empresa à mão; usar `Tenant::url()`, `url()`/`route()` (já levam a empresa) e `asset()` (raiz). Nomes reservados em `TenantResolver::RESERVED` (o Core também bloqueia).
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
- Telas novas: `$langGroup` no `ModuleResource` + chave `new` nos 3 idiomas ("Novo veículo", nunca "Criar Veículo"); páginas de cadastro usam `NewRecordTitle`. Ações de linha com nome (`->link()` ou menu ⋮ `ActionGroup`), nunca `iconButton()` solto. Lista cujo cadastro espera um passo mostra o passo (`EmptyListShowsPendingStep`). Parâmetros `{record}`/`{order}` só aceitam números (`AppServiceProvider::register`), e o endereço errado dá 404.
- Mensagens de validação humanas nos 3 idiomas (`lang/*/validation.php`); mensagem própria com `->validationMessages()` quando a genérica não basta.
- Faixa "Teste grátis: faltam N dias" só em `App\Licensing\TrialBanner` (todos os módulos em teste, só dono/admin, dias no fuso da empresa).

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

## Campo (`app/Modules/Field`, chave `campo`, "Kiban Field" em inglês)
- Ordem das partes: O1 clientes ✔ → O2 tipos de serviço, roteiro do técnico, materiais e modelos por ramo ✔ (`FieldTemplates`; o roteiro é JSON em `ServiceType::checklist`, com código fixo por pergunta; a OS copia na abertura) → O3 OS e agenda ✔ (`WorkOrder`, toda mudança por `WorkOrderService` com histórico em `OrderEvent`; OS nunca é excluída; conflito de horário só avisa; fuso da empresa em `OrderResource::timezone()`) → O2b orçamentos ✔ (`QuoteService`; página pública `QuoteLinkController` com link aleatório; aprovado vira OS com `origin = quote` e aviso no sino; respondido nunca muda) → O4 técnico no celular sem internet ✔ (`OrderExecutionService` aplica cada peça uma vez, `client_id`; fila `public/js/field-queue.js`; `sw.js` guarda Minhas OS e cada OS; km vai para a Frota com `OdometerSource::WorkOrder`) → O5 relatório ✔ (`OrderReport`, link assinado e PDF com a identidade da empresa em `CompanyProfile`, compartilhada por todos os sistemas). **Primeira versão vendável completa (30/10/2026).** → dia do técnico ✔ (`DayPlanner` estimativa sem mapa pago; `DayWatch` avisos uma vez por `Notifier::firstTime`; peças `on_the_way`/`delay`/`handoff`; avisos no celular por web push em `PhoneAlerts`, chaves VAPID no `.env`; nota do cliente e retorno em `WorkOrderService`). Comentários dentro de `x-data` nunca podem ter aspas duplas (cortam o atributo). → O6 painel ✔ (`FieldReports`: hoje por técnico, mês, quadro "Atenção" e técnicos mais perto ao agendar; `Filament/Dashboard`; "avisar o cliente do atraso" no WhatsApp) → O7 ✔ ligação com Ponto e Frota (lembrete do ponto na primeira/última OS, aproveitamento e custo estimado do deslocamento só para o gestor; veja "Conexões entre sistemas"). Depois: PMOC, estoque no carro. Limite do plano: técnicos ativos (`FieldModule::LIMIT_TECHNICIANS`).
- Funções (`FieldRole`): gestor, atendimento (`dispatcher`), técnico, consulta. Quem altera cada tela fica em `FieldPolicy::$editRoles`.
- Cliente (`Customer`, `fd_customers`) com vários endereços (`Site`, `fd_sites`, sempre um principal: `ensureMainSite()`). Regras do cliente só em `CustomerRules` (formulário, planilha e, depois, loja/API): CPF/CNPJ no Brasil, documento livre fora, documento e código únicos, telefones com `Country::phone()` e exibidos com `Country::formatPhone()`.
- Ponto no mapa: `location_source` (typed/import/search/technician) e `locate_status` (pending/found/not_found). Um ponto de pessoa nunca é trocado pela busca. A busca é `AddressLocator` (OpenStreetMap, 1 por segundo, rua+número → rua → bairro), rodada por `kiban:field` a cada minuto; CEP pelo ViaCEP. Nos testes, serviços externos ficam em `$this->externalServices` do `TestCase`.
- Planilha: `CustomerImporter` (xlsx/csv, colunas em 3 idiomas e apelidos, atualiza por código ou documento, nunca apaga com célula vazia).
- Explicação para o dono: `docs/arquivos/campo.md`.
