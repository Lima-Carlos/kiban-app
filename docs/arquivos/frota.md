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
- Lista de **consumo fora do normal**. A manutenção entra no painel na parte 3.

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
