# Kiban Frota

**O que é:** o sistema de frota dentro da casca. Ele é entregue em partes (F1 a F6). Este arquivo explica o que já está pronto.

## Parte 1 (F1): veículos, km, motoristas e postos

### Km sempre atualizado
- Todo registro pede o km do veículo: abastecimento, manutenção, vistoria, lavagem ou "Informar km".
- O km do veículo é sempre a **maior leitura**, e cada leitura fica no **Histórico de km**, com a origem, a data e quem informou.
- **O km nunca volta.** Um km menor que o atual é recusado, porque quase sempre é erro de digitação.
- **Salto muito grande** (mais de 20.000 de uma vez) precisa ser confirmado.
- **Corrigir km:** só o gestor, com motivo. Serve para um km digitado errado ou para a troca do painel. A correção aparece em "Alterações importantes".

### Troca de óleo e revisão
- No cadastro do veículo: próxima troca **por km e/ou por data** (vale o que chegar primeiro) e o intervalo (ex.: a cada 10.000 km ou 6 meses).
- Situação: **Em dia**, **Em breve** (dentro da antecedência das Configurações) ou **Vencida**.
- Quando a manutenção for registrada (F3), o sistema calcula a próxima sozinho a partir do intervalo.

### Proteções contra burlar o limite do plano
- O plano no Core limita o **número de veículos** (limite `vehicles`). Motoristas são ilimitados.
- **Placa e chassi travam** depois do primeiro registro. Para trocar, só o gestor, pelo botão "Alterar placa/chassi", com motivo registrado.
- **Placa única** dentro da empresa, em qualquer formato ("ABC-1D23" = "abc 1d23").
- **Veículo com histórico não pode ser apagado**, só ficar **Inativo** (com motivo). Inativo não conta no plano e não recebe registros. Para reativar, precisa ter vaga no plano.
- Tudo isso fica em **Alterações importantes**, na página do veículo.

### Quem vê o quê (papéis da frota)
| Papel | Quem é | O que pode |
|---|---|---|
| **Gestor** | Dono ou administrador da empresa (sempre), ou quem recebeu "Gestor" na tela **Pessoas** | Tudo, inclusive painel, equipe e configurações |
| **Mecânico** | Recebeu "Mecânico" na tela Pessoas | Vê os veículos e abastece qualquer um; os serviços entram na parte 3. Não vê painel nem relatórios |
| **Motorista** | Membro com o mesmo e-mail de um motorista cadastrado | Só os veículos ligados a ele (ou todos, se marcado "Pode usar qualquer veículo"); abastece e informa km. Tela própria no celular |
| **Consulta** | Qualquer outro membro | Só olhar |

- O acesso do motorista é ligado sozinho na primeira vez que ele entra com o mesmo e-mail cadastrado.
- Um veículo de outro motorista "não existe" para ele (aparece como não encontrado).
- Motorista e posto com abastecimentos não podem ser apagados, só desativados.

### Documentos
- Licenciamento, seguro, IPVA e inspeção, com número, vencimento e o arquivo (PDF ou foto).
- Situação: em dia, em breve ou vencido.

### Fotos e arquivos
- Ficam no servidor, **separados por empresa** (`storage/app/private/tenants/{empresa}/fleet/...`), e só abrem por links que expiram.
- As fotos são **reduzidas no próprio celular** antes de enviar: no máximo 1600 pixels, cerca de 300 a 500 KB. O limite é de 10 MB por arquivo.

### Configurações da frota (gestor)
- Unidades (km ou milhas, litros ou galões) e moeda. Vêm do país da empresa e travam depois do primeiro veículo.
- Antecedência dos avisos (padrão: 500 km ou 15 dias).

### Primeiros passos
1. Cadastre o primeiro veículo.
2. Cadastre um motorista (depende do veículo).
3. Cadastre os postos de combustível.

## Parte 2 (F2): abastecimento com código, página do posto e painel

### Como o motorista abastece (no celular)
1. Na página **Início**, toca em **Abastecer** no veículo, escolhe o **posto**, digita o **km do painel** e tira a **foto do painel**.
2. Toca em **Gerar código**. Sai um código curto (ex.: `3QX-RDX`), de uso único, válido por **2 horas**. O km já é registrado.
3. **No posto**, o frentista abre a **página do posto** (`endereço-da-empresa/posto`, sem login) e digita o código. Ele vê se é válido, a **placa**, o modelo, o motorista, o combustível permitido e o tamanho do tanque.
4. Depois de abastecer, o motorista toca em **Finalizar abastecimento** e informa:
   - o combustível;
   - os litros;
   - o valor;
   - "tanque cheio?";
   - a **foto da nota**.

### Média de consumo e avisos
- A média (km/l) é calculada **de tanque cheio a tanque cheio**. Abastecimentos parciais no meio entram na conta seguinte.
- Avisos para o gestor (não bloqueiam):
  - **mais que o tanque**;
  - **combustível diferente do veículo** (ex.: diesel em carro flex);
  - **consumo acima do normal** (25% pior que a média dos últimos 6 meses, pode ser problema mecânico);
  - **veículo de outro motorista**;
  - **finalizado depois do prazo**.

### Quem pode abastecer
- O **gestor** e o **mecânico** abastecem qualquer veículo ativo, mesmo sem cadastro de motorista. Ex.: o gestor saiu com um dos carros.
- O **motorista reserva** ("Pode usar qualquer veículo" no cadastro) abastece qualquer veículo.
- O **motorista comum**, se a empresa permitir (Configurações → Abastecimento, ligado por padrão), pode abastecer um veículo que não é dele **informando o motivo**. O abastecimento fica marcado para o gestor conferir.
- Consulta não abastece.

### Outras regras
- **Um código aberto por veículo.** Código não finalizado em 24 horas vira **Expirado** (rotina `kiban:fleet`, de hora em hora).
- O abastecimento em aberto pode ser **cancelado** com motivo. O km registrado continua valendo.
- **Abastecimentos nunca são alterados nem apagados.**
- **Lançar abastecimento** (gestor): para uma nota recebida depois, sem código. Pode ter data passada; o km precisa caber entre as leituras de antes e de depois, e não muda o km atual.
- A página do posto aceita **10 tentativas por minuto**, então ninguém consegue "chutar" códigos. Os códigos valem só no endereço da própria empresa.

### Painel da frota (gestor e consulta)
- Filtros: **1, 3, 6, 9 ou 12 meses** e **todos os veículos ou um**.
- Números:
  - gasto com combustível;
  - litros;
  - km rodados (entre tanques cheios);
  - **média de consumo**;
  - **custo por km**.
- Gráficos: média de consumo por mês e gasto por mês.
- Lista de **consumo fora do normal**. A manutenção entra no painel desde a parte 3.

## Parte 3 (F3): manutenção, lavagens, agenda, despesas e oficinas

### O caminho de um pedido
1. O **motorista** toca em **Solicitar manutenção ou lavagem** no veículo (página Início), escreve o que precisa e, se quiser, tira uma **foto** (não é obrigatória).
2. A solicitação aparece para o **gestor** e o **mecânico**: no menu **Solicitações** (com um número de quantas estão esperando) e na página Início do mecânico.
3. Eles **agendam** (data, hora, oficina ou local, responsável) ou **recusam** com uma resposta. O motorista vê a situação em "Minhas solicitações".
4. Na **Agenda** (lista ou **calendário** do mês), o agendamento é **concluído** quando o serviço termina. Isso registra a manutenção ou a lavagem.
5. Se o agendamento for **cancelado**, a solicitação volta para "esperando", para ninguém esquecer.

O gestor e o mecânico também podem agendar ou registrar direto, sem pedido do motorista.

### Manutenção
- Registra:
  - tipo (preventiva ou corretiva);
  - serviços feitos (troca de óleo, revisão, pneus, freios...);
  - data;
  - **km**;
  - oficina ou mecânico;
  - **peças** e **mão de obra** (o total é a soma);
  - nota ou orçamento.
- **Troca de óleo e revisão calculam sozinhas a próxima**, usando os intervalos do veículo (ex.: a cada 10.000 km ou 6 meses).
- O km entra no histórico do veículo. Uma manutenção de dias atrás precisa caber entre as leituras de antes e de depois.
- Se o veículo estava "Em manutenção", ele volta a "Ativo".

### Lavagem
- Simples ou completa, valor e local. O km é opcional.

### Despesas (só o gestor)
- IPVA, licenciamento, seguro, multa, pedágio, estacionamento etc.
- Podem ser de um veículo ou da **frota toda**.

### Oficinas
- Lista das oficinas de fora (só cadastro, elas não entram no sistema). Uma oficina com manutenção registrada não pode ser apagada.

### Quem vê o quê
- **Mecânico**:
  - Solicitações, Agenda, Manutenções, Lavagens e Oficinas;
  - **não** vê o painel nem as Despesas.
- **Motorista**: pede e acompanha os próprios pedidos (só dos veículos dele).
- **Gestor**: tudo. **Consulta**: só olha.
- Nada disso é apagado; faz parte do histórico do veículo.
- A página do veículo mostra as abas de manutenções, lavagens, despesas e agendamentos.

### Painel
- O painel agora soma **manutenção, lavagens e despesas**, além do combustível:
  - "Gasto total da frota";
  - gráfico de gastos por mês, em cores;
  - **custo por km** com tudo.

## Arquivos (parte 3)
- Tabelas: `fleet_workshops`, `fleet_service_requests`, `fleet_appointments`, `fleet_maintenances`, `fleet_washes` e `fleet_expenses`, em `database/migrations/2026_10_12_160000_create_fleet_maintenance_tables.php`.
- `app/Modules/Fleet/Services/MaintenanceService.php`: todas as regras (pedir, agendar, recusar, cancelar, registrar manutenção/lavagem/despesa, calcular a próxima troca).
- `app/Modules/Fleet/Models/`: `Workshop`, `ServiceRequest`, `Appointment`, `Maintenance`, `Wash`, `Expense`.
- `app/Modules/Fleet/Enums/`:
  - `MaintenanceType`, `MaintenanceItem`;
  - `WashKind`, `ExpenseCategory`, `ServiceKind`;
  - `RequestStatus`, `AppointmentStatus`.
- `app/Modules/Fleet/Policies/`: `WorkPolicy` (base: gestor e mecânico), `MaintenancePolicy`, `WashPolicy`, `AppointmentPolicy`, `ServiceRequestPolicy`, `ExpensePolicy`, `WorkshopPolicy`.
- Telas:
  - `app/Modules/Fleet/Filament/`: `Requests/`, `Appointments/` (lista + calendário), `Maintenances/`, `Washes/`, `Expenses/`, `Workshops/`;
  - formulários comuns: `WorkForms.php`;
  - mensagens de erro: `RunsFleetRules.php`;
  - abas do veículo: `Vehicles/RelationManagers/`.
- Visuais:
  - `resources/views/fleet/appointments/calendar.blade.php` (calendário);
  - `resources/views/fleet/home/mechanic.blade.php` (Início do mecânico).
- Testes: `tests/Feature/FleetMaintenanceTest.php`.

## Parte 4 (F4): vistorias

### Como o motorista faz (no celular, 1 ou 2 minutos)
1. Na página **Início**, toca em **Fazer vistoria** no veículo. Quando falta a vistoria do período, o botão fica azul e diz "(pendente)".
2. Digita o **km** do painel.
3. Em cada item da lista, toca em **OK** ou em **Problema**. Um problema pede **o que está errado**; a foto é opcional (nem tudo dá para fotografar, como um barulho).
4. Tira as **5 fotos**: frente, traseira, os dois lados e o painel ligado.
5. Toca em **Concluir vistoria**.

### O que acontece sozinho
- O km entra no histórico do veículo.
- **Cada problema vira uma solicitação de manutenção** (com a foto), no mesmo caminho da parte 3: o gestor e o mecânico veem, agendam e concluem.
- **Item grave com problema** (freios, pneus, luzes e luzes de alerta, já marcados): o veículo vai para **"Em manutenção"** e fica registrado no histórico ("Parado pela vistoria"). Quando a manutenção é registrada, ele volta a "Ativo". O gestor também pode liberar mudando a situação.
- A vistoria nunca é alterada nem apagada. As respostas guardam o nome do item como era naquele dia.

### Com que frequência (Configurações → Vistoria)
- **Todo dia** (padrão), toda semana, todo mês ou só quando quiser.
- **Nunca bloqueia nada.** Sem a vistoria:
  - o motorista vê um lembrete na tela de abastecer (com o botão para fazer a vistoria);
  - o abastecimento fica marcado "Sem a vistoria do período", para o gestor conferir.
- Só os veículos **ativos** precisam de vistoria (em manutenção ou parados, não).

### Itens da vistoria (só o gestor)
- Vêm 9 itens prontos: pneus, luzes e setas, freios, óleo e água, luzes de alerta no painel, lataria e vidros, limpeza, documento, e extintor/triângulo/macaco/estepe.
- O gestor pode:
  - desligar um item ("Usar na vistoria");
  - marcar ou desmarcar como **grave**;
  - mudar o nome;
  - mudar a ordem (arrastando);
  - criar itens próprios (ex.: tacógrafo).
- Os itens prontos são desligados, não apagados. Os próprios podem ser apagados.

### Quem vê o quê
- **Motorista:** faz e vê as vistorias dos veículos dele.
- **Gestor:** faz e vê todas, e cuida dos itens e da frequência.
- **Mecânico:** pode fazer, mas recebe os problemas como solicitações (a vistoria não aparece no menu dele).
- **Consulta:** só olha.

### Painel e veículo
- O painel e a página Início do gestor mostram o quadro **Vistorias**:
  - "Hoje: X de Y veículos vistoriados" e quais faltam;
  - os **problemas encontrados ainda em aberto**.
- A página do veículo ganhou a aba **Vistorias**.

## Arquivos (parte 4)
- Tabelas `fleet_checklist_items` e `fleet_inspections`, a ligação da solicitação com a vistoria e a frequência nas configurações: `database/migrations/2026_10_21_100000_create_fleet_inspection_tables.php`.
- `app/Modules/Fleet/Services/InspectionService.php`: todas as regras (itens prontos, registrar, problemas → solicitações, item grave → em manutenção, frequência e quem está pendente).
- `app/Modules/Fleet/Models/`: `ChecklistItem`, `Inspection`.
- `app/Modules/Fleet/Enums/InspectionFrequency.php`. Também ganharam opções novas: `VehicleEventType` ("parado pela vistoria") e `FuelUpFlag` ("sem a vistoria").
- `app/Modules/Fleet/Policies/`: `InspectionPolicy`, `ChecklistItemPolicy`.
- Telas:
  - `app/Modules/Fleet/Filament/Inspections/`: lista, "Fazer vistoria" (`Pages/DoInspection.php`) e detalhes com fotos;
  - `app/Modules/Fleet/Filament/Checklist/`: itens da vistoria;
  - quadro do painel: `Dashboard/InspectionStatusWidget.php` + `resources/views/fleet/dashboard/inspection-status.blade.php`;
  - aba do veículo: `Vehicles/RelationManagers/InspectionsRelationManager.php`.
- Testes: `tests/Feature/FleetInspectionTest.php`.

## Parte 5 (F5): avisos no sino e quadro "Atenção"

Os avisos ficam só dentro do sistema (sem e-mail). O funcionamento geral está em `avisos.md`.

### Quem recebe o quê
| Aviso | Quem recebe | Quando |
|---|---|---|
| Vistoria com **item grave** ("Veículo parado") | Gestor e mecânico | Na hora |
| Vistoria com outros problemas | Gestor e mecânico | Na hora |
| Pedido novo de manutenção ou lavagem | Gestor e mecânico | Na hora |
| Pedido agendado (com a data), recusado (com a resposta), resolvido ou de volta à fila | Quem pediu | Na hora |
| Abastecimento com alerta (mais que o tanque, combustível errado, consumo alto, outro veículo, atrasado) | Gestor | Ao finalizar |
| Agendamento para amanhã | Quem vai fazer (sem responsável: os mecânicos) | Rotina de hora em hora |
| Troca de óleo ou revisão chegando / vencida | Gestor e os motoristas do veículo | Rotina, uma vez cada |
| Documento vencendo / vencido | Gestor | Rotina, uma vez cada |
| Veículos sem a vistoria de hoje (lista das placas) | Gestor | Depois das 11h, uma vez por dia (só com vistoria diária) |

"Sem a vistoria" sozinho não gera aviso de abastecimento: o aviso das 11h já cobre isso.

### Quadro "Atenção" (página Início)
- **Gestor, mecânico e consulta:**
  - veículo parado pela vistoria, com os itens graves;
  - troca de óleo ou revisão vencida.
- O gestor e a consulta também veem os **documentos vencidos**.
- **Motorista:** "Não use o veículo X: ele está em manutenção".
- Tudo some sozinho quando é resolvido.

## Arquivos (parte 5)
- `app/Modules/Fleet/Services/FleetNotices.php`: quem recebe cada aviso, a rotina de hora em hora (`scan()`) e o quadro "Atenção" (`attention()`).
- Os avisos são disparados por `InspectionService`, `MaintenanceService` e `FuelService`; a rotina roda dentro do `kiban:fleet`.
- Testes: `tests/Feature/FleetNoticesTest.php`.

## Parte 6 (F6): ficha em PDF, planilhas e limpeza das fotos antigas

### Ficha do veículo (PDF)
- Na página do veículo, botão **Ficha (PDF)**. Só o gestor e a consulta veem, porque a ficha mostra os gastos.
- A ficha traz:
  - identificação (situação, km, tipo, combustível, ano, cor, chassi, RENAVAM, tanque e motoristas);
  - troca de óleo e revisão (próxima e situação);
  - documentos com vencimento;
  - **gastos dos últimos 12 meses** (combustível, manutenção, lavagem, despesas, total, média e custo por km);
  - manutenções do período;
  - as últimas 10 vistorias.
- Sai no idioma de quem baixa, com o logo da Kiban, o nome da empresa e a data no rodapé.

### Relatórios (planilhas do Excel)
- Menu **Relatórios** (gestor e consulta). Escolha a planilha, o período e, se quiser, um veículo.
- As planilhas:
  - **Abastecimentos:** litros, preço, valor, km, rodado, média, tanque cheio e avisos;
  - **Gastos:** tudo o que a frota gastou, um por linha (combustível, manutenção, lavagem, despesa), mais uma aba **"Resumo por veículo"** com os mesmos números do painel, inclusive o custo por km;
  - **Manutenções:** serviços, quem fez, km, peças, mão de obra e total;
  - **Vistorias:** quem fez, km, resultado e os problemas.
- Os valores vão como números, então dá para somar e fazer gráficos no Excel. A última linha traz os totais.

### Limpeza das fotos antigas
- **Fotos** (vistorias, painel do abastecimento e pedidos): apagadas depois de **6 meses**.
- **Notas e comprovantes** (abastecimento, manutenção e despesas): apagados depois de **5 anos**.
- **Ficam sempre:** a foto do veículo e os documentos.
- **Os dados nunca são apagados.** Valores, datas, km, quem fez e os problemas continuam. Só a foto some, e a tela avisa: "As fotos desta vistoria foram apagadas depois de 6 meses".
- **Aviso antes:** uma vez por mês, o gestor recebe no sino quantos arquivos serão apagados nos próximos 30 dias, para baixar o que precisar.
- A regra aparece em Configurações → "Fotos e arquivos antigos". A limpeza roda dentro da rotina de hora em hora (`kiban:fleet`).

## Arquivos (parte 6)
- `app/Modules/Fleet/Services/VehicleRecord.php` + `resources/views/fleet/pdf/vehicle.blade.php`: a ficha em PDF (biblioteca DomPDF, com a fonte DejaVu, que tem acentos; só as letras usadas vão no arquivo).
- `app/Modules/Fleet/Services/FleetExporter.php`: as 4 planilhas (biblioteca OpenSpout, a mesma do Core).
- `app/Modules/Fleet/Filament/FleetReportsPage.php`: a tela Relatórios.
- `app/Modules/Fleet/Services/FleetCleanup.php`: a limpeza e a contagem para o aviso.
- `FleetReports::totalsBetween()`: os números do painel para qualquer período (usado no resumo da planilha e na ficha).
- Bibliotecas novas no `composer.json`: `barryvdh/laravel-dompdf` e `openspout/openspout`.
- Testes: `tests/Feature/FleetReportsTest.php`.

## Arquivos (parte 2)
- Tabelas: `fleet_members` (papéis) e `fleet_fuel_ups` (abastecimentos), em `database/migrations/2026_10_12_120000_create_fleet_fuel_tables.php`. Mais `..._130000_add_fuel_access_options.php` (motorista reserva, permitir outro veículo, motivo).
- `app/Modules/Fleet/Services/`:
  - `FuelService` (código, finalizar, lançar, cancelar, média, avisos);
  - `FleetReports` (números do painel);
  - `FleetAccess` (os 4 papéis).
- `app/Modules/Fleet/Models/FuelUp.php`, `FleetMember.php`.
- `app/Modules/Fleet/Enums/`: `FleetRole`, `FuelUpStatus`, `FuelUpFlag`.
- Telas:
  - `app/Modules/Fleet/Filament/FuelUps/` (lista, gerar código, abastecimento, lançar);
  - `Dashboard/` (painel e gráficos);
  - `resources/views/fleet/home/driver.blade.php` (Início do motorista).
- Página do posto: `app/Http/Controllers/Fleet/StationCheckController.php` + `resources/views/fleet/station-check.blade.php`.
- Rotina de hora em hora: `app/Console/Commands/RunFleetTasks.php` (`kiban:fleet`).
- Testes: `tests/Feature/FleetFuelTest.php`.

## Arquivos (parte 1)
- `app/Modules/Fleet/FleetModule.php`: o módulo (chave `frota`, limite `vehicles`, passos, telas e regras).
- `app/Modules/Fleet/Models/`:
  - `Vehicle`, `Driver`, `DriverVehicle`, `FuelStation`;
  - `OdometerReading`, `VehicleDocument`, `VehicleEvent`;
  - `FleetSetting`.
- `app/Modules/Fleet/Enums/`: tipos, combustíveis, situações, documentos, origens do km.
- `app/Modules/Fleet/Services/`:
  - `OdometerService` (km);
  - `VehicleService` (cadastro, travas e situação);
  - `FleetAccess` (gestor, motorista ou visitante);
  - `FleetFormat` (números com unidade e moeda);
  - `FleetException`.
- `app/Modules/Fleet/Rules/UniquePlate.php`: placa única em qualquer formato.
- `app/Modules/Fleet/Policies/`: quem pode o quê em cada tela.
- `app/Modules/Fleet/Filament/`:
  - telas de Veículos (lista, cadastro, edição, página do veículo com Histórico de km, Documentos e Alterações), Motoristas e Postos;
  - `FleetSettingsPage` e `FleetFields`.
- `database/migrations/2026_10_12_100000_create_fleet_tables.php`.
- Textos: `lang/*/fleet.php`.
- Testes: `tests/Feature/FleetVehiclesTest.php`.
