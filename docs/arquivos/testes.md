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
| `FleetMaintenanceTest` | Frota: pedido do motorista → agenda → manutenção, próxima troca de óleo/revisão, lavagens, despesas, menus de cada papel, painel com todos os gastos |
| `PeopleTest` | Pessoas: adicionar pelo celular, editar, novo link, remover, menus por papel, app instalável |
| `AccessProfilesTest` | Um sistema com vários módulos: sem acesso por padrão, perfis sugeridos e editáveis, Início com os resumos |
| `LocaleTest` | Troca de idioma no sistema e nas páginas simples |
| `FirstStepsTest` | Passos em ordem, dependências, envio ao Core só quando muda |

Fábricas de dados de teste: `database/factories/` (`TenantFactory`, `UserFactory`).
