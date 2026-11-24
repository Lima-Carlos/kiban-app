# Kiban Core Pedidos (delivery para quem vende comida)

Primeira versão (24/11/2026), **pronta mas ainda não vendida**: sem preço, fora do catálogo do Core (`kiban:catalog-launch`) e sem vídeos. Código do produto no Core: `pedidos` (nome provisório; en: Orders, es: Pedidos). O nome aparece só nos arquivos de tradução (`lang/*/pedidos.php`, chave `module`), então trocar o nome depois é mudar uma linha em cada idioma.

Para quem: pizzaria, hamburgueria, sushi, esfiha, marmita, doceria... que recebem pedidos por **telefone e WhatsApp**. O foco é **não perder clientes**: saber quem parou de pedir e chamar de volta.

## O que tem
- **Novo pedido** (tela feita para usar com o telefone na orelha): digita o telefone e o cliente aparece com nome, endereço, ritmo, observações e o **último pedido**, com o botão **"Repetir o de sempre"** (o pedido que ele mais repete entre os últimos 10, com o preço de hoje). Número novo pede só o nome e o endereço (a cidade vem sozinha das configurações). Depois é tocar nos itens do cardápio (cada linha com o texto livre: "sem cebola", "meio a meio"), escolher entrega ou retirada, de onde veio (telefone, WhatsApp, balcão, iFood/outro app), o pagamento (dinheiro com troco, Pix, cartão, pago no app), a taxa de entrega e salvar. O cliente pode autorizar ali mesmo as promoções pelo WhatsApp.
- **Pedidos agora** (quadro da cozinha): colunas Novo, Preparando, Pronto e Saiu para entrega, que se atualizam sozinhas. Um toque: "Ana prepara" (com o nome de quem prepara), "Pronto", "Cliente retirou". Botão da **comanda** para imprimir.
- **Comanda**: página estreita que serve para impressora de cupom (80 mm ou 58 mm) e para qualquer impressora. Número grande, itens com as observações, endereço com ponto de referência, pagamento e troco.
- **Entregas**: marca os pedidos prontos que vão juntos e quem leva (um toque no nome). O sistema **sugere a ordem de visita** a partir da loja: sempre o mais perto primeiro e depois troca pedaços do caminho se isso encurtar (então a ordem de quem ligou primeiro nunca obriga a um caminho maior). Mostra a distância em linha reta e o botão **"Abrir no Google Maps"** com as paradas na ordem, para o celular do entregador. Na rua: "Entregue" em cada parada; a saída fecha sozinha quando todas acabam. Sem mapa pago.
- **Todos os pedidos**: histórico com filtros (situação, de onde veio, entrega ou retirada, só hoje). Na tela do pedido: próximo passo, mudar quem preparou e quem entregou (também depois), cancelar com o motivo e a comanda. Pedido nunca é apagado.
- **Clientes**: o mesmo cadastro de clientes do Campo e da Agenda (veja "Decisões"), com telefone (um cliente por telefone na empresa), endereços de entrega (o ponto no mapa é procurado sozinho), como costuma pagar, autorização de promoções pelo WhatsApp (com a data) e o **ritmo**. A ficha mostra os últimos pedidos com quem preparou, quem entregou e quanto demorou.
- **Ritmo de cada cliente**: a média de dias entre os pedidos dele. Verde (no ritmo) até 150% dessa média, amarelo (atrasado) até 300% e vermelho (parou de pedir) depois disso. Com menos de 3 pedidos, "Novo". Os números são ajustáveis nas configurações. Pedidos cancelados e vendas de balcão sem nome não contam.
- **Mapa de clientes**: cada casa com a cor do ritmo e a loja em azul (OpenStreetMap, grátis). Mostra onde estão os clientes e que ruas esfriaram.
- **Clientes sumidos**: os vermelhos (e, se quiser, os amarelos), os que pararam mais recentemente primeiro, cada um com os 3 últimos pedidos (quem preparou, quem entregou, quanto demorou: pista do motivo) e o botão **"Chamar no WhatsApp"** com a mensagem pronta e editável ({nome} vira o primeiro nome, {empresa} o nome da empresa). **Só para quem autorizou promoções** (LGPD). O botão fica cinza com a data depois de usado.
- **Início**: quadro "Pedidos de hoje" (novos, preparando, prontos, na rua, entregues, total vendido) e o quadro "Atenção" com pedidos novos esperando a cozinha, entregas prontas esperando sair e clientes que pararam de pedir nesta semana. Sem e-mail (só sino e "Atenção", decisão do dono).
- **Configurações**: endereço e ponto da loja ("Usar minha localização" na loja), cidade e estado dos endereços novos, taxa de entrega sugerida, mensagem para chamar de volta e os limites do ritmo.

## Primeiros passos
1. Informe o endereço da loja (configurações). 2. Cadastre o cardápio. 3. Anote o primeiro pedido (só abre depois do cardápio).

## Quem usa (funções)
- **Gestor dos pedidos**: tudo (dono e administradores sempre são gestores).
- **Atendimento**: anota pedidos, cadastra clientes, entregas, clientes sumidos e mapa.
- **Cozinha**: o quadro (preparando, pronto) e a comanda.
- **Entregador**: a tela de entregas.
- **Somente consulta**: olha.
Na tela **Pessoas**, cada pessoa pode ser marcada como "Trabalha na cozinha" e/ou "Faz entregas" (com ou sem login). Elas viram os botões de um toque "quem preparou" e "quem leva". Quem tem login e está marcado ganha a função sozinho (cozinha ou entregador). Enquanto ninguém está marcado, os botões mostram todas as pessoas ativas.

## Decisões
- **Clientes compartilhados**: o cliente dos Pedidos é o mesmo cadastro do Campo e da Agenda (tabela `fd_customers`, endereços em `fd_sites`), como a Agenda já faz. Uma empresa, uma lista de clientes, nunca duas cópias. A classe `Pedidos\Models\Client` só acrescenta o que os Pedidos precisam (pedidos, forma de pagamento e autorização do WhatsApp, na tabela `pd_client_details`) e tem as próprias regras de acesso. O endereço reaproveita os campos e a busca no mapa do Campo (`AddressLocator`, rodado pelo `kiban:field`, que agora também procura endereços de empresas com os Pedidos).
- **Um telefone, um cliente**: regra dos Pedidos (o cliente é achado pelo telefone). O Campo continua podendo ter uma empresa com o mesmo telefone do dono.
- **Preço e nome copiados no pedido**: mudar o cardápio nunca muda pedido antigo. Valores sempre em centavos.
- **iFood e outros apps**: só anotados para o histórico do cliente (o app continua cuidando do pedido). Nada é enviado a eles.
- **Ordem de entrega sem mapa pago**: distância em linha reta (o caminho de verdade pelas ruas é maior), suficiente para escolher a ordem. O link do Google Maps é grátis (não usa chave). No celular, o Google Maps aceita poucas paradas por link (cerca de 9).
- **Ritmo calculado na hora** a partir dos pedidos (nada guardado), então sempre bate com o histórico.

## Próximas partes (não estão nesta versão)
- Pagamento online (Pix, cartão) e conferência do caixa do dia.
- Integração com o iFood e outros apps (receber os pedidos sozinho).
- Pedidos pelo site da Vitrine (cardápio online com carrinho, caindo direto no quadro da cozinha).
- Avaliação depois da entrega e um "jeito de avaliar" o atendimento (nota do cliente por pedido, ligada a quem preparou e quem entregou).
- Mensagens automáticas pelo WhatsApp ("seu pedido saiu", "chegou") quando o WhatsApp automático for ligado.
- Tempo estimado de entrega para falar ao cliente, impressão automática da comanda e adicionais com preço (borda recheada, bacon extra) em vez de texto livre.
- Relatórios: mais vendidos, horários de pico, ticket médio, tempo médio por entregador; e o resumo da semana do dono.
- Cupom de volta para os sumidos (desconto que o sistema confere) e campanhas por bairro no mapa.
- Vídeos (só quando a parte for vendida) e o preço (decisão do dono).

## Arquivos
- `app/Modules/Pedidos/PedidosModule.php`: o módulo (passos, telas, funções, "Atenção", campos em Pessoas).
- `app/Modules/Pedidos/Enums/`: situação do pedido, entrega/retirada, de onde veio, pagamento, ritmo e funções.
- `app/Modules/Pedidos/Models/`: `PedidosSetting`, `MenuItem`, `Client` (o cliente compartilhado), `ClientDetail`, `Staff` (cozinha/entregas), `Order`, `OrderLine`, `Run` (saída de entregas).
- `app/Modules/Pedidos/Services/`: `OrderService` (toda mudança de pedido e as saídas de entrega), `ClientService` (achar pelo telefone, cliente novo, "o de sempre"), `RoutePlanner` (ordem de visita e link do Google Maps), `CustomerRhythm` (verde, amarelo, vermelho), `PedidosReports` (Início e "Atenção"), `PedidosMessages` (WhatsApp), `PedidosPeople` (campos em Pessoas), `PedidosAccess`, `PedidosFormat`, `PedidosException`.
- `app/Modules/Pedidos/Policies/`: regras de acesso de cada tela.
- `app/Modules/Pedidos/Filament/`: as telas (`OrderDeskPage`, `BoardPage`, `DeliveriesPage`, `MissingClientsPage`, `ClientsMapPage`, `PedidosSettingsPage`, `TodayWidget`, cardápio, clientes e pedidos).
- `app/Http/Controllers/Pedidos/TicketController.php` e `resources/views/pedidos/ticket.blade.php`: a comanda (rota `pedidos.ticket`, `/pedidos/{pedido}/comanda`, só com login).
- `resources/views/pedidos/`: as páginas (novo pedido, quadro, entregas, sumidos, mapa, quadro do Início).
- `lang/*/pedidos.php`: todos os textos nos 3 idiomas.
- `database/migrations/2026_11_24_100000_create_pedidos_tables.php`: as tabelas `pd_*` (todas com a empresa; as ligações usam empresa + id, e a ligação com pessoas só fica vazia se a ficha da pessoa for apagada).
- `tests/Feature/PedidosTest.php`: os testes (ordem de entrega, novo pedido, "o de sempre", caminho do pedido, ritmo, sumidos só com autorização, funções, telas e isolamento entre empresas).
