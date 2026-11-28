# Erros dos sistemas vão para o Kiban Core

Desde 28/11/2026, quando acontece um erro dentro de um sistema (Frota, Ponto, Campo, Agenda, Pedidos...), a casca avisa o Kiban Core. O erro entra na lista "Erros do sistema" do painel. Ali o assistente (Claude) explica o que parece ser, e o aviso chega no Telegram do Carlos. Antes, ninguém vigiava os erros da casca.

## Como funciona

- `app/Licensing/CoreErrorReporter.php` é chamado para todo erro que o Laravel registra (em `bootstrap/app.php`).
- Ele manda para o Core o tipo do erro, a mensagem, o lugar no nosso código (`app/...:linha`) e o endereço da página. Usa o `POST /api/v1/errors`, pelo `CoreClient::reportError()`, com a permissão `onboarding`.
- O mesmo tipo de erro vai no máximo **uma vez a cada 10 minutos**, para não inundar.
- O envio é feito **depois** de a página responder, então nunca deixa nada lento. Se o Core estiver fora, o erro fica só no log, como sempre.
- O Core esconde de novo nomes, e-mails e documentos antes de guardar a mensagem (`Redactor`).

## Quando não manda

- Nos testes, porque `KIBAN_REPORT_ERRORS=false` está no `phpunit.xml`. Também pode ser desligado pelo `.env`.
- Sem as chaves do Core (`KIBAN_CORE_CLIENT_ID` / `KIBAN_CORE_CLIENT_SECRET`).
- No erro "Core fora do ar", que não chegaria mesmo.

Teste: `tests/Feature/CoreErrorReporterTest.php`.
