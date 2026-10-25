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
- **Tolerância (CLT, art. 58):** se cada batida ficou até 5 minutos do horário previsto e a soma do dia até 10, **o dia conta exatamente como o previsto**. Passou disso, **contam todos os minutos**, não só o excesso.
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
