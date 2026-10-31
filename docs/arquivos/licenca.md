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
