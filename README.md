# Kiban App

Os sistemas que os clientes da Kiban usam (Frota, Ponto...), numa "casca" única ligada ao **Kiban Core**:
- o login é único, feito pelo Core;
- cada empresa tem o próprio endereço;
- o menu segue a licença assinada da empresa;
- o sistema continua funcionando se o Core cair (até 72 h).

## Como rodar (desenvolvimento)
1. Ligue o Core (`C:\Projetos\kiban-core`): `docker compose --profile full up -d`.
2. Nesta pasta: `cp .env.example .env` (só na primeira vez) e `docker compose --profile full up -d`.
3. Primeira vez:
   - `docker compose exec app composer install`
   - `docker compose exec app php artisan key:generate`
   - `docker compose exec app php artisan migrate`
4. No Core, em **Configurações → Sistemas conectados → Conectar sistema**:
   - endereço de retorno `http://localhost:8090/auth/callback`;
   - copie a identificação e o segredo para `KIBAN_CORE_CLIENT_ID` e `KIBAN_CORE_CLIENT_SECRET` no `.env` desta pasta.
5. Abra `http://localhost:8090/entrar` e digite o endereço da empresa.

Explicações de cada parte: [docs/arquivos/README.md](docs/arquivos/README.md).
