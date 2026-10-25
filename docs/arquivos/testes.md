# Testes automáticos

**O que é:** testes que conferem sozinhos se tudo funciona. Eles rodam no seu computador e no GitHub a cada envio.

- Rodar: `docker compose exec app php artisan test`
- Usam o banco separado `kiban_app_test`, nunca o banco real.
- **O Core é simulado** (`tests/TestCase.php`): os testes criam um par de chaves próprio e respondem como o Core responderia. Assim rodam sem internet e sem o Core ligado.

| Arquivo | O que confere |
|---|---|
| `TenancyTest` | Uma empresa nunca vê nem altera dados de outra (inclusive no próprio banco) |
| `LicenseTest` | Licença falsa recusada, menu pela licença, somente consulta, limite, Core fora do ar |
| `SingleSignOnTest` | Login pelo Core, PKCE, links de uso único, removido da equipe perde acesso |
| `BrowserFlowTest` | Os botões das telas funcionam como num navegador (pedidos "por trás" sabem a empresa) |
| `FleetVehiclesTest` | Frota: km nunca volta, travas de placa, limite de veículos, motorista vê só os dele |
| `FleetFuelTest` | Frota: código de abastecimento, página do posto, média de consumo, avisos, papéis, painel |
| `FleetInspectionTest` | Frota: vistoria pelo celular, problemas viram solicitações, item grave para o veículo, frequência e lembrete no abastecimento, itens da vistoria, quem vê o quê |
| `FleetNoticesTest` | Avisos: cada aviso para as pessoas certas, no idioma de cada uma, uma vez só, só na própria empresa; quadro "Atenção"; limpeza dos antigos |
| `FleetReportsTest` | Frota: planilhas com os números do painel, ficha em PDF, quem pode baixar, limpeza de fotos antigas (dados ficam) com aviso antes |
| `FleetMaintenanceTest` | Frota: pedido do motorista → agenda → manutenção, próxima troca de óleo/revisão, lavagens, despesas, menus de cada papel, painel com todos os gastos |
| `PeopleTest` | Pessoas: adicionar pelo celular, editar, novo link, desligar (a ficha fica), menus por papel, app instalável |
| `TimeClockCalculationTest` | Ponto (P4): tolerância da CLT, extras, atrasos, falta, folga e feriado, turno da noite que passa da meia-noite, noturno com hora reduzida, intervalo curto e descanso menor que 11h, correções pedidas/aprovadas/recusadas, banco de horas com vencimento, quem vê o quê, regras de outro país |
| `TimeClockPunchesTest` | Ponto (P3): bater ponto no celular (selfie, local, avisos, toque duplo), funcionário externo, batida que nunca muda, NSR e corrente por empresa, relógio do tablet (PIN, tentativas, tempo para a foto, desativar), ativação que encerra a sessão do gestor, comprovante em endereço assinado, quem vê o quê |
| `TimeClockSchedulesTest` | Ponto (P2): horários semanais e noturnos, 12x36 e 6x1, histórico do horário do funcionário, feriados nacionais (Páscoa), opcionais e da empresa, regras de cálculo com os mínimos da CLT |
| `TimeClockEmployeesTest` | Ponto (P1): pessoa sem login que ganha login depois, CPF (válido, único, obrigatório no Brasil), documento e celular de outro país, limite de funcionários, quem vê o CPF, locais com ponto no mapa, equipes, separação entre empresas |
| `AccessProfilesTest` | Um sistema com vários módulos: sem acesso por padrão, perfis sugeridos e editáveis, Início com os resumos |
| `LocaleTest` | Troca de idioma no sistema e nas páginas simples |
| `FirstStepsTest` | Passos em ordem, dependências, envio ao Core só quando muda |

Fábricas de dados de teste: `database/factories/` (`TenantFactory`, `UserFactory`).
