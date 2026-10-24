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
3. Cadastre os funcionários.

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
