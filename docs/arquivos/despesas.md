# Kiban Core Despesas (reembolso de gastos)

Primeira versão (03/11/2026). Código do produto no Core: `despesas` (en: Expenses, es: Gastos). Preço **provisório** (a confirmar pelo dono): R$ 79 com 10 pessoas + R$ 6 por pessoa extra (US$ 15 + 1). A pesquisa de mercado mostrou que o VExpenses cobra cerca de R$ 21 por pessoa, então mesmo assim fica bem mais barato.

## O que tem
- **Despesas**: cada pessoa lança o que pagou pela empresa (data, categoria, valor, o que foi) com a **foto do recibo**, tirada na hora pelo celular. Opcional: a OS do Campo e o veículo da Frota.
- **Categorias** prontas: pedágio, estacionamento, combustível, alimentação, hospedagem, táxi/aplicativo, material comprado, km com carro próprio e outros. O gestor muda o nome, o **valor máximo** (acima dele a despesa aparece em destaque, mas não é bloqueada) e se o recibo é obrigatório.
- **Aprovar ou recusar**: só o gestor das despesas. A recusa pede o motivo, que a pessoa vê no sino.
- **Reembolsos**: as despesas aprovadas somadas por pessoa. Depois do Pix, "Marcar como pago" e a pessoa recebe o aviso. Planilha do mês para o contador.
- **Ligação com a Frota**: despesa aprovada com veículo, numa categoria ligada à Frota (pedágio, estacionamento, outros), entra nos gastos do veículo e no custo por km.
- **Km com carro próprio**: o gestor define o valor por km na categoria; a pessoa digita os km rodados e o valor é calculado sozinho (km × valor). Sem recibo. Sem o valor definido, o sistema avisa.
- **Atenção no Início**: despesas esperando aprovação, para o gestor.

## Quem usa
Dono e administradores aprovam e pagam (função "Gestor das despesas"). Todos os outros lançam e veem só as próprias.

## Limite do plano
Pessoas que lançam despesas (`people`).

## Arquivos
`app/Modules/Expenses/` (módulo, modelos, `ExpenseService` com todas as regras, políticas, telas), `resources/views/expenses/payments.blade.php`, `lang/*/expenses.php`, `tests/Feature/ExpensesTest.php` e a migração `2026_11_15_100000_create_expense_claim_tables.php`.
