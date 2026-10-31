# EPI e ferramentas (entrega assinada no celular)

**O que é:** um recurso da tela **Pessoas**, comum a todos os sistemas (como os Documentos). Não é um produto à venda por enquanto. Registra a **entrega de EPI** (capacete, luvas, cinto...) e de **ferramentas** (escada, multímetro...) para cada pessoa, com a **assinatura com o dedo no celular**. É a ficha de EPI da NR-6 (a Portaria MTE 57/2025 aceita o sistema eletrônico). Junto com "Documentos e vencimentos", forma o pacote "Pessoas e Segurança".

## Itens (o catálogo da empresa)
- Tela **Empresa → EPI e ferramentas → Itens**.
- Na primeira vez entram itens prontos, conforme o **país da empresa**:
  - **Brasil:** capacete com jugular, óculos, luva isolante, luva de vaqueta, cinto paraquedista com talabarte (NR-35), botina, protetor auricular, uniforme, alicate, escada, multímetro, furadeira;
  - **outros países:** uma lista genérica (nada da lei brasileira; o campo do CA vira "Certificação", opcional).
- Cada item tem:
  - nome e **tipo**: EPI, ou ferramenta/equipamento (o uniforme ficou como "equipamento", porque não tem CA);
  - **número do CA e validade** (só EPI);
  - **vida útil** depois da entrega, em dias ou meses (ex.: luva 30 dias, capacete 24 meses). Vazio = sem troca automática;
  - tamanho, código interno e custo unitário (opcionais);
  - **estoque** (vazio = não controla) e **estoque mínimo**;
  - "volta para o estoque na devolução" (ferramentas sim; EPI usado, normalmente não).
- **Cada tamanho pode ser um item** (botina 40 e botina 42), cada um com o seu estoque. O botão "Duplicar (outro tamanho)" ajuda.
- Botões: **Editar**, **Entrada no estoque** (soma a compra) e **Duplicar**. Um item em uso é **desligado**, nunca apagado.

### Estoque (decisão)
- A entrega **diminui** o estoque; a devolução de um item que serve de novo **aumenta**; descarte e perda não devolvem.
- **O estoque pode ficar negativo.** A entrega nunca é bloqueada, porque o item já está na mão da pessoa e o registro precisa mostrar a verdade. Aparece um aviso: "O estoque de Escada ficou em -1. Registre a Entrada no estoque".

## Entrega para uma pessoa
- Em **Pessoas → ⋮ → EPI e ferramentas** (ou no botão **Nova entrega** da tela EPI e ferramentas).
- Escolha os itens e as quantidades, a data, o motivo (primeira entrega, troca por desgaste, perda, dano, fim da vida útil/validade, outro) e uma observação.
- **Assinatura:**
  - **"A pessoa está aqui: assinar agora"**: abre a janela com os itens, o **texto de ciência** e o quadro para assinar com o dedo (no celular ou no computador, com o mouse);
  - **"Assinar depois, no celular da pessoa"**: ela recebe um aviso no sino e a entrega aparece em **Minhas entregas** (menu do usuário). Também dá para mandar o link pelo **WhatsApp**. Só para quem tem login; sem login, assina na hora no aparelho de quem entrega (ou depois, no botão "Assinar" da linha).
- Fica guardado de cada assinatura: a imagem, o nome, a data e a hora, o **IP**, o **aparelho**, se foi no celular da própria pessoa, o **texto exato** mostrado e um **código de conferência** (SHA-256 de tudo). Em "Ver a entrega" aparece "✓ sem alteração desde a assinatura".
- **Entrega assinada nunca muda nem é apagada.** Para corrigir: devolução ou nova entrega. Uma entrega **ainda não assinada** e sem devolução pode ser **cancelada** (os itens voltam para o estoque).
- **Texto de ciência:** o padrão da NR-6 (receber gratuitamente, uso, guarda, conservação, devolver, recusa injustificada é falta — art. 158 da CLT), nos 3 idiomas. A empresa muda no botão **Texto de ciência**. Fora do Brasil, um texto genérico sem a NR-6.

## Devolução e troca
- **Devolução:** quantidade, data, motivo e o que aconteceu (voltou para o estoque, descartado, perdido). O sistema já sugere: desgaste/dano → descarte; perda → perdido; fim do uso de ferramenta → estoque.
- **Trocar:** registra a saída do antigo + a entrega do novo (mesmo item ou outro tamanho) e já abre a assinatura.

## Troca pela vida útil
- Cada item entregue ganha a data de troca (entrega + vida útil).
- Situação: **Em dia**, **Trocar em N dias** (7 dias antes), **Trocar hoje**, **Troca atrasada N dias**, **Sem troca automática**, **Devolvido**. O "hoje" segue o fuso da empresa.

## Telas
- **Empresa → EPI e ferramentas:** números no topo (para trocar, trocar nos próximos 7 dias, esperando assinatura, CA vencido ou vencendo, estoque baixo; tocar filtra a lista ou abre os itens), a lista do que está com quem, filtros (troca, assinatura, tipo, item, equipe) e **Baixar planilha** (Excel, como a lista está na tela).
- **Pessoas → EPI e ferramentas da pessoa:** o que está com ela agora (ou "Tudo", com o histórico), **Nova entrega**, **Assinar**, **Devolução**, **Trocar**, **Ver a entrega** e **Ficha em PDF**.
- **Minhas entregas** (menu do usuário): a pessoa vê o que está com ela, assina as pendentes e baixa a própria ficha.
- No celular, todas as listas viram **cartões**. A lista vazia explica e mostra o botão "Nova entrega".

## Ficha de EPI em PDF
- No formato usual da NR-6 (página deitada): dados da empresa (identidade da empresa), da pessoa (nome, documento, equipe), tabela com data, item, CA e validade, quantidade, motivo, devoluções/baixas e a **assinatura** (imagem, nome, data/hora e código), o termo de ciência e a nota sobre a assinatura eletrônica.

## Avisos (sem e-mail)
Rotina **diária** `kiban:epi` (agendador, 10:10 UTC = 07:10 em Brasília). No sino de quem cuida do EPI, **uma vez por marco**:
- **troca pela vida útil**: 7 dias antes e no dia (um aviso por pessoa, juntando os itens);
- **CA vencendo**: 30 dias antes, 7 dias antes e no dia;
- **entrega esperando assinatura há mais de 2 dias** (também para a própria pessoa);
- **estoque no mínimo ou abaixo**: uma vez; depois da entrada no estoque, avisa de novo numa próxima queda (ferramentas avisam também os gestores da Frota/Campo).
- **Quadro "Atenção" do Início** (todos os sistemas): "EPI e ferramentas: 1 troca vencida · 1 CA vencido · 1 entrega sem assinatura · 1 item com estoque baixo". A pessoa vê "Você tem 1 entrega para assinar".

## Ligação com o Campo
- No **tipo de serviço** do Campo, o campo **"EPI necessário"** (ex.: Instalação de split → cinto paraquedista).
- O técnico que não tem esse EPI (nenhuma entrega com ele agora) aparece com aviso vermelho **ao agendar** ("Atenção: sem EPI registrado — ... O agendamento continua permitido") e **ao abrir a OS no celular**. **Só aviso, nunca bloqueia.**
- Outro tamanho do mesmo item (mesmo nome) conta.
- Está no catálogo de conexões (`epi_field`, Ponto + Campo, como os treinamentos NR), para a janela "seus sistemas agora trabalham juntos". Ponto: nada (opcional, decidido não fazer agora).

## Quem vê o quê (decisões)
- **Dono, administradores e RH** (a mesma regra dos Documentos): tudo.
- **Gestores da Frota e do Campo: só FERRAMENTAS** (entregar, devolver, ver, estoque). Eles emprestam escada, furadeira e multímetro no dia a dia. O EPI fica com o RH, porque a ficha da NR-6 é um documento trabalhista. Eles não veem a ficha em PDF nem o texto de ciência.
- **A pessoa** vê só as dela e assina as pendentes.
- Separação total entre empresas.

## LGPD
- "Baixar dados (LGPD)" em Pessoas traz as entregas, devoluções e o registro da assinatura (data, IP, aparelho, texto), **sem a imagem**.
- Quando o sistema apagar os dados de uma pessoa, as entregas, devoluções e as **imagens das assinaturas** vão junto (é o único jeito de uma entrega assinada sumir). As imagens ficam no disco privado, em `tenants/<empresa>/people/epi/<pessoa>`.

## Para ligar/desligar por licença no futuro (anotação para o programador)
Tudo passa por um interruptor só: `EpiService::enabled()`. Hoje ele diz "sim" para toda empresa com licença ativa. Para vender como pacote, basta conferir ali a licença (ex.: `$tenant->license()->hasFeature('epi')`): telas, menu, avisos, "Atenção", a ligação com o Campo e a rotina leem esse método.

## Arquivos
- `app/People/Epi/`: `EpiItem`, `EpiDelivery`, `EpiDeliveryLine`, `EpiReturn`, `EpiSetting` (modelos); `EpiKind`, `DeliveryReason`, `ReturnReason`, `ReturnOutcome`, `LineStatus` (listas); `EpiTemplates` (itens prontos por país); `EpiService` (todas as regras); `EpiRecord` (ficha em PDF); `EpiExporter` (planilha); `EpiException`.
- Telas: `app/Filament/Pages/EpiPage.php`, `EpiItemsPage.php`, `PersonEpiPage.php`, `MyEpiPage.php` e `app/Filament/Support/ManagesEpi.php` (formulário, assinatura, devolução, troca, colunas).
- Vistas: `resources/views/filament/forms/signature-pad.blade.php` (quadro da assinatura), `resources/views/filament/epi/` (resumo, assinatura, detalhes) e `resources/views/epi/record-pdf.blade.php` (ficha).
- Campo: `app/Connections/Bridges/FieldEpi.php`, campo "EPI necessário" em `ServiceTypeResource`, aviso em `OrderResource::scheduleFields()` e em `resources/views/field/execute.blade.php`.
- Rotina: `app/Console/Commands/RunEpiTasks.php` (`kiban:epi`, em `routes/console.php`).
- Migração: `database/migrations/2026_11_03_100000_create_epi_tables.php`.
- Textos: `lang/*/epi.php`. Testes: `tests/Feature/EpiTest.php`.

## Para depois
- Leitura do CA direto no site do Ministério do Trabalho (hoje é digitado).
- Kit pronto por função (ex.: "kit técnico" com 7 itens de uma vez).
- Custo de EPI por pessoa/equipe no painel.
- Exigir EPI no Ponto (hoje não liga nada).
