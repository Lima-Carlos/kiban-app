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
- **Central:** http://localhost:8090/entrar. A pessoa digita o endereço da empresa. É também para onde o Core devolve a pessoa depois do login.
- **Empresa:** http://padaria.localhost:8090. Na produção será padaria.kibancore.com.br.
- A casca fala com o Core por `KIBAN_CORE_INTERNAL_URL` (dentro do Docker é `host.docker.internal:8080`). O navegador usa `KIBAN_CORE_URL` (`localhost:8080`).

## Configuração (`.env`, nunca vai para o GitHub)
- `KIBAN_CORE_CLIENT_ID` / `KIBAN_CORE_CLIENT_SECRET`: identificação e segredo da casca. Ficam no Core em **Configurações → Sistemas conectados**.
- `KIBAN_TENANT_DOMAIN`: domínio das empresas (`localhost` aqui; `kibancore.com.br` na produção).
- `KIBAN_CORE_PUBLIC_KEY` (opcional, recomendado na produção): a chave pública do Core fixada aqui.
- `TRUSTED_PROXIES` (opcional): quem pode avisar que a pessoa chegou com cadeado e por qual porta (o Caddy no teste externo, o proxy da VPS). Padrão `*`.
- **Teste no celular de verdade:** o comando `teste-externo ligar` / `desligar`, que fica no Core (veja `kiban-core/docs/arquivos/teste-externo.md`), troca `APP_URL`, `KIBAN_TENANT_DOMAIN`, `KIBAN_CORE_URL` e `APP_DEBUG` daqui junto com os do Core.

## Agendador (`routes/console.php`)
- `kiban:licenses`, a cada 5 minutos: renova as licenças e envia os primeiros passos ao Core.
- `kiban:fleet`, de hora em hora: rotina da Frota (códigos de abastecimento não finalizados expiram).

## Arquivos
- Docker:
  - `docker-compose.yml`;
  - `docker/php/Dockerfile` e `docker/php/php.ini`;
  - `docker/nginx/default.conf` (os arquivos em `/storage/` são entregues pelo Laravel, só com links assinados);
  - `docker/postgres/create-test-database.sql` (cria o banco de testes `kiban_app_test`).
- `.env.example` (modelo da configuração) e `config/kiban.php` (configurações da Kiban: Core, domínio, módulos, idiomas, marca).
- `.github/workflows/tests.yml`: testes automáticos no GitHub a cada envio.
