# Pessoas (e o app no celular)

**O que é:** a tela onde o gestor cadastra **todos da empresa** nos sistemas da Kiban: motoristas, mecânicos, funcionários e gestores. É o **cadastro central**: a mesma pessoa bate o ponto, usa o carro da Frota e, depois, executa as ordens de serviço. Ninguém precisa ir à Área do cliente para isso.

## Com ou sem login
- **Com login** (o padrão): a pessoa entra no sistema com o celular ou o e-mail. O Core cria o login e o convite.
- **Sem login:** para quem **só bate ponto no tablet** da empresa. A pessoa existe só aqui, sem convite.
- Quem foi cadastrado sem login pode **ganhar o acesso depois**, no botão **"Dar acesso ao sistema"**. A ficha e o histórico continuam os mesmos.
- Decisão do dono: **sem login, a pessoa não tem papel na Frota**, porque não consegue entrar no sistema. A parte da Frota some do formulário quando o login está desligado.

## Como adicionar uma pessoa
1. Menu **Pessoas** → **Adicionar pessoa**.
2. Preencha:
   - **nome**;
   - **"Vai entrar no sistema (login)"**: ligado ou desligado;
   - **celular**: obrigatório com login, porque é com ele que a pessoa entra. O código do país entra sozinho, conforme o país da empresa (no Brasil, 55);
   - **e-mail** (opcional);
   - **documento:** no Brasil é o **CPF**, com conferência dos dígitos e sem repetir na empresa. Em outros países é um documento genérico (tipo e número, ex.: "NIF 123456789");
   - **equipe**: dá para criar uma nova ali mesmo;
   - o que a pessoa faz em cada sistema:
     - na **Frota**: o papel e, para motorista, os veículos e a CNH;
     - no **Ponto**: se bate ponto, a admissão, o cargo, o local, se trabalha na rua e o acesso ao Ponto.
3. **Com login**, aparece o aviso **"Acesso pronto"**, com os botões **Enviar pelo WhatsApp** (abre o seu WhatsApp com a mensagem e o link prontos) e **Copiar link**. O link comprido não aparece escrito na tela. Se a pessoa tiver e-mail, o acesso também vai por e-mail.
4. A pessoa toca no link, vê o nome da empresa, cria a senha e **cai direto no sistema da empresa**.

**Antes de criar o login no Core, o sistema confere as regras de cada módulo:** limite do plano, CPF exigido no Ponto do Brasil, admissão e local de trabalho. Se algo estiver faltando, aparece a mensagem e nada é criado, nem no Core.

Uma pessoa que já tem login na Kiban (por exemplo, porque trabalha em duas empresas) continua com **um login só**.

## Quem vê o CPF (decisão do dono)
- Só o **dono**, os **administradores** e o **gestor do Ponto (RH)**.
- Quem gerencia outro sistema (ex.: o gestor da Frota) abre a tela Pessoas **sem o campo de documento**.
- Cada pessoa só vê as partes do formulário dos sistemas que ela gerencia. O dono e os administradores veem tudo.

## Equipes
- Botão **Equipes**, no topo da tela Pessoas: equipes ou setores da empresa (ex.: "Equipe Instalação", "Escritório").
- Servem para o Ponto (filtrar funcionários) e, depois, para o Kiban Campo (mandar uma OS para uma equipe).
- Uma equipe em uso é **desligada**, não apagada.

## Um sistema, vários módulos (acesso por pessoa)
- A empresa usa **um sistema só** (`suaempresa.kibancore.com.br`). Cada sistema contratado (Frota, Ponto...) é um **módulo** no mesmo menu.
- Cada pessoa tem, em **cada módulo**, um papel ou **"Sem acesso"**, que é o padrão. Sem acesso, o módulo nem aparece no menu dela.
- Donos e administradores da empresa têm todos os módulos, como gestores.
- A página **Início** junta os resumos dos módulos que a pessoa acessa e os botões do dia a dia. Os primeiros passos aparecem só para quem configura e ficam recolhidos quando estão concluídos.
- Quem é da empresa mas ainda não tem acesso a nenhum módulo vê: "Você ainda não tem acesso a nenhum sistema. Fale com o gestor".

## Perfis de acesso (setores)
- Menu **Perfis de acesso**, só para donos e administradores.
- Um perfil junta o papel em cada módulo. Ex.: **Logística** = Frota como gestor; **RH** = Ponto como gestor.
- **Perfis sugeridos:** Logística, Motorista, Mecânico, RH e Consulta.
  - Cada sugestão aparece quando a empresa tem o sistema dela. Por exemplo, o "RH" aparece quando a empresa contrata o Ponto.
  - Uma sugestão que a empresa excluiu **não volta**.
- Ao adicionar alguém em **Pessoas**, escolha o perfil: o acesso de cada módulo é preenchido sozinho e ainda dá para ajustar.
- Mudar ou excluir um perfil **não altera quem já foi cadastrado**.

## Na lista
- **Equipe**, **Na empresa** (dono, administrador ou membro) e **Nos sistemas** (ex.: "Frota: Motorista", "Ponto: Funcionário").
- **Acesso:**
  - **Sem login**;
  - **Convite enviado** (ainda não criou a senha);
  - **Ativo**;
  - **Bloqueado**;
  - **Desligada**.
- O filtro mostra as **ativas** (padrão) ou as **desligadas**.
- Botões (com o nome escrito, também no celular): **Editar** e **Dar acesso ao sistema** ficam à vista; os outros ficam no menu **⋮** ("Mais ações"), cada um com o seu nome:
  - **Editar**: a equipe, o documento, o papel e os dados de cada sistema. Nome, celular e e-mail de quem tem login vêm do Core: a pessoa muda na conta dela;
  - **Dar acesso ao sistema**: para quem não tem login;
  - **Enviar acesso de novo**: gera um novo link, e o anterior para de funcionar;
  - **Desligar da empresa** (antes "Remover"): a pessoa sai da lista e perde o acesso em até 10 minutos. **A ficha e o histórico ficam guardados.** No Ponto, o funcionário fica inativo, com a data de saída;
  - **Reativar**: a pessoa volta para a lista, sem login.
- Donos e administradores não podem ser desligados por aqui (isso é na Área do cliente).
- Quem pode abrir esta tela: dono, administradores e quem é **gestor** em algum sistema.

Na tela **Motoristas** também há o botão **Enviar acesso**, para quem cadastrou o motorista por lá.

## Entrar pelo celular e ficar conectado
- A pessoa entra com o **celular ou o e-mail** e a senha.
- No celular, ela **fica conectada por 30 dias**. A cada 10 minutos o sistema confere com o Core se ela ainda é da empresa: se foi desligada ou bloqueada, perde o acesso. Se o Core estiver fora do ar, continua trabalhando.

## App na tela do celular
- No celular aparece o aviso **"Instale o app no celular"**, numa linha só:
  - no Android, com um botão **Instalar**;
  - nos outros casos (iPhone), o link **"Como?"** abre a explicação ("Compartilhar → Adicionar à Tela de Início").
- O ícone da Kiban vai para a tela inicial. Ao tocar, o sistema abre direto, como um aplicativo.
- O aviso pode ser fechado e não volta mais naquele celular.

## Listas no celular (30/10/2026)
No celular, toda lista (pessoas, veículos, abastecimentos, batidas, solicitações...) mostra **um cartão por linha**, com o nome de cada informação, e nenhuma coluna fica cortada. No computador e no tablet, continua tabela. Isso vale para todas as listas de uma vez (`AppServiceProvider`, `stackedOnMobile`), inclusive as do Campo. As ações de cada cartão têm o nome escrito ("Editar", "Renovar"...), nunca só um ícone solto; quando são muitas, ficam no menu ⋮. As abas acima das listas (ex.: "Em aberto, Hoje, Concluídas...") passam para uma segunda linha em vez de ficarem cortadas, e o "Ordenar por" mostra "Padrão".

## Menus por papel (Frota)
| Papel | Menu |
|---|---|
| Gestor | Tudo |
| Motorista | Início (os veículos dele, com os botões Abastecer, Vistoria e Solicitar), Abastecimentos, Vistorias e Solicitações |
| Mecânico | Início, Veículos, Solicitações, Agenda, Manutenções, Lavagens e Oficinas |
| Consulta | Tudo, só para olhar |

## Arquivos
- `app/Filament/Pages/PeoplePage.php`: a tela Pessoas. `app/Filament/Pages/TeamsPage.php`: as equipes.
- `app/People/`:
  - `Person` (a ficha central, com ou sem login);
  - `PeopleService` (adicionar, dar acesso, editar, desligar, novo link, situação, quem vê documentos);
  - `PeopleForm` (ajuda para os campos de cada sistema saberem se a pessoa tem login);
  - `PersonRuleException` (quando um sistema recusa algo, com a mensagem);
  - `AccessNotice` (aviso com o WhatsApp).
- `app/Models/Team.php`: equipe.
- Peças compartilhadas (servem também para o Kiban Campo):
  - `app/Support/Country.php` (país da empresa e celular com o código do país);
  - `app/Rules/Cpf.php` (conferência do CPF);
  - `app/Support/Geo.php` (distância entre dois pontos e link do mapa);
  - `app/Filament/Support/MapPoint.php` + `resources/views/filament/forms/map-point.blade.php` (ponto no mapa com "Usar minha localização").
- Cada sistema acrescenta os próprios campos pela classe do módulo:
  - `personFields`, `personData`, `checkPerson`, `savePerson`, `removePerson`;
  - `personGotLogin`, `personNeedsLogin`, `seesPersonDocuments`, `personRoleLabel`, `managesPeople`.

  Na Frota: `app/Modules/Fleet/Services/FleetPeople.php`. No Ponto: `app/Modules/TimeClock/Services/TimeClockPeople.php`.
- `app/Auth/CoreSignIn.php`: quem entra pelo Core ganha a ficha na empresa sozinho.
- `app/Licensing/CoreClient.php` (pessoas no Core) e `CoreValidationException.php`.
- `app/Http/Middleware/RevalidateMembership.php`: confere a cada 10 minutos, com o acesso do próprio sistema.
- 30 dias conectado: `config/auth.php` (`remember`) e a migração `2026_10_12_140000_add_phone_and_remember_to_users.php`.
- App instalável:
  - `app/Http/Controllers/ManifestController.php` (`/manifest.webmanifest`);
  - `public/sw.js`;
  - `resources/views/filament/pwa-head.blade.php`;
  - `resources/views/filament/install-hint.blade.php`;
  - `public/images/brand/icon-192.png`.
- Textos: `lang/*/app.php` (`people`, `teams`, `map`, `validation`, `install`) e `lang/*/fleet.php` (`people`).
- Acesso por módulo e perfis:
  - `app/Access/AccessManager.php` (quem acessa o quê; sugestões de perfil por sistema contratado);
  - `app/Models/ModuleAccess.php` e `AccessProfile.php` (perfil excluído fica só escondido);
  - `app/Filament/Pages/AccessProfilesPage.php`;
  - `config/kiban.php` → `access_profiles` (sugestões).
- Migrações:
  - `2026_10_12_150000_create_module_access_tables.php`;
  - `2026_10_24_100000_create_people_table.php` (pessoas e equipes; quem já existia ganhou a ficha);
  - `2026_10_24_120000_add_deleted_at_to_access_profiles.php`.
- Testes: `tests/Feature/PeopleTest.php`, `tests/Feature/AccessProfilesTest.php` e `tests/Feature/TimeClockEmployeesTest.php`.

## Dados da pessoa (LGPD)
- A **empresa cliente é a controladora** dos dados dos seus funcionários, e a Kiban é a operadora. Quando um funcionário pede uma cópia dos dados dele, quem entrega é a empresa.
- Na tela **Pessoas**, no menu ⋮, o botão **"Baixar dados (LGPD)"** gera um arquivo com tudo o que os sistemas guardam sobre ela:
  - cadastro e equipe;
  - no Ponto: dados do funcionário, batidas (com local e IP), correções e espelhos;
  - na Frota: dados do motorista e abastecimentos;
  - os documentos com validade (CNH, ASO, treinamentos), sem os anexos. O ASO e outros dados de saúde só entram se quem baixa pode vê-los (veja `documentos.md`).
- O CPF só entra no arquivo se quem baixa pode ver documentos (dono, administradores e RH). As fotos não entram.
- Cada sistema diz o que exporta em `exportPerson()` (`app/Modules/Module.php`). Um sistema novo, como o Campo, precisa implementar esse método.
- O menu do usuário tem o link **"Privacidade"**, que leva à página de privacidade da conta Kiban (canal, "baixar meus dados" e pedidos).
- **Ainda não existe** a exclusão ou anonimização de uma pessoa, nem a exclusão automática dos dados da empresa depois do cancelamento. Faltam decisões do dono e do advogado, porque as batidas precisam ficar guardadas por 5 anos. Veja o roteiro.
