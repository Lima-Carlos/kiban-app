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
