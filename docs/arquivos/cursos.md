# Cursos e treinamentos (NR-10, NR-35...)

**O que é:** uma parte de **Documentos e vencimentos** (veja `documentos.md`), comum a todos os sistemas e incluída em todos os planos. Não é um sistema novo: os cursos continuam sendo documentos das pessoas, com o mesmo aviso, histórico e anexo. A novidade é ver a equipe inteira de uma vez, dizer quem **precisa** de cada curso e lançar uma **turma** inteira de uma vez.

## O que mudou nos tipos de documento
- Cada tipo agora diz se **é um curso/treinamento** (tela **Tipos de documento**, botão "É um curso/treinamento"). Os prontos já vêm marcados: NR-10, NR-35, NR-33, NR-11, NR-12, NR-06, "Outro treinamento ou curso" e, fora do Brasil, "Treinamento de segurança". CNH e ASO não são cursos.
- **Cursos avisam 60 dias antes** (marcar e fazer uma turma leva tempo). Empresas novas já começam assim. Nas empresas que já existiam, o aviso dos cursos passou de 30 para 60 dias **só** onde ainda estava nos 30 dias do padrão; quem tinha escolhido outro número ficou com o dele. A empresa pode mudar quando quiser. CNH e ASO continuam com 30 dias.

## Quem precisa de cada curso
- Tela **Cursos e treinamentos → Quem precisa de cada curso**. Cada linha diz: o curso e **para quem**:
  - **uma função num sistema** (ex.: "Campo: Técnico", "Frota: Motorista"; dono e administradores contam como gestores, então não entram em "Técnico");
  - **uma equipe** (ex.: "Equipe de campo");
  - **todas as pessoas**.
- Quem está nesse grupo (só pessoas ativas) e **não tem** o curso cadastrado aparece como **"Falta fazer"**. Quem tem o curso vencido aparece como **vencido** (não como "falta fazer").
- "Não é mais obrigatório" apaga só a regra; os cursos que as pessoas fizeram continuam.

## A tela Cursos e treinamentos (Empresa → Cursos e treinamentos)
- Quem vê: os mesmos de **Vencimentos** (dono, administradores e RH).
- **Quem fez cada curso:** uma tabela com as pessoas nas linhas e os cursos nas colunas (só os cursos obrigatórios ou que alguém tem). Cada quadrinho:
  - **verde**: em dia (mostra "até 10/03/2028" ou "sem vencimento");
  - **amarelo**: vencendo (dentro do "avisar antes");
  - **vermelho**: vencido;
  - **cinza**: falta fazer (só quando o curso é obrigatório para a pessoa);
  - **vazio**: a pessoa não precisa e não tem.
  Filtro por **equipe**. Em cada pessoa: **Comprovante de cursos** (PDF) e **Documentos**. No celular, a tabela vira **cartões** (um por pessoa).
- **Próximas renovações (90 dias):** para cada curso, quem vence nos próximos 90 dias, quem já venceu e quem falta fazer, com o botão **"Lançar turma (N pessoas)"** já preenchido com o curso e essas pessoas. Assim a empresa marca **uma turma só** para todo mundo.
- **Baixar planilha:** a tabela no Excel, com as mesmas cores (respeita o filtro de equipe).

## Lançar turma
- Um formulário só: curso, **data do curso**, vencimento (preenchido sozinho pela validade do tipo; pode mudar), **instituição ou instrutor**, **carga horária**, **tipo de curso** (curso inicial ou reciclagem), **certificado** (foto ou PDF, opcional), observação e **quem fez** (várias pessoas).
- Cada pessoa ganha **o seu próprio documento**, pelo mesmo caminho de sempre (`PersonDocumentService`). Quem já tinha o curso fica com o novo, e o anterior vai para o **histórico** (igual ao "Renovar").
- O certificado é **copiado para cada pessoa**: apagar o documento de uma não estraga o das outras. Fica no disco privado, como os outros anexos.
- Quando a turma vem das "Próximas renovações" e todos já tinham o curso, o tipo já vem como **Reciclagem**.
- Os novos campos (instituição, horas, inicial/reciclagem) aparecem embaixo do nome do documento na lista da pessoa, no histórico, em **Meus documentos** e em **Adicionar documento / Corrigir / Renovar** (só para tipos que são cursos).

## Comprovante de cursos (PDF)
- Um PDF com a **identidade da empresa** (logo, cor e dados de **Identidade da empresa**), a pessoa e os **cursos válidos** (nome, data, validade, instituição e horas). Cursos vencidos e dados de saúde **não** entram.
- Serve para o gestor mandar a um cliente que pede comprovação antes de um serviço.
- Botões: na página de documentos da pessoa e em cada linha da tabela de cursos.
- Quem baixa: dono, administradores e RH (de qualquer pessoa) e a **própria pessoa** (só o dela). Endereço: `/cursos/<pessoa>/comprovante`, que confere quem pode.
- **LGPD (decisão):** o comprovante **nunca** vai para link público nem para página do cliente. Fica interno; quem manda o arquivo é o gestor.

## Avisos e ligação com os sistemas
- **Sino:** a rotina diária `kiban:documents` agora também avisa quem cuida dos documentos quando alguém está com um curso obrigatório **para fazer**: um aviso por curso, com os nomes ("NR-35 (trabalho em altura): 2 pessoas sem o curso"), **uma vez só por pessoa e curso** (tabela `notice_log`). Os avisos de vencimento continuam como antes (agora 60 dias antes para os cursos).
- **Quadro "Atenção" do Início:** "Cursos obrigatórios: N pessoas com curso para fazer", com link para a tela. Só para dono, administradores e RH.
- **Campo:** o aviso do técnico que já existia ("NR-10: venceu em ...") agora também diz **"NR-35: falta fazer"** quando o curso é obrigatório para ele e é um curso do Campo (tipo ligado ao Campo). Aparece no "Atenção" do gestor/atendimento e ao escolher o técnico numa OS. **O agendamento continua permitido.**

## Arquivos
- `app/People/Documents/TrainingService.php`: todas as regras dos cursos (quem precisa, falta fazer, tabela, próximas renovações, lançar turma, aviso no sino, "Atenção", textos do Campo, quem baixa o comprovante).
- `app/People/Documents/DocumentRequirement.php`: "quem precisa de cada curso" (tabela `person_document_requirements`).
- `app/People/Documents/TrainingKind.php`: curso inicial ou reciclagem.
- `app/People/Documents/TrainingRecord.php` + `resources/views/documents/trainings-pdf.blade.php`: o comprovante em PDF (DomPDF).
- `app/People/Documents/TrainingMatrixExporter.php`: a planilha do Excel (OpenSpout).
- `app/Http/Controllers/TrainingRecordController.php`: a rota `people.trainings.proof` (em `routes/web.php`).
- Telas: `app/Filament/Pages/TrainingsPage.php` + `resources/views/filament/pages/trainings.blade.php` (Cursos e treinamentos) e `TrainingRequirementsPage.php` (Quem precisa de cada curso).
- Migração: `database/migrations/2026_11_04_100000_add_trainings_to_person_documents.php` (marca de curso nos tipos, instituição/horas/tipo nos documentos, a tabela de obrigatórios com `tenant_id` e chaves compostas, e o ajuste dos 60 dias).
- Textos: `lang/*/documents.php` (parte `trainings`).
- Testes: `tests/Feature/TrainingsTest.php`.

## Para depois
- Avisar a própria pessoa no sino quando o curso dela estiver vencendo.
- Agenda de turmas futuras (data marcada, vagas, lista de presença).
