# Pessoas (e o app no celular)

**O que é:** a tela onde o gestor cadastra **todos da empresa** nos sistemas da Kiban (motoristas, mecânicos, gestores) e envia o acesso. Ninguém precisa ir à Área do cliente para isso.

## Como adicionar uma pessoa
1. Menu **Pessoas** → **Adicionar pessoa**.
2. Preencha:
   - **nome**;
   - **celular** (com DDD; é com ele que a pessoa entra);
   - **e-mail** (opcional);
   - o que ela faz em cada sistema. Na Frota: o **papel** (motorista, mecânico, gestor ou consulta) e, se for motorista, os veículos e a CNH.
3. Aparece o aviso **"Acesso pronto"** com o botão **Enviar pelo WhatsApp**. Ele abre o seu WhatsApp com a mensagem e o link prontos. Se a pessoa tiver e-mail, o acesso também vai por e-mail.
4. A pessoa toca no link, vê o nome da empresa, cria a senha e **cai direto no sistema da empresa**.

Nos bastidores, a casca pede ao Core para criar o login (permissão `team`). Uma pessoa que já tem login na Kiban, por exemplo porque trabalha em duas empresas, continua com **um login só**.

## Na lista
- **Na empresa:** dono, administrador ou membro (o papel na conta Kiban).
- **Nos sistemas:** o papel em cada sistema (ex.: "Frota: Motorista").
- **Acesso:** **Convite enviado** (ainda não criou a senha), **Ativo** ou **Bloqueado**.
- Botões:
  - **Editar**: muda o papel, os veículos e a CNH;
  - **Enviar acesso de novo**: gera um novo link (o anterior para de funcionar);
  - **Remover da empresa**: a pessoa perde o acesso em até 10 minutos; o histórico continua.
- Donos e administradores aparecem, mas são cuidados na Área do cliente.
- Quem pode abrir esta tela: dono, administradores e quem é **gestor** em algum sistema (ex.: gestor da frota).

Na tela **Motoristas** também há o botão **Enviar acesso**, para quem cadastrou o motorista por lá.

## Entrar pelo celular e ficar conectado
- A pessoa entra com o **celular ou o e-mail** e a senha.
- No celular, ela **fica conectada por 30 dias**. A cada 10 minutos o sistema confere com o Core se ela ainda é da empresa: se foi removida ou bloqueada, perde o acesso. Se o Core estiver fora do ar, continua trabalhando.

## App na tela do celular
- No celular aparece o aviso **"Instale o app no celular"**:
  - no Android, com um botão **Instalar**;
  - no iPhone, com a explicação "Compartilhar → Adicionar à Tela de Início".
- O ícone da Kiban vai para a tela inicial. Ao tocar, o sistema abre direto, como um aplicativo.
- O aviso pode ser fechado e não volta mais naquele celular.

## Menus por papel (Frota)
| Papel | Menu |
|---|---|
| Gestor | Tudo |
| Motorista | Início (os veículos dele, com o botão Abastecer) e Abastecimentos. As vistorias entram na parte 4 |
| Mecânico | Início e Veículos. Manutenções e lavagens entram na parte 3 |
| Consulta | Tudo, só para olhar |

## Arquivos
- `app/Filament/Pages/PeoplePage.php`: a tela Pessoas.
- `app/People/`:
  - `PeopleService` (adicionar, novo link, remover, situação);
  - `AccessNotice` (aviso com o WhatsApp).
- Cada sistema acrescenta os próprios campos pela classe do módulo: `personFields`, `personData`, `savePerson`, `removePerson`, `personRoleLabel`, `managesPeople`. Na Frota: `app/Modules/Fleet/Services/FleetPeople.php`.
- `app/Licensing/CoreClient.php` (pessoas no Core) e `CoreValidationException.php`.
- `app/Http/Middleware/RevalidateMembership.php`: confere a cada 10 minutos, com o acesso do próprio sistema.
- 30 dias conectado: `config/auth.php` (`remember`) e a migração `2026_10_12_140000_add_phone_and_remember_to_users.php` (celular e "lembrar de mim").
- App instalável:
  - `app/Http/Controllers/ManifestController.php` (`/manifest.webmanifest`);
  - `public/sw.js`;
  - `resources/views/filament/pwa-head.blade.php`;
  - `resources/views/filament/install-hint.blade.php`;
  - `public/images/brand/icon-192.png`.
- Textos: `lang/*/app.php` (`people`, `install`) e `lang/*/fleet.php` (`people`).
- Testes: `tests/Feature/PeopleTest.php`.
