# Kiban Ponto

O controle de ponto: funcionários, locais de trabalho e, nas próximas partes, horários, batidas pelo celular ou pelo tablet, banco de horas e o espelho do mês. No menu, o grupo se chama **Ponto**; o código do produto no Core é `ponto`.

**Regras por país:**
- **"Brasil (CLT)":** completo e preparado para as regras oficiais (Portaria 671): comprovante de cada batida, arquivos AFD e AEJ, CPF obrigatório.
- **"Personalizado":** para outros países. A empresa informa as próprias regras e é responsável por seguir a lei do país dela.

O registro do programa no INPI e o atestado técnico são tarefas da empresa Kiban, para quando houver CNPJ.

## Parte 1 (P1): funcionários, locais de trabalho e equipes

### Configurações do ponto (gestor)
- **País da empresa:** vem do cadastro no Core e pode ser mudado.
- **Regras:** "Brasil (CLT)" ou "Personalizado". Ao trocar o país, a regra sugerida muda sozinha.
- Salvar a primeira vez conclui o primeiro passo ("Confirme o país e as regras").
- Os detalhes das regras (jornada, horas extras, adicional noturno, tolerância, banco de horas) entram nas partes 2 e 4.

### Locais de trabalho (gestor)
- **Dados:** nome, endereço, cidade, estado e **fuso horário**. O horário das batidas segue o fuso do local, o que importa para clientes de outros países.
- **Ponto no mapa (opcional):**
  - vá até o local com o celular e toque em **"Usar minha localização"**, sem digitar números;
  - o link **"Ver no mapa"** abre o OpenStreetMap, que é gratuito.
- **Raio permitido (opcional):** 100 m a 2 km. Fora do raio, a batida **não é bloqueada**: fica marcada para o gestor conferir. A regra das batidas entra na parte 3.
- Um local com funcionários é **desligado**, não apagado.

### Funcionários
- São cadastrados em **Pessoas** (o cadastro central), ligando **"Bate ponto"**. A tela **Ponto → Funcionários** tem o botão **"Adicionar funcionário"**, que abre esse formulário.
- **Dados do Ponto:**
  - admissão (obrigatória), cargo e matrícula;
  - PIS (só no Brasil, opcional);
  - local de trabalho;
  - **"Trabalha na rua (externo)"**.
- **Funcionário externo:** técnico, instalador ou vendedor externo. Bate o ponto onde estiver: não tem local nem raio, e grava só a localização. Pensado também para o Kiban Campo.
- **No Brasil, o CPF é obrigatório** para quem bate ponto, porque vai nos arquivos oficiais. Em outros países, usa-se o documento genérico da pessoa.
- **Login é opcional:** quem só bate ponto no tablet não precisa. Quem tem login e bate ponto já é "Funcionário" no Ponto sozinho, e vai ver o próprio espelho a partir da parte 3.
- **Limite do plano:** número de **funcionários ativos**. Desligar alguém libera a vaga. A ficha e o histórico ficam, com a data de saída.
- A lista mostra o nome, o cargo, a equipe, o local (ou "Externo"), a admissão e se a pessoa tem login. Os filtros são situação, equipe e local. O botão **Editar** muda só os dados do Ponto.

### Quem vê o quê
| Papel | O que faz |
|---|---|
| **Gestor (RH)** | Tudo: configurações, locais, funcionários. Vê o CPF e os documentos na tela Pessoas |
| **Consulta** | Vê a lista de funcionários e os locais, sem mudar nada |
| **Funcionário** | Bate o ponto e vê o próprio espelho (a partir da parte 3) |

Dono e administradores são sempre gestores. O perfil sugerido **"RH"** (Ponto como gestor) aparece quando a empresa contrata o Ponto.

### Primeiros passos
1. Confirme o país e as regras.
2. Cadastre o local de trabalho.
3. Cadastre os horários de trabalho (parte 2).
4. Cadastre os funcionários.

## Arquivos (parte 1)
- `app/Modules/TimeClock/TimeClockModule.php`: o módulo (código `ponto`, limite `employees`, passos, telas, papéis e a parte do Ponto na tela Pessoas).
- `app/Modules/TimeClock/Enums/`:
  - `TimeClockRole` (gestor, funcionário, consulta);
  - `LaborProfile` (Brasil CLT / Personalizado).
- `app/Modules/TimeClock/Models/`: `TimeClockSetting`, `Workplace`, `Employee`.
- `app/Modules/TimeClock/Services/`:
  - `TimeClockAccess` (quem é quem);
  - `EmployeeService` (regras do funcionário: limite, CPF, admissão, local; desligar);
  - `TimeClockPeople` (os campos do Ponto na tela Pessoas).
- `app/Modules/TimeClock/Policies/`: `TimeClockPolicy` (base), `WorkplacePolicy`, `EmployeePolicy`.
- Telas em `app/Modules/TimeClock/Filament/`:
  - `TimeClockSettingsPage.php`;
  - `Workplaces/` (locais);
  - `Employees/` (funcionários).
- Tabelas `tc_settings`, `tc_workplaces` e `tc_employees`, em `database/migrations/2026_10_24_110000_create_time_clock_tables.php`.
- Textos: `lang/*/timeclock.php`.
- Testes: `tests/Feature/TimeClockEmployeesTest.php`.

## Parte 2 (P2): horários de trabalho e feriados

### Horários de trabalho (Ponto → Horários de trabalho)
- **Comece por um modelo pronto:**
  - Comercial (seg–sex, 8h–18h);
  - Comercial com sábado;
  - Noturno (22h–6h);
  - 12x36 diurno e noturno;
  - 6x1.

  O formulário se preenche e dá para ajustar tudo.
- **Dois tipos:**
  - **Semanal:** cada dia da semana com a entrada, o intervalo (opcional) e a saída. Uma saída antes da entrada vira o dia seguinte (turno da noite).
  - **Escala:** trabalha alguns dias e folga outros, sem olhar o dia da semana (ex.: 12x36 = 1 dia de trabalho e 1 de folga; 6x1). Ela é contada a partir de "um dia de trabalho da escala".
- **Total por semana:** o formulário mostra as horas da semana enquanto você preenche. O 12x36 dá uma média de 38h30.
- **Conferências:** pelo menos um dia de trabalho; entrada e saída preenchidas; o intervalo dentro do horário.
- **Um horário que já foi dado a alguém não é alterado nem apagado**, porque mudaria os dias que já passaram. Use **Duplicar**, ajuste a cópia e troque o horário das pessoas a partir de uma data.

### O horário de cada funcionário
- Em **Pessoas** (parte "Ponto"), o funcionário ganha o **horário de trabalho** e a data **"Horário vale a partir de"**.
  - Vazio: desde a admissão (funcionário novo) ou a partir de hoje (troca).
  - **Os dias antes da troca continuam com o horário antigo** (fica um histórico).
- **Quem bate ponto precisa de um horário.** O horário não pode começar antes da admissão.
- Na lista de **Funcionários**:
  - a coluna **Horário** mostra o de hoje;
  - o botão **"Ver escala do mês"** mostra cada dia: horário e horas, folga ou feriado, e o total previsto no mês.

### Feriados (Ponto → Feriados)
- **Brasil:** os **feriados nacionais são calculados sozinhos** para qualquer ano, incluindo os que dependem da Páscoa. Não é preciso cadastrar nada. A lista do ano aparece no topo da tela, com setas para ver outros anos.
  - O Dia da Consciência Negra (20/11) é nacional desde 2024.
- **Feriados opcionais** (em Configurações do ponto):
  - **Sexta-feira Santa:** ligada por padrão;
  - **Carnaval** (segunda e terça) e **Corpus Christi:** desligados por padrão.

  Ligados, contam como feriado para todos.
- **Feriados da empresa:** os do estado, da cidade e os próprios.
  - Uma vez só ou **"repete todo ano"**.
  - Para **toda a empresa** ou **só um local de trabalho** (ex.: o aniversário da cidade de uma filial).
- **Outros países:** a empresa cadastra os feriados do país dela. Listas prontas de outros países podem vir depois.
- **No dia de feriado ninguém é esperado no trabalho.** Quem trabalhar, terá as horas calculadas na parte 4.

### Regras de cálculo (Configurações do ponto)
São usadas no cálculo das horas a partir da parte 4:
- hora extra (%) e hora extra em folga e feriado (%);
- horário e % do adicional noturno, e a hora noturna reduzida (52min30s);
- tolerância por batida e por dia;
- banco de horas (sim ou não) e o prazo para compensar.

**Brasil (CLT):** os valores já vêm com o mínimo da lei, e a empresa pode melhorar, mas não ficar abaixo:
- hora extra de pelo menos 50%, e 100% em folga e feriado;
- noturno das 22h às 5h, com pelo menos 20% e a hora reduzida (estes não mudam);
- tolerância de até 5 minutos por batida e 10 por dia;
- banco de horas de 6 meses (acordo individual por escrito) ou 12 meses (acordo coletivo).

**Personalizado:** a empresa informa tudo, do jeito do país dela.

## Arquivos (parte 2)
- Tabelas `tc_schedules` (horários), `tc_employee_schedules` (o horário de cada funcionário a partir de uma data) e `tc_holidays` (feriados da empresa), mais as regras de cálculo em `tc_settings`: `database/migrations/2026_10_25_100000_create_time_clock_schedule_tables.php`.
- `app/Modules/TimeClock/Models/`: `Schedule`, `EmployeeSchedule`, `Holiday`; `TimeClockSetting` ganhou as regras e os mínimos da lei (`LEGAL`).
- `app/Modules/TimeClock/Services/`:
  - `ScheduleService` (modelos prontos, conferências, duplicar, trocar o horário a partir de uma data);
  - `ScheduleCalendar` (o que cada dia espera: horas, intervalo, folga ou feriado; o mês de um funcionário);
  - `HolidayCalendar` (feriados nacionais calculados, a Páscoa, os opcionais e os da empresa).
- `app/Modules/TimeClock/Policies/`: `SchedulePolicy` (horário em uso não muda), `HolidayPolicy`.
- Telas:
  - `app/Modules/TimeClock/Filament/Schedules/` (horários);
  - `Holidays/` (feriados + `NationalHolidaysWidget`, com `resources/views/timeclock/national-holidays.blade.php`);
  - `TimeClockSettingsPage.php` (regras e feriados opcionais);
  - o mês do funcionário: `resources/views/timeclock/employee-month.blade.php`.
- Testes: `tests/Feature/TimeClockSchedulesTest.php`.

## Parte 3 (P3): bater o ponto

### Pelo celular (quem tem login e bate ponto)
- Menu **Ponto → Bater ponto**, ou o botão grande no quadro **"Meu ponto hoje"** da página Início.
- A tela abre a **câmera ao vivo** (selfie na hora, não uma foto da galeria) e pede a **localização**.
- O botão **"Registrar ponto agora"** grava a batida com a **hora do servidor**, nunca a do celular.
- **Nada bloqueia a batida.** Ela só fica marcada para o gestor quando:
  - está **fora do local** (fora do raio do local de trabalho);
  - está **sem localização**;
  - está **sem foto**.
- O funcionário **externo** (que trabalha na rua) não tem raio: grava só a localização.
- Dois toques no mesmo minuto valem como **uma batida só**.
- Embaixo aparecem as batidas de hoje e as últimas, cada uma com o **comprovante**.

### Pelo tablet da empresa (com ou sem login)
1. O gestor cadastra o tablet em **Ponto → Relógios (tablets)**, escolhendo o local onde ele fica.
2. **No próprio tablet**, o gestor entra no sistema e toca em **"Usar este aparelho como relógio"**.
   - O tablet guarda um **segredo** (aqui fica só uma "impressão" dele).
   - **A sessão do gestor é encerrada no tablet**, que nunca fica aberto com o login dele.
3. O tablet passa a mostrar o **relógio de ponto** (`endereço-da-empresa/relogio`). O funcionário:
   - digita o **código pessoal (PIN)** de 6 números;
   - olha para a câmera e toca em **"Registrar ponto"**.

   Aparece o comprovante com um **QR code**: apontando o celular para ele, a pessoa guarda o comprovante, mesmo sem ter login.
- **PIN:** criado pelo gestor em **Funcionários → ícone da chave** ("Criar código (PIN)").
  - O sistema escolhe o número, então duas pessoas nunca têm o mesmo.
  - **Ele aparece uma vez só**: o gestor entrega à pessoa. Se ela perder, gera-se outro e o antigo para de valer.
- **Segurança:** 10 códigos errados em 1 minuto fazem o tablet esperar. Depois de digitar o PIN, a pessoa tem 3 minutos para tirar a foto.
- **Tablet perdido ou trocado:** "Desativar" faz ele parar na hora.

### A batida nunca muda
- Cada batida recebe um **número em sequência na empresa (NSR)** e guarda a **"impressão digital" da anterior** (uma corrente). Qualquer alteração apareceria.
- Uma batida **nunca é alterada nem apagada**. As correções entram na parte 4, sempre à parte e com o motivo. A única coisa que pode sair é a foto antiga, pela limpeza automática da parte 5.

### O comprovante
- Traz:
  - a empresa e o funcionário (com CPF ou documento);
  - a data e a hora no fuso do local;
  - o NSR, por onde foi registrado e o local;
  - um **código de verificação**, feito com a chave secreta do sistema: um comprovante copiado ou mudado não bate.
- Fica num **endereço assinado**: funciona sem login, mas qualquer mudança no endereço é recusada. Há a página e o **PDF**.

### Telas do gestor
- **Ponto → Batidas:** todas as batidas, com a foto, quando (hora do local) e o NSR, quem, por onde, a distância do local (com link para o mapa) e os avisos. Filtros: funcionário, período e "só com avisos". É só leitura.
- **Ponto → Relógios (tablets):** cadastrar, ativar, desativar e ver o último uso.

## Arquivos (parte 3)
- Tabelas `tc_punches` (batidas), `tc_kiosks` (tablets) e o PIN do funcionário, mais o último NSR e a última "impressão" da empresa em `tc_settings`: `database/migrations/2026_10_25_200000_create_time_clock_punch_tables.php`.
- `app/Modules/TimeClock/Models/`: `Punch` (nunca muda, só a foto pode sair), `Kiosk`.
- `app/Modules/TimeClock/Services/`:
  - `PunchService` (registrar, avisos, NSR, corrente e a conferência dela);
  - `PunchPhotoStore` (guardar a foto da câmera);
  - `KioskService` (ativar e desativar tablets, PIN);
  - `ReceiptService` (comprovante, código, endereço assinado, PDF, QR code).
- Telas:
  - `app/Modules/TimeClock/Filament/PunchPage.php` + `resources/views/timeclock/punch-page.blade.php` (Bater ponto);
  - `Punches/` (batidas);
  - `Kiosks/` (tablets);
  - `resources/views/timeclock/home/employee.blade.php` (quadro "Meu ponto hoje").
- Relógio do tablet: `app/Http/Controllers/TimeClock/KioskController.php` + `resources/views/timeclock/kiosk.blade.php`. As duas ações do relógio não usam a "senha do formulário" do Laravel (`bootstrap/app.php`), porque o tablet fica horas na mesma tela; elas são protegidas pelo segredo do tablet.
- Comprovante: `app/Http/Controllers/TimeClock/ReceiptController.php` + `resources/views/timeclock/receipt.blade.php` e `receipt-pdf.blade.php`.
- Biblioteca nova: `bacon/bacon-qr-code` (QR code).
- Testes: `tests/Feature/TimeClockPunchesTest.php`.

## Parte 4 (P4): cálculo do dia e banco de horas

### Apuração (gestor) e Meu ponto (funcionário)
- Menu **Ponto → Apuração** (gestor e consulta, com escolha do funcionário) ou **Meu ponto** (o funcionário vê só o dele).
- Mostra o mês **dia a dia**:
  - o previsto e as batidas (a letra **"I"** marca a batida incluída por correção);
  - o trabalhado, a hora extra, o que faltou e o noturno;
  - os avisos: **falta**, **batida faltando**, **intervalo curto**, **descanso menor que 11h**, abono e pedido esperando.
- **Totais do mês:** previsto, trabalhado, horas extras, extras em folga e feriado, atrasos e saídas antes, noturno, faltas e o **banco de horas**.
- O cálculo **nunca fica guardado**: sai sempre das batidas, das correções aprovadas, do horário e das regras, então uma correção aparece na hora. O mês fechado (parte 5) guarda a própria cópia.

### Como cada dia é calculado
- **De que dia é a batida:** de meia-noite a meia-noite. Depois de um turno que passa da meia-noite, o dia vira **4 horas depois do fim do turno** (no máximo ao meio-dia). Assim, a saída das 6h do turno da noite conta para a noite em que ele começou.
- As batidas formam **pares** (entrada e saída). Número ímpar = **batida faltando**: contam só os pares completos e o dia fica marcado.
- **Tolerância (CLT, art. 58, e Súmula 366 do TST), decisão do dono em 29/10/2026:** vale para a **entrada e a saída do dia** (a primeira e a última batida). Se cada uma ficou até 5 minutos do horário previsto e as duas juntas até 10, **o dia conta exatamente como o previsto**. Passou disso, **contam todos os minutos**, não só o excesso. O intervalo é conferido à parte, pelo aviso "Intervalo de X min". (Pergunta levada ao advogado/contador.)
- **Dia normal:** a diferença vira **hora extra** ou **minutos que faltaram** (atraso ou saída antes).
- **Dia sem nenhuma batida:** **falta**, a não ser que haja abono.
- **Folga ou feriado trabalhado:** todos os minutos são extra com o **% de folga e feriado** (100% no Brasil).
- **Hoje** entra no previsto, mas só fica com falta ou atraso quando o dia termina.
- **Noturno:** os minutos dentro do horário noturno (22h às 5h no Brasil), já com a **hora reduzida** de 52min30s quando a regra usa.
- **Avisos do Brasil** (só avisos, nada é descontado):
  - **intervalo curto:** menos de 1 hora de intervalo para quem trabalhou mais de 6 horas, ou menos de 15 minutos para quem trabalhou de 4 a 6 horas;
  - **descanso menor que 11h** entre um dia e outro.

### Correções (a batida original nunca muda)
- **O funcionário pede** em Meu ponto (o lápis do dia):
  - **"Esqueci de bater"** (o horário; "foi no dia seguinte" para quem sai de madrugada);
  - **"Batida errada"** (escolhe qual desconsiderar);
  - **"Abono"** (alguns minutos ou o dia inteiro, com o atestado anexo, que é opcional).

  O **motivo é obrigatório**. O gestor recebe o aviso no sino.
- **O gestor aprova ou recusa** em **Ponto → Correções** (recusar pede uma resposta), e o funcionário recebe o aviso. A correção que o próprio gestor faz na Apuração vale na hora.
- Não dá para corrigir um dia que ainda não chegou, nem um dia antes da admissão, nem um **mês fechado** (parte 5).

### Banco de horas (quando a empresa usa)
- A **hora extra dos dias normais** vira crédito. **Atrasos e saídas antes** saem do banco, sempre do crédito mais antigo.
- O **crédito não usado dentro do prazo** (6 ou 12 meses no Brasil) **vence e precisa ser pago** como hora extra. A tela mostra quanto venceu.
- Para ficar simples e claro: **faltas do dia inteiro não saem do banco** (aparecem à parte) e **extra em folga ou feriado é pago**, não vai para o banco.

## Arquivos (parte 4)
- Tabela `tc_adjustments` (correções, à parte das batidas): `database/migrations/2026_10_25_300000_create_time_clock_adjustments_table.php`. Modelo: `app/Modules/TimeClock/Models/Adjustment.php`.
- `app/Modules/TimeClock/Services/`:
  - `DayCalculator` (o cálculo de cada dia e os totais);
  - `HourBank` (o banco de horas);
  - `AdjustmentService` (pedir, corrigir, aprovar, recusar, avisos);
  - `MonthClosing` (o mês fechado, completado na parte 5).
- Telas:
  - `app/Modules/TimeClock/Filament/TimesheetPage.php` + `resources/views/timeclock/timesheet-page.blade.php` (Apuração / Meu ponto);
  - `Adjustments/` (Correções).
- Testes: `tests/Feature/TimeClockCalculationTest.php`.

## Parte 5 (P5): fechamento do mês, espelho e arquivos

### Fechamento do mês (gestor)
- Menu **Ponto → Fechamento do mês**. Abre no mês que acabou de terminar; as setas trocam o mês.
- Cada funcionário aparece com os totais do mês e a **situação**:
  - **Aberto**;
  - **Fechado, esperando assinatura**;
  - **Assinado**;
  - **Não concorda** (com o motivo).
- **"Fechar o mês de todos"** ou **"Fechar o mês"** em uma pessoa:
  - só depois que o mês termina: a partir do **meio-dia do dia 1º**, para o turno da noite do último dia ficar completo;
  - quem tem **correção esperando resposta** fica de fora até o gestor responder (a tela mostra o nome e o motivo);
  - num mês sem nenhum funcionário (ex.: todos entraram depois), o botão fica apagado, com a explicação "Ninguém trabalhou neste mês".
- O botão **"Arquivo da fiscalização (AFD)"** (antes "Arquivo AFD (prévia)") explica o que é: o arquivo oficial das batidas que o fiscal do trabalho pode pedir. Ainda é uma prévia.
- **Fechar congela o mês:**
  - o sistema guarda uma **cópia de todos os dias e dos totais**, com um código (SHA-256) que mostra se alguém mexeu na cópia;
  - dali em diante, a tela e o PDF mostram sempre essa cópia, mesmo que as regras mudem depois;
  - **não entram mais correções naquele mês**.
- **Reabrir** (com motivo) só **antes da assinatura**. O fechamento anterior fica guardado no histórico; depois de corrigir, fecha-se de novo.

### Espelho de ponto e assinatura (funcionário)
- Quando o mês fecha, o funcionário recebe o aviso no sino e vê em **Meu ponto**:
  - **"Assinar o espelho"**: marca "Conferi e concordo". Fica registrado quem, quando, o endereço de internet (IP) e o aparelho, com um código da assinatura;
  - **"Não concordo"**, com o que está errado: o gestor recebe o aviso e pode reabrir para corrigir.
- **Espelho (PDF)** em Meu ponto, na Apuração e no Fechamento:
  - empresa, funcionário, cada dia com as batidas e as horas, os totais e o banco de horas;
  - os percentuais das regras;
  - quem fechou e a assinatura eletrônica com os códigos.

  Mês ainda aberto sai marcado como **"Prévia"**. Sem assinatura eletrônica, o PDF traz as linhas para assinar no papel (para quem não tem login).

### Planilha para o contador (Excel)
- Botão no Fechamento do mês. Duas abas:
  - **Resumo do mês**, uma linha por funcionário: documento, PIS, cargo, local, situação, previsto, trabalhado, extras (com o %), extras de folga e feriado, atrasos, faltas, abonos, noturno, dias com batida faltando e o banco de horas;
  - **Dia a dia**.
- As horas vão como **horas de verdade do Excel** (dá para somar). O banco de horas vai em **horas decimais** (ex.: −3,25), porque pode ser negativo.

### Arquivo AFD (Brasil) — **prévia**
- Botão **"Arquivo AFD (prévia)"** no Fechamento do mês, só para o gestor e só no perfil "Brasil (CLT)".
- Segue a Portaria 671 (REP-P):
  - cabeçalho;
  - uma linha por batida original, com o NSR, a data e hora com fuso, o CPF e a corrente SHA-256 (refeita desde a primeira batida da empresa);
  - o rodapé.
- **Ainda é uma prévia, a validar** com o leiaute oficial e o validador do Ministério antes de entregar a um fiscal. Faltam:
  - os registros de mudança da empresa e dos funcionários (tipos 2 e 5);
  - a **assinatura digital com certificado ICP-Brasil** (.p7s);
  - o **AEJ**.

  A tela avisa o que está faltando: CNPJ e razão social da empresa (em **Configurações do ponto → Empregador nos arquivos oficiais**), o registro do programa no INPI (providência da Kiban, no `.env`: `KIBAN_PONTO_INPI_REGISTRATION` e `KIBAN_PONTO_DEVELOPER_DOCUMENT`) e quem está sem CPF válido.

### Avisos (sino, nunca por e-mail) e quadro "Atenção"
- **Rotina de hora em hora** (`kiban:timeclock`, aos 20 minutos de cada hora), cada aviso uma vez só:
  - **ao funcionário:** "Faltou uma batida em (data)", no dia seguinte, depois do meio-dia;
  - **aos gestores:** o resumo do dia anterior (faltas e batidas faltando);
  - **aos gestores:** "o mês ainda não foi fechado", a partir do dia 3;
  - **aos gestores:** as fotos que vão ser apagadas no próximo mês.
- **Na hora:** mês fechado (para o funcionário assinar), discordância (para os gestores) e reabertura (para o funcionário).
- **Quadro "Atenção" do Início:**
  - **gestor:** correções esperando resposta, quem não concorda com o espelho, o mês anterior sem fechar (a partir do dia 3) e as batidas fora do local hoje;
  - **funcionário:** espelho para assinar e batida faltando nos últimos 7 dias (some quando pede a correção).

### Fotos antigas
- As **fotos das batidas são apagadas depois de 12 meses**; a batida continua (hora, local, número, corrente).
- Os **anexos das correções** (atestados) **ficam guardados**.

## Arquivos (parte 5)
- Tabela `tc_closings` e os dados do empregador em `tc_settings`: `database/migrations/2026_10_25_400000_create_time_clock_closings_table.php`. Modelo: `app/Modules/TimeClock/Models/Closing.php`.
- `app/Modules/TimeClock/Services/`:
  - `MonthClosing` (fechar, congelar, assinar, discordar, reabrir);
  - `TimesheetDocument` (o mês: cópia congelada ou cálculo, e o PDF);
  - `TimeClockExporter` (planilha do contador);
  - `AfdFile` (AFD, prévia);
  - `TimeClockNotices` (avisos e "Atenção");
  - `TimeClockCleanup` (fotos antigas).
- Telas: `app/Modules/TimeClock/Filament/ClosingsPage.php`; a `TimesheetPage` ganhou o PDF, "Assinar" e "Não concordo". PDF: `resources/views/timeclock/pdf/timesheet.blade.php`.
- Rotina: `app/Console/Commands/RunTimeClockTasks.php` (agendada em `routes/console.php`).
- CNPJ: `app/Rules/Cnpj.php`.
- Testes: `tests/Feature/TimeClockClosingTest.php`.

## Parte 6 (P6): painel do Ponto

### Ponto → Painel (gestor e consulta)
- **Agora** (atualiza sozinho a cada minuto), cada nome abre o mês da pessoa:
  - **Trabalhando**, desde que horas;
  - **Atrasados**: tinham horário, passou a entrada mais a tolerância e não há batida;
  - **No intervalo**;
  - **Entram mais tarde**;
  - **Já saíram**;
  - quem está **abonado** hoje, e as batidas **fora do local** hoje.
- **Resumo do mês** (escolha o mês no alto; mês fechado usa a cópia congelada):
  - horas extras, extras em folga e feriado, atrasos e saídas antes;
  - faltas (e dias com batida faltando), noturno;
  - o **saldo do banco de horas** de todos (e quanto venceu);
  - quantos meses estão **fechados e assinados** (abre o Fechamento do mês).
- **Quem mais**: as 5 pessoas com mais horas extras, com mais faltas e atrasos e com o maior saldo do banco de horas (positivo ou negativo).

### Início
- O gestor e a consulta veem no Início os quadros **"Agora"** e **"Resumo do mês"**.
- O funcionário continua vendo o botão "Bater ponto" e as batidas de hoje.

### Ponto e Frota juntos
- A pessoa é a mesma na tela Pessoas. Um motorista pode bater ponto e usar a Frota com o mesmo login, e o perfil sugerido **"Motorista"** já dá os dois (motorista na Frota e funcionário no Ponto).
- Os números de cada sistema ficam no seu painel.

## Arquivos (parte 6)
- `app/Modules/TimeClock/Services/TimeClockReports.php` (agora, o mês e os rankings).
- `app/Modules/TimeClock/Filament/Dashboard/`:
  - `TimeClockDashboard` (a página);
  - `NowWidget`, `MonthStatsWidget`, `MonthRankingWidget` (os quadros);
  - `ReadsTimeClockFilters` (o mês escolhido).
- Telas dos quadros: `resources/views/timeclock/dashboard/now.blade.php` e `ranking.blade.php`.
- Testes: `tests/Feature/TimeClockDashboardTest.php`.

## Aviso de privacidade das fotos (LGPD)

### Como funciona
- Na **primeira batida com foto**, pelo celular ou pelo tablet, **antes de a câmera ligar**, a pessoa lê o aviso "Sobre a foto no ponto". Ele explica:
  - por que a foto é tirada: confirmar quem bateu e evitar fraude no controle de jornada;
  - quem vê: o RH e os administradores;
  - por quanto tempo fica guardada: 12 meses, depois é apagada sozinha e a batida continua;
  - que não há reconhecimento facial;
  - com quem falar sobre os dados.
- O botão é **"Estou ciente"**. É **ciência, não consentimento**, por decisão do dono: no emprego, o consentimento não seria livre.
- Fica registrado:
  - quem, quando, por onde (celular ou qual tablet), o IP e o aparelho;
  - a **versão do aviso**.

  O registro nunca muda nem é apagado.
- **Sem a ciência, nenhuma foto é gravada** (mesmo que chegue uma).
- **Aviso novo**, quando muda o texto, o prazo ou o contato: todos leem e dão ciência de novo, uma vez.

### "Bater sem foto"
- O botão aparece no aviso e embaixo do botão de bater. A batida vale do mesmo jeito e fica marcada **"Sem foto (escolha da pessoa)"** para o RH.
- A empresa escolhe em **Configurações do ponto → Fotos e privacidade** se o botão aparece (padrão: aparece).
- Desligado, não há o botão. Mesmo assim, **a batida nunca é bloqueada**: se a câmera não abrir, ela vale, marcada "Sem foto".

### Onde se consulta
- **Configurações do ponto → Fotos e privacidade:** o botão "Bater sem foto" e o **contato para dúvidas**, que é opcional (sem ele, o aviso diz "fale com o RH da sua empresa").
- **Meu ponto / Apuração:** botão **"Sobre a foto no ponto"**, com o texto e a ciência da pessoa.
- **Funcionários:** coluna **"Aviso das fotos"** (ciente em... ou ainda não lido).
- **Planilha do contador:** a mesma coluna no resumo.

### Arquivos
- Tabela `tc_photo_notices` e os campos `allow_punch_without_photo` e `privacy_contact` em `tc_settings`: `database/migrations/2026_10_25_500000_create_time_clock_photo_notices_table.php`.
- Modelo `app/Modules/TimeClock/Models/PhotoNotice.php`.
- Regras em `app/Modules/TimeClock/Services/PhotoNoticeService.php`. Para mudar o texto, suba `REVISION`.
- Telas:
  - celular: `PunchPage` e `punch-page.blade.php`;
  - tablet: `KioskController` (rota `/relogio/ciente`) e `kiosk.blade.php`.
- Testes: `tests/Feature/TimeClockPhotoNoticeTest.php`.

## Bater o ponto sem internet (30/10/2026)

Funciona como o WhatsApp: **sem sinal, a batida fica guardada no celular e é enviada sozinha quando o sinal voltar.**

### Como funciona para o funcionário
- Na primeira vez que abre "Bater ponto" com internet, o celular guarda essa tela. Depois, mesmo sem sinal, o app abre direto nela (também pelo ícone na tela inicial).
- Sem sinal, aparece o aviso amarelo "Sem internet. Pode bater o ponto normalmente…".
- Ao tocar em "Registrar ponto agora", a batida (com a selfie e o local) vai primeiro para a lista **"Guardadas no celular"**, com "⏳ Esperando sinal".
- Quando o sinal volta, ela é enviada sozinha. O celular tenta ao voltar o sinal, ao abrir a tela e a cada 30 segundos, e também há o botão "Enviar agora". Na lista "Hoje", cada batida mostra **"✓ Enviada"**. Essa é a prova de envio.
- Se a sessão tiver acabado, a tela pede para entrar de novo. As batidas guardadas não se perdem e vão depois.

### A hora da batida
- Com internet (a batida chega em até 2 minutos), vale a **hora do servidor**, como antes.
- Guardada sem sinal, vale a **hora em que foi feita**. O celular corrige o próprio relógio pelo relógio do servidor (medido a cada contato). Uma hora no futuro nunca é aceita.
- A batida ganha o aviso **"Sem internet (enviada depois)"** para o gestor. O comprovante mostra a hora da batida e a hora em que o sistema recebeu, além do aviso "Batida feita sem internet".
- A mesma batida enviada duas vezes (por exemplo, quando a resposta se perdeu) conta **uma vez só**: cada batida tem um código próprio.
- A batida é **recusada**, com o motivo na tela, quando:
  - ficou guardada por mais de **7 dias**;
  - o mês dela **já foi fechado**;
  - é um toque repetido no mesmo minuto.
  Nos dois primeiros casos, o gestor inclui a batida por correção.
- A sequência das batidas (número e corrente de códigos) continua conferível. O número segue a ordem de chegada.
- No AFD, a "data e hora da gravação" é a de chegada (`created_at`).

### Privacidade
- O celular guarda só a tela "Bater ponto" e os arquivos visuais (cores, letras, imagens). As outras telas vêm sempre do servidor.
- Ao sair (Sair), a tela guardada é apagada. Batidas ainda não enviadas continuam na fila e só são enviadas para a mesma pessoa, na mesma empresa.

### Arquivos
- `public/js/punch-queue.js`: a fila no celular (IndexedDB), o relógio corrigido e o envio.
- `public/sw.js`: o "ajudante" do celular (service worker). Guarda a tela "Bater ponto" e os arquivos visuais; sem sinal, abre essa tela.
- `app/Http/Controllers/TimeClock/PhoneQueueController.php`: recebe as batidas da fila (`POST /<empresa>/ponto/enviar`) e informa a hora do servidor (`GET /<empresa>/ponto/hora`).
- `app/Modules/TimeClock/Services/PunchService.php`: regras da hora, do código único, dos 7 dias e do mês fechado.
- `database/migrations/2026_10_30_100000_add_client_id_to_tc_punches.php`: o código único de cada batida.
- `tests/Feature/TimeClockOfflineTest.php`: os testes automáticos.
- Teste num Chrome de verdade (corta a internet de fato): `docker compose run --rm studio node scripts/testar-offline.mjs`, no projeto `kiban-videos`.

## Ponto junto com o Campo e a Frota (31/10/2026)
Explicação completa em `conexoes.md`.
- **Com o Campo:** o técnico que chega na primeira OS sem ter batido a entrada recebe o lembrete "Bater a entrada agora?" (e o da saída depois da última OS). É só um lembrete com o link para "Bater ponto": a batida continua sendo do funcionário, com selfie e local (Portaria 671). No painel do Campo, o gestor vê o aproveitamento de cada técnico (horas em OS ÷ horas trabalhadas no Ponto).
- **Com a Frota:** depois de bater a entrada, o motorista vê no topo da tela "Fazer a vistoria do veículo agora" para cada veículo dele ainda sem vistoria no período (segue a frequência da Frota; só sugestão). Fica na tela do ponto, não no comprovante.
