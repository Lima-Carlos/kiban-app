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

### Quem vê o quê
| Pessoa | O que pode |
|---|---|
| Dono ou administrador da empresa (gestor) | Tudo |
| Motorista (membro com o mesmo e-mail de um motorista cadastrado) | Só os veículos ligados a ele; pode informar o km |
| Outros membros | Só olhar |

- O acesso do motorista é ligado sozinho na primeira vez que ele entra com o mesmo e-mail cadastrado.
- Um veículo de outro motorista "não existe" para ele (aparece como não encontrado).

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

## Arquivos
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
