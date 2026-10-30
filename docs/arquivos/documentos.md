# Documentos e vencimentos (CNH, ASO, treinamentos)

**O que é:** um recurso da tela **Pessoas**, comum a todos os sistemas (não é um produto à venda). Guarda os documentos com validade de cada pessoa da empresa (CNH, ASO, NR-10, NR-35...), com o anexo, e **avisa antes de vencer**.

Os documentos do **veículo** (licenciamento, seguro) continuam na Frota, na página do veículo. Não mudaram.

## Tipos de documento
- Cada empresa tem os seus tipos. Os prontos entram sozinhos na primeira vez, conforme o **país da empresa**:
  - **Brasil:** CNH, ASO (exame periódico, 12 meses, **dado de saúde**), NR-10 (24 meses), NR-35 (24 meses), NR-33 (12 meses), NR-11, NR-12, NR-06 (EPI), outro treinamento ou curso, outro documento;
  - **outros países:** carteira de motorista, exame médico (12 meses, dado de saúde), treinamento de segurança, outro documento. Nada da lei brasileira.
- Cada tipo tem:
  - **validade sugerida** em meses (vazia = a pessoa digita a data);
  - **avisar antes** (padrão: 30 dias);
  - se é **dado de saúde**;
  - para qual sistema ele importa: CNH → Frota (motoristas); NR-10, NR-35 e NR-33 → Campo (técnicos).
- Tela: **Vencimentos → Tipos de documento**. Dá para criar, mudar o nome, a validade e o aviso, e **desligar**. Um tipo nunca é apagado (os documentos apontam para ele).

## O documento de uma pessoa
- Campos: tipo, número (opcional), data de **emissão ou realização**, **vencimento**, **anexo** (foto ou PDF) e observação.
- O vencimento é **preenchido sozinho** pela validade do tipo (ex.: NR-10 feita em 10/03/2026 → vence em 10/03/2028). Dá para mudar. **Vazio = não vence.**
- **Situação** (calculada, nunca guardada): **Em dia**, **Vence em N dias** (dentro do "avisar antes" do tipo), **Vence hoje**, **Vencido** ou **Sem vencimento**. O "hoje" segue o fuso da empresa.
- **Renovar** = cadastrar o documento novo. O anterior fica no **histórico** da pessoa ("Substituído").
- **Corrigir**: para um erro de digitação. **Excluir**: só para um documento cadastrado por engano; o anexo é apagado junto e o anterior volta a valer.
- O anexo fica no **disco privado**, na pasta da empresa (`tenants/<empresa>/people/documents`). Ele só abre pelo endereço `/documentos/<id>/arquivo`, que confere quem pode ver. Nunca há endereço público.
- Fica registrado quem cadastrou e quem alterou por último (`created_by`, `updated_by`). A casca não usa o "activity log"; o histórico é o próprio documento antigo, que não some.

## Onde aparece
- **Pessoas:** a coluna **Documentos** ("1 vencido", "2 vencendo", "Em dia") e o botão **Documentos** (ícone de crachá) em cada pessoa. Ele abre a página da pessoa com os documentos atuais, o histórico e **Adicionar documento**.
- **Empresa → Vencimentos:** todos os documentos atuais da empresa, o que vence primeiro no topo. Filtros: situação, tipo e equipe. Botões: **Adicionar documento**, **Baixar planilha** (Excel, do jeito que a lista está na tela) e **Tipos de documento**.
- **Meus documentos** (menu do usuário, no canto de cima): a própria pessoa vê os documentos dela e abre o anexo, **só para ler**. O item só aparece para quem tem documento. Decisão: o menu do usuário serve para todos os sistemas (inclusive quem não bate ponto), por isso o item não ficou perto de "Meu ponto".
- **No celular:** todas essas listas viram **cartões**.
- **Dica na tela vazia:** "Cadastre a CNH dos motoristas, o ASO da equipe e os treinamentos...". Não há assistente de primeiros passos novo.

## Avisos (sem e-mail)
- Rotina **diária** `kiban:documents` (agendador, 10:00 UTC = 07:00 em Brasília). Ela avisa no **sino** quem cuida dos documentos (dono, administradores e RH), **uma vez em cada marco**:
  1. no início do "avisar antes" do tipo (ex.: 30 dias antes);
  2. 7 dias antes;
  3. no dia em que vence (ou depois, se já estiver vencido).
- Um documento cadastrado já perto de vencer recebe só o aviso mais adiantado (ex.: faltando 3 dias, só o de 7 dias).
- Se o sistema ficar um dia desligado, o aviso sai no dia seguinte (nada se perde).
- **Quadro "Atenção" do Início** (em todos os sistemas), para quem cuida dos documentos: "Documentos da equipe: 1 vencido · 3 vencendo". Some quando tudo estiver em dia.

## Ligação com a Frota e o Campo
- **Frota:** o gestor e a consulta da Frota veem no "Atenção" o **motorista com a CNH vencida** ("Motorista João — CNH: venceu em 15/10/2026"). Vale a CNH cadastrada em Pessoas. Para quem ainda não tem a CNH em Pessoas, vale a data da ficha do motorista. Ao cadastrar uma CNH nova em Pessoas, a ficha do motorista é atualizada sozinha (número e validade).
- **Campo:** o gestor e o atendimento veem no "Atenção" o **técnico com NR-10, NR-35 ou NR-33 vencida**. Ao agendar uma OS, o técnico aparece com "⚠ NR-10: venceu em ..." na lista e, ao escolhê-lo, um aviso vermelho embaixo. **O agendamento continua permitido** (decisão: avisar, nunca proibir).

## Quem vê o quê (LGPD)
- **Cadastrar e ver documentos:** dono, administradores e quem já vê o CPF em Pessoas (o **RH**, gestor do Ponto). É a mesma regra do CPF.
- **Dado de saúde (ASO) e anexos:** só dono, administradores e RH. Os gestores da Frota e do Campo **nunca** veem o documento nem o anexo: só "vencido" no quadro "Atenção" do sistema deles. Eles também não abrem a tela Vencimentos.
- **A própria pessoa** sempre vê os documentos dela (inclusive o ASO) e abre o anexo.
- **"Baixar os dados da pessoa (LGPD)"** em Pessoas agora traz os documentos (sem os arquivos). O ASO e outros dados de saúde só entram se quem baixa pode vê-los.
- **Apagar:** quando o sistema apagar os dados de uma pessoa, os documentos e os anexos vão junto (`Person` → `PersonDocumentService::purgePerson`). Ao apagar os dados de uma empresa, a pasta `tenants/<empresa>` inteira é removida, como já acontece com as fotos. A exclusão/anonimização de uma pessoa pela tela ainda espera o advogado (veja `pessoas.md`).

## Arquivos
- `app/People/Documents/`:
  - `DocumentType` (tipo da empresa) e `DocumentTemplates` (tipos prontos por país);
  - `PersonDocument` (o documento: situação, dias para vencer; apaga o anexo junto) e `DocumentStatus` (as situações);
  - `PersonDocumentService` (todas as regras: quem vê, vencimento, renovar, avisos, "Atenção", Frota/Campo, LGPD);
  - `DocumentExporter` (planilha do Excel, OpenSpout).
- Telas: `app/Filament/Pages/DocumentsPage.php` (Vencimentos), `PersonDocumentsPage.php` (documentos de uma pessoa), `DocumentTypesPage.php` (tipos), `MyDocumentsPage.php` (Meus documentos) e `app/Filament/Support/ManagesPersonDocuments.php` (formulário, colunas e botões em comum).
- Anexo: `app/Http/Controllers/PersonDocumentFileController.php` (rota `people.documents.file` em `routes/web.php`).
- Rotina: `app/Console/Commands/RunDocumentTasks.php` (`kiban:documents`, em `routes/console.php`).
- Ligações: `Home::getAttention()` (quadro "Atenção"), `PeoplePage` (coluna, botão e LGPD), `FleetNotices::expiredLicenses()` (Frota), `FieldModule::attention()` e `OrderResource::technicianWarning()` (Campo).
- Migração: `database/migrations/2026_11_01_100000_create_person_documents_tables.php` (tabelas `person_document_types` e `person_documents`, com `tenant_id` e chaves compostas).
- Textos: `lang/*/documents.php`.
- Testes: `tests/Feature/PersonDocumentsTest.php`.

## Para depois
- Avisar também a própria pessoa no sino (hoje só quem cuida dos documentos).
- Pedir a foto do documento pelo celular da própria pessoa (hoje quem cadastra é o RH).
- Importar documentos por planilha.
