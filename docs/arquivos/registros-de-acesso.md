# Registros de acesso (Marco Civil)

**O que é:** a lei brasileira (Marco Civil da Internet, art. 15, e o Decreto 8.771) obriga quem oferece um sistema pela internet a guardar, por **no mínimo 6 meses** e em sigilo, quem acessou, quando e de onde. Com celulares, muita gente divide o mesmo IP (CGNAT), por isso também é preciso guardar a **porta de origem**.

## O que o sistema guarda
- **Acessos** (tabela `access_logs`): uma linha por sessão e por dia. Cada linha tem a empresa, a pessoa, o IP, a porta, o aparelho, o primeiro e o último momento do dia. A gravação acontece no máximo a cada 10 minutos, então quase não pesa.
- **Batidas de ponto:** cada batida guarda também o IP e a porta. O tablet não tem login, então a própria batida é o registro de acesso dele.
- **Prazo:** 365 dias (`KIBAN_ACCESS_LOG_DAYS`), **nunca menos de 6 meses**, mesmo que alguém configure um número menor. Os mais antigos são apagados sozinhos todo dia, às 03:45.
- **Sigilo:** nenhuma tela mostra esses registros, nem para a empresa cliente. Eles são da Kiban.
- **Quando a justiça pedir:** `php artisan kiban:access-logs --email=... --ip=... --from=AAAA-MM-DD --to=AAAA-MM-DD`. O comando gera uma planilha (CSV) em `storage/app/exports/`.

## Confiar só no "porteiro"
- O IP e a porta verdadeiros chegam do porteiro do servidor (Caddy), nos cabeçalhos `X-Forwarded-For` e `X-Client-Port`.
- O sistema só aceita esses cabeçalhos quando vêm da **rede interna**: o PC, o Docker e o próprio servidor. Esse é o padrão de `TRUSTED_PROXIES`. Ninguém de fora consegue falsificar o IP gravado.
- Nunca usar `TRUSTED_PROXIES=*` em produção.

## Arquivos
- `app/Models/AccessLog.php`;
- `app/Http/Middleware/RecordAccess.php` (ligado depois do login, em `AppPanelProvider`);
- `app/Support/ClientPort.php`;
- `app/Console/Commands/ExportAccessLogs.php`;
- a migração `2026_10_30_020000_create_access_logs_table.php`;
- o agendamento em `routes/console.php`;
- testes: `tests/Feature/AccessLogTest.php`.
