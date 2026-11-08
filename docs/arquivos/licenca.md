# Licença

**O que é:** o "documento" assinado pelo Core que diz o que cada empresa pode usar:
- quais sistemas;
- se algum está em "somente consulta";
- os limites do plano;
- se a empresa está ativa.

## Como funciona
- A casca pede a licença ao Core **a cada 5 minutos** (`kiban:licenses`) e também durante o uso, sem fazer ninguém esperar.
- A licença vem **assinada**. A casca confere a assinatura com a chave pública do Core. Uma licença alterada ou falsa não vale nada.
- A chave pública é baixada do Core uma vez e guardada. Em produção, o recomendado é fixá-la no `.env` (`KIBAN_CORE_PUBLIC_KEY`).

## Se o Core cair
- A casca continua com a **última licença válida**, que dura **72 horas**.
- Quando faltam menos de 24 h para vencer, todos veem um aviso no topo da tela.
- Depois de 72 h sem o Core, a empresa vê "Acesso indisponível". Nada é liberado sem licença válida.
- O Core voltou: na próxima visita (ou em até 5 minutos), tudo volta ao normal sozinho.

## O que a licença controla
- **Menu:** só aparecem os sistemas contratados. Abrir o endereço de um sistema não contratado dá "sem permissão".
- **Somente consulta** (contrato encerrado ou pagamento atrasado): dá para ver, mas os botões de criar, editar e apagar somem, e um aviso explica o motivo.
- **Limites do plano** (ex.: 50 veículos): ao chegar no limite (mais a tolerância configurada no Core), o botão de cadastrar some e a tela explica.
- **Empresa suspensa** no Core: "Acesso indisponível".
- **Teste grátis:** enquanto **todos** os sistemas da empresa estão em teste, uma faixa discreta no topo de todas as telas diz "Teste grátis: faltam N dias · Ver planos" ("termina hoje" no último dia). Os dias contam no fuso da empresa. Nos últimos 3 dias a faixa fica laranja. "Ver planos" abre a Área do cliente no Core (`/conta/sistemas`). **Só o dono e os administradores veem**; funcionários, motoristas e técnicos não. No celular, a faixa ocupa uma linha só.
- Quando a empresa já paga um sistema e testa outro, a faixa some e vale o aviso antigo "O período de teste de X termina em..." (nos últimos 7 dias, também só para quem gerencia).

## Arquivos
- `app/Licensing/`:
  - `CoreClient` (conversa com o Core);
  - `CoreUnavailableException` e `InvalidGrantException` (Core fora do ar ou que recusou o pedido);
  - `LicenseManager` (buscar e guardar);
  - `LicenseVerifier` (conferir a assinatura);
  - `License` e `LicenseState` (o que a licença diz);
  - `LicenseNotices` (avisos no topo);
  - `TrialBanner` (a faixa do teste grátis: quando aparece, para quem e quantos dias faltam).
- `app/Http/Middleware/EnsureLicense.php`: bloqueia sem licença válida.
- `app/Console/Commands/RefreshLicenses.php`: comando `kiban:licenses`.
- Telas: `resources/views/filament/license-notice.blade.php` e `resources/views/filament/trial-banner.blade.php` (a faixa do teste).
- Testes: `tests/Feature/LicenseTest.php` e `tests/Feature/TrialBannerTest.php` (a faixa).

## Parte nova aparece na hora (02/11/2026)
Quando a pessoa volta para a aba depois de ficar fora mais de 8 segundos (por exemplo, depois de contratar ou testar outra parte na Área do cliente), a página pede uma licença nova em `POST /licenca/atualizar` (`LicenseRefreshController`, no máximo uma renovação real a cada 15 s por empresa) e recarrega sozinha só se as partes mudaram. Peça do navegador: `resources/views/filament/license-sync.blade.php`. Sem internet, nada acontece (a licença é renovada de qualquer jeito a cada poucos minutos).

O botão **"Abrir meus sistemas"** do Core (no Início da Área do cliente e na tela Sistemas, logo depois de começar um teste) abre o endereço com `?atualizar=1` (08/11/2026). A casca (`EnsureLicense`) busca a licença nova na mesma hora e mostra o endereço sem o `?atualizar=1`, então a parte nova já está no menu. Se a pessoa ainda não entrou, isso acontece antes do login. As duas formas usam `LicenseManager::refreshNow()`, que faz no máximo uma busca real a cada 15 segundos por empresa.

## Faixa de pagamento dentro dos sistemas (02/11/2026)
A licença ganhou um campo opcional `billing` (`due_on`, `overdue`: a fatura em aberto mais antiga, montada pelo Core em `LicenseService::billing()`; casca antiga ignora). Para o dono e os administradores, a faixa "Sua fatura vence em N dias / hoje / está vencida · Ver faturas" aparece a partir de 3 dias antes do vencimento (`App\Licensing\PaymentBanner`, `resources/views/filament/payment-banner.blade.php`). Colaboradores nunca veem.

