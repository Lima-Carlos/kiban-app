# Kiban Core Estoque (materiais e comodato)

Primeira versão (03/11/2026). Código do produto no Core: `estoque` (en: Inventory, es: Inventario). Preço **provisório** (a confirmar pelo dono): R$ 79 com 5 locais + R$ 10 por local extra.

## O que tem
- **Itens**: o que a empresa guarda (cabo, conector, roteador...), com unidade, código, estoque mínimo e, com o Campo, qual material do Campo ele é. Marcando "Controlar por número de série", cada unidade tem o seu número (equipamentos de comodato).
- **Locais**: o almoxarifado e o **estoque de cada técnico** (o carro). Com o Campo, o botão "Criar o local de cada técnico" cria "Carro de ..." para cada técnico.
- **Estoque** (saldos): quanto há de cada item em cada local. Botões: **Entrada** (o que chegou, com nota), **Transferir** (ex.: almoxarifado → carro do técnico; nunca mais do que há) e **Ajustar pela contagem** (a diferença vira ajuste).
- **Ligação com o Campo**: quando o técnico lança um material na ordem de serviço, ele **sai sozinho do estoque do carro dele** (pode ficar negativo, porque já foi usado). Se o material for tirado da OS, volta.
- **Comodato** (números de série): cadastrar vários números de uma vez (um por linha, serve leitor de código de barras); enviar para outro local; **entregar ao cliente** (cliente do Campo ou nome); devolver; marcar com defeito ou perdido. A lista mostra onde está cada equipamento e desde quando.
- **Movimentações**: o histórico de tudo, nunca editado (quem, quando, de onde para onde, qual OS).
- **Meu estoque** (técnicos): o que o técnico carrega, em cartões, no celular.
- **Atenção no Início**: itens abaixo do mínimo, para o gestor.

## Quem usa
Dono e administradores gerenciam. Com o Campo: gestor e atendimento do Campo gerenciam o estoque; quem só consulta o Campo só consulta; técnicos veem "Meu estoque".

## Limite do plano
Locais ativos (`locations`): almoxarifados e técnicos juntos.

## Arquivos
- `app/Modules/Stock/StockModule.php` (liga o Campo pelo `boot()`), `Models/` (`Item`, `Location`, `Balance`, `Unit`, `Movement`), `Policies/StockPolicy.php`.
- `Services/StockService.php` (todas as regras), `StockAccess.php`, `StockFormat.php`, `StockException.php`.
- `Filament/` (Estoque, Comodato, Itens, Locais, Movimentações, Meu estoque) e `resources/views/stock/my-stock.blade.php`.
- Migração `2026_11_14_100000_create_stock_tables.php`; textos em `lang/*/stock.php`; testes em `tests/Feature/StockTest.php`.
- `app/Modules/Module.php` ganhou `boot()`: um módulo pode ouvir eventos de outro (chamado no `AppServiceProvider`).

## Etiquetas com QR (03/11/2026)
Em Comodato, **"Imprimir etiquetas"** abre a folha (A4, 3 × 7) com os equipamentos em estoque (respeita os filtros de item e local). O QR abre a **ficha do equipamento** (`UnitPage`, precisa de login): situação, onde está (local ou cliente), desde quando e o histórico. Técnicos também abrem.
