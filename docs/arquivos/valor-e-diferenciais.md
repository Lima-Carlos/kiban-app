# Diferenciais para ganhar da concorrência (09/11/2026)

Pedido do dono: "pode fazer o que não tem custo hoje; o que tem custo, deixar pronto para só adicionar depois".

## Prontos e ligados (sem custo)

### "Por que deu isso?" no espelho de ponto
- Em cada dia do espelho (Ponto → Apuração e Meu ponto), o link **"Por que deu isso?"** abre a conta do dia em frases simples:
  - o horário previsto;
  - as batidas em pares, com as horas de cada par;
  - a tolerância da CLT;
  - a hora extra ou o banco de horas;
  - o atraso, a falta e o adicional noturno (com a hora de 52min30s);
  - as correções com o motivo;
  - os avisos.
- Ataca a reclamação nº 1 do mercado: "o sistema calculou errado".
- Arquivos: `app/Modules/TimeClock/Services/DayExplainer.php`, botão em `TimesheetPage` (`whyAction`), textos em `lang/*/timeclock.php` (`why`). Teste: `tests/Feature/TimeClockWhyTest.php`.

### Resumo da semana
- Toda **segunda às 7h** (no fuso da empresa), o dono e os administradores recebem os números da semana de **todos os sistemas juntos**:
  - Ponto: horas extras, faltas, atrasos e batidas faltando;
  - Frota: combustível, em reais e litros;
  - Campo: OS concluídas, nota dos clientes, OS atrasadas e orçamentos esperando;
  - Agenda: atendimentos e faltas;
  - Despesas: aprovadas e esperando.

  O resumo também traz o que precisa de atenção agora.
- O resumo chega em três lugares:
  - no **sino**;
  - na página **Empresa → Resumo da Semana**, que mostra qualquer semana passada;
  - por **e-mail**, enviado pelo Core. A empresa pode desligar o e-mail na própria página.
- Só a Kiban consegue dar isso, porque vende vários sistemas que conversam.
- Arquivos:
  - `app/Digest/WeeklyDigest.php` (monta e envia);
  - `app/Digest/ModuleWeek.php` (os números de cada sistema);
  - `Module::weeklySummary()` (cada módulo diz os seus números);
  - `app/Filament/Pages/WeeklyDigestPage.php`;
  - `app/Console/Commands/SendWeeklyDigests.php` (`kiban:weekly-digest`, de hora em hora);
  - a coluna `tenants.weekly_digest_email`.

  Teste: `tests/Feature/WeeklyDigestTest.php`.

### Trocar de sistema sem digitar (planilhas)
- Em **Colaboradores** e em **Frota → Veículos**, o botão **"Planilha"** importa um Excel/CSV e baixa o modelo com as colunas. Os clientes do Campo já tinham isso.
- **Colaboradores:**
  - entram sem acesso; a equipe é criada se não existir;
  - com o Ponto, "Bate ponto = sim" pede admissão, local (ou "externo") e horário pelo nome, além da Matrícula e do PIS;
  - quem já existe (mesmo CPF ou e-mail) não se repete.
- **Veículos:**
  - placa nova é cadastrada com o km atual (o limite do plano vale);
  - placa existente tem os dados atualizados, mas o km não muda por aqui.
- Linha com erro fica de fora com o motivo; as outras entram.
- Arquivos: `app/Support/Spreadsheet/SpreadsheetImporter.php` (leitor comum), `app/People/PersonImporter.php`, `app/Modules/Fleet/Services/VehicleImporter.php`, `app/Filament/Support/ImportActions.php`. Teste: `tests/Feature/SpreadsheetImportTest.php`.

### Baixar todos os dados
- **Empresa → Identidade → "Baixar todos os dados"** (dono e administradores). O sistema monta, em segundo plano, um **.zip** com:
  - uma planilha (CSV com ";") para cada tipo de cadastro;
  - as fotos, recibos, assinaturas e anexos.

  Códigos de acesso, PINs e senhas nunca vão no arquivo.
- O link chega no sino e vale **7 dias**; depois, o arquivo é apagado (`kiban:notices-cleanup`).
- Argumento de venda: "os dados são seus".
- Arquivos: `app/Exports/CompanyExport.php`, `app/Jobs/ExportCompanyData.php`, `app/Http/Controllers/CompanyExportController.php` (rota `/empresa/exportacao/{arquivo}`). Teste: `tests/Feature/CompanyExportTest.php`.

### Página do cliente final (Campo)
- Na ficha do cliente do Campo, o botão **"Página do cliente"**:
  - abre o link;
  - envia pelo WhatsApp (botão grátis);
  - gera um link novo, e o antigo para de funcionar.
- A página `/cliente/{código}` não precisa de login e mostra:
  - as próximas visitas;
  - os orçamentos;
  - os serviços feitos no último ano, com o relatório;
  - os aparelhos do PMOC, se a empresa tiver.

  Tudo aparece com a **identidade da empresa** (nunca a da Kiban).
- Arquivos: `app/Modules/Field/Services/CustomerPortal.php`, `app/Http/Controllers/Field/CustomerPortalController.php`, `resources/views/field/portal.blade.php`, coluna `fd_customers.portal_token`. Teste: `tests/Feature/FieldPortalTest.php`.

## Prontos, mas desligados (custam; o dono decide quando ligar)

### "Pergunte ao seu sistema" (Claude)
- Página **Empresa → Pergunte ao seu Sistema**. O dono escreve uma pergunta com as próprias palavras, e o Claude responde (pelo Core) **só com os números** das últimas 6 semanas, nunca com nomes.
- O limite é de 20 perguntas por empresa por dia, dentro do teto mensal do Claude.
- Para ligar:
  - `KIBAN_ASK_ENABLED=true` no `.env` da casca;
  - a chave "Clientes: Pergunte ao seu sistema" no Core (Assistente do dono).
- Arquivos: `app/Filament/Pages/AskPage.php`, `CoreClient::ask()`. Teste: `tests/Feature/AskPageTest.php`.

### WhatsApp automático (API oficial da Meta, cobra por mensagem)
- Ligado, os **lembretes da Agenda** de amanhã saem sozinhos às 17h, uma vez, e o agendamento fica marcado como lembrado. Hoje continua o botão grátis da tela Lembretes.
- Para ligar:
  - `KIBAN_WHATSAPP_AUTO=true`, `WHATSAPP_TOKEN` e `WHATSAPP_PHONE_NUMBER_ID` no `.env`;
  - o modelo de mensagem aprovado no painel da Meta. O nome padrão é `kiban_lembrete_agenda`, com 6 campos: nome, empresa, serviços, pet, quando e link.
- Arquivos: `app/Messaging/WhatsAppCloud.php`, `app/Console/Commands/SendAgendaReminders.php` (`kiban:agenda-reminders`). Teste: `tests/Feature/WhatsAppAutoTest.php`.

### Não feito, de propósito: leitura do recibo pela foto (Despesas)
- Mandar a foto do recibo para a IA iria contra a regra de privacidade do projeto, porque a foto pode ter CPF e nomes, e para o Claude só vão números e textos mascarados.
- Fica para decidir com o dono: um serviço de leitura de recibos (OCR) com contrato de dados, ou a regra mudar.

## No Core
O e-mail do resumo, a abertura escrita pelo Claude (desligada), a API do "Pergunte", o Pix automático (Asaas, desligado) e o aviso do reajuste na Área do cliente estão em `kiban-core/docs/arquivos/valor-e-diferenciais.md`.
