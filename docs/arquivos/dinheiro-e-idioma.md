# Dinheiro e idioma

## Valores em dinheiro (`app/Support/Money.php`)
- Todo valor em dinheiro que aparece na tela passa por `Money::format()`: Frota, Campo, Agenda, Rotina, Estoque, Despesas e EPI.
- O valor é escrito como no país da moeda, em qualquer idioma. O real aparece sempre como **R$ 31,20**, mesmo para quem usa o sistema em inglês ou espanhol. Antes aparecia "31,20 BRL" ou "R$31.20".
- O peso mexicano aparece como no México (**$1,234.50**). As outras moedas seguem a mesma regra.
- Os campos de valor mostram o símbolo na frente (`Money::symbol()`: "R$", "$", "€"), não o código ("BRL").
- O teste é o `tests/Feature/MoneyTest.php`.

## Idioma das páginas sem login (`app/Http/Middleware/SetLocale.php`)
- Quem entra no sistema vê o idioma que escolheu. Sem escolha, vale o da conta Kiban e depois o da empresa.
- As páginas sem login são o `/agendar` da Agenda, o orçamento e o relatório do Campo, e a etiqueta QR do PMOC. Elas seguem primeiro o **idioma do celular do cliente**: um cliente com o celular em espanhol lê em espanhol.
- Se o celular estiver num idioma que não temos (alemão, por exemplo), vale o idioma da empresa.
