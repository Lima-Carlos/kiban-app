# Ambiente

**O que é:** como a casca roda no seu computador. Na produção, ela vai para a VPS.

## Docker (`docker-compose.yml`, pasta `docker/`)
| Parte | Container | Porta no Windows |
|---|---|---|
| Site (Nginx) | `kibanapp-nginx` | 8090 |
| PHP | `kibanapp-php` | — |
| Banco (PostgreSQL 18) | `kibanapp-db` | 5433 |
| Redis | `kibanapp-redis` | 6380 |
| Fila (perfil `full`) | `kibanapp-worker` | — |
| Agendador (perfil `full`) | `kibanapp-scheduler` | — |

As portas são diferentes das do Core (8080, 5432, 6379), então os dois rodam juntos.

## Endereços
- **Endereço único** (todas as empresas): http://localhost:8090. Na produção, https://app.kibancore.com.br.
- **Central:** http://localhost:8090/entrar: entrar com a conta Kiban, ou digitar o nome da empresa. É também para onde o Core devolve a pessoa depois do login (`/auth/callback`).
- **Empresa:** http://localhost:8090/padaria. Na produção, https://app.kibancore.com.br/padaria.
- A casca fala com o Core por `KIBAN_CORE_INTERNAL_URL` (dentro do Docker é `host.docker.internal:8080`). O navegador usa `KIBAN_CORE_URL` (`localhost:8080`).

## Configuração (`.env`, nunca vai para o GitHub)
- `KIBAN_CORE_CLIENT_ID` / `KIBAN_CORE_CLIENT_SECRET`: identificação e segredo da casca. Ficam no Core em **Configurações → Sistemas conectados**.
- `KIBAN_CORE_PUBLIC_KEY` (opcional, recomendado na produção): a chave pública do Core fixada aqui.
- `TRUSTED_PROXIES` (opcional): quem pode avisar que a pessoa chegou com cadeado e por qual porta (o Caddy no teste externo, o proxy da VPS). Padrão `*`.
- **Teste no celular de verdade:** o comando `teste-externo ligar` / `desligar`, que fica no Core (veja `kiban-core/docs/arquivos/teste-externo.md`), troca `APP_URL`, `KIBAN_CORE_URL` e `APP_DEBUG` daqui junto com os do Core.

## Dados de demonstração (`php artisan kiban:demo`)
- Recomeça os dados do zero, **só no PC**: apaga todas as empresas, pessoas, veículos, batidas, fotos e avisos, e preenche a empresa de demonstração do Core (`transportes-demo`). Ela recebe os papéis de cada conta, 3 veículos, abastecimentos, uma manutenção, um local de trabalho, o horário e as batidas dos últimos dias.
- Roda junto com o do Core pelo atalho **`dados-demo`** (na pasta do Core); veja `kiban-core/docs/arquivos/dados-demo.md`.
- Arquivo: `app/Console/Commands/ResetDemoData.php`. Testes: `tests/Feature/DemoDataTest.php`.

## Empresa dos vídeos (`php artisan kiban:video-demo`)
- **Só no PC.** Apaga e preenche **só** a empresa fictícia dos vídeos, a "Rede Exemplo Fibra" (`rede-exemplo`, criada antes pelo mesmo comando no Core). Nunca toca na empresa de testes do dono.
- Dados limpos para gravar:
  - 4 veículos;
  - 8 pessoas batendo ponto na "Base Centro", sem alertas;
  - contas em português, espanhol e inglês;
  - o mês passado fechado.
  - **Campo** (quando a empresa tem o Campo no Core): modelo de provedor com preços, identidade da empresa (cor verde), Lucas, Ana, Sofía e John como técnicos, 8 clientes inventados ao redor da base, a agenda de hoje (Lucas e Ana), 2 OS para agendar (uma urgente), 3 OS concluídas ontem pelo celular (com roteiro, foto e assinatura) e 3 orçamentos (aprovado, enviado e rascunho).
- Quem usa é o estúdio de vídeos (`kiban-videos/scripts/empresa.sh`).
- Arquivo: `app/Console/Commands/VideoDemoData.php`. Testes: `tests/Feature/DemoDataTest.php`.

## Agendador (`routes/console.php`)
- `kiban:licenses`, a cada 5 minutos: renova as licenças e envia os primeiros passos ao Core.
- `kiban:field`, a cada minuto: procura no mapa (OpenStreetMap) os endereços de clientes do Campo ainda sem ponto, no máximo 1 por segundo.
- `kiban:fleet`, de hora em hora: rotina da Frota (códigos de abastecimento não finalizados expiram).

## Arquivos
- Docker:
  - `docker-compose.yml`;
  - `docker/php/Dockerfile` e `docker/php/php.ini`;
  - `docker/nginx/default.conf` (os arquivos em `/storage/` são entregues pelo Laravel, só com links assinados);
  - `docker/postgres/create-test-database.sql` (cria o banco de testes `kiban_app_test`).
- `.env.example` (modelo da configuração) e `config/kiban.php` (configurações da Kiban: Core, domínio, módulos, idiomas, marca).
- `.github/workflows/tests.yml`: testes automáticos no GitHub a cada envio.
