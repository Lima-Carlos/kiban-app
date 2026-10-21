# Avisos (o sino e o quadro "Atenção")

Os avisos aparecem **só dentro do sistema**, quando a pessoa entra para trabalhar. Nada vai por e-mail; foi uma decisão do dono, para não incomodar ninguém em casa. Se um dia for preciso, dá para acrescentar o e-mail como uma opção que cada pessoa liga.

## O sino
- Fica no topo de todas as telas. Mostra quantos avisos não foram lidos e confere novidades a cada 30 segundos.
- Cada aviso tem um botão **Abrir**, que leva direto para a tela certa (e marca o aviso como lido).
- A mesma pessoa pode trabalhar em mais de uma empresa: **cada empresa tem os seus avisos**, que nunca se misturam.
- O aviso é escrito **no idioma de quem recebe**: se o mecânico usa o sistema em inglês, recebe em inglês.
- Quem fez a ação não recebe aviso dela (ex.: o motorista que fez a vistoria).
- Avisos com mais de 90 dias são apagados todo dia de madrugada (`kiban:notices-cleanup`). Eles são lembretes, não histórico; o histórico fica nos próprios registros.

## O quadro "Atenção"
- Aparece no topo da página **Início**, só quando há algo **urgente e ainda pendente** (ex.: um veículo parado pela vistoria).
- Some sozinho quando o problema é resolvido. Ninguém precisa "marcar como lido".
- Cada módulo diz o que é urgente para cada pessoa (veja os avisos da Frota em `frota.md`, parte 5).

## Rotinas sem repetição
- Avisos que dependem de data (ex.: "troca de óleo chegando") são conferidos de hora em hora pelas rotinas de cada módulo. Cada um é enviado **uma vez só**: o sistema guarda uma "chave" do que já avisou (tabela `notice_log`).
- Se a situação muda (ex.: de "chegando" para "vencida"), é um aviso novo.
- As rotinas só rodam sozinhas com o agendador ligado (`docker compose --profile full up -d`).

## Arquivos
- `database/migrations/2026_10_21_120000_create_notices_tables.php`: tabela dos avisos (`notifications`, com a empresa) e `notice_log` (o que já foi avisado).
- `app/Notices/Notice.php`: um aviso (título, texto, ícone, cor e a tela que ele abre). Os textos são chaves de tradução, escritas no idioma de quem recebe.
- `app/Notices/TenantDatabaseChannel.php`: grava o aviso junto com a empresa.
- `app/Notices/Notifier.php`: envia para uma lista de pessoas, tirando quem fez a ação e evitando repetição.
- `app/Console/Commands/CleanupNotices.php`: apaga os avisos antigos (`kiban:notices-cleanup`, todo dia às 03:30).
- `app/Models/User.php`: o sino lê só os avisos da empresa atual (`notifications()`), e `preferredLocale()` diz o idioma da pessoa.
- `app/Modules/Module.php` → `attention()`: o que cada módulo põe no quadro "Atenção". A página Início junta tudo (`app/Filament/Pages/Home.php`).
