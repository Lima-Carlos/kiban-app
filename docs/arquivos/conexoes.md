# Conexões entre os sistemas ("Sistemas juntos")

**O que é:** quando uma empresa usa dois ou mais sistemas da Kiban Core (Frota, Ponto, Campo), um aproveita o que o outro registra. Este arquivo lista essas ligações, explica a janela de novidade e a página **Empresa → Sistemas juntos**.

## As conexões que existem hoje (31/10/2026)
| Sistemas | Conexão | O que acontece | Quem vê na janela |
|---|---|---|---|
| Campo + Ponto | Lembrete do ponto na primeira e na última OS | Ao tocar em "Cheguei" na primeira OS do dia sem ter batido a entrada: "Você ainda não bateu o ponto. Bater a entrada agora?", com o botão que abre o "Bater ponto". Ao concluir a última OS do dia: "Bater a saída agora?". **Nunca bate o ponto pelo técnico**: a batida continua sendo um ato do funcionário, com selfie e local (Portaria 671). | técnico que bate ponto, gestor do Campo, RH |
| Campo + Ponto | Aproveitamento de cada técnico | No painel do Campo (Por técnico): horas em atendimento nas OS (da chegada ao fim) ÷ horas trabalhadas no Ponto, em %. Mês fechado no Ponto usa a cópia congelada. | gestor do Campo |
| Campo + Frota | O km da chegada vai para a Frota | O km que o técnico informa ao tocar "Cheguei" entra no histórico do veículo (já existia desde a O4). | técnico que é motorista, gestor e consulta da Frota, gestor do Campo |
| Campo + Frota | Custo estimado do deslocamento | Na OS concluída e no painel do Campo: km até o cliente × custo por km do veículo (o mesmo do painel da Frota, últimos 3 meses). Sempre escrito como "estimativa". | gestor do Campo |
| Frota + Ponto | Vistoria lembrada ao bater o ponto | Depois de bater a entrada, o motorista vê "Fazer a vistoria do veículo agora" para cada veículo dele ainda sem vistoria no período. Só sugestão. | motorista que bate ponto, gestor da Frota |
| Ponto + Frota | A CNH cadastrada pelo RH avisa a Frota | A CNH de Pessoas → Documentos atualiza a ficha do motorista e aparece no "Atenção" da Frota quando vence (já existia). | RH, gestor e consulta da Frota |
| Ponto + Campo | Os treinamentos NR avisam o Campo | NR-10, NR-35 e NR-33 vencidas aparecem no "Atenção" do Campo e ao agendar o técnico (já existia). | RH, gestor e atendimento do Campo |
| Ponto + Campo | O EPI entregue avisa o Campo | O tipo de serviço diz o "EPI necessário" (ex.: cinto para altura). O técnico sem esse EPI entregue aparece com aviso ao agendar e ao abrir a OS no celular. Só aviso, nunca bloqueia (veja `epi.md`). | RH, gestor e atendimento do Campo (o técnico vê o aviso na própria OS) |
| Quaisquer dois | Uma pessoa, um cadastro só | Uma ficha em Pessoas e um login para todos os sistemas; perfis prontos ("Técnico de campo", "Motorista", "Mecânico") dão os dois sistemas de uma vez (já existia). | quem cadastra pessoas |

## Decisões
- **Custo do deslocamento:** o km até o cliente é o km da chegada menos a leitura anterior do mesmo veículo **no mesmo dia** (outra OS, a vistoria, um abastecimento...). Sem leitura anterior no dia, sem o km ou sem custo por km na Frota, **nada aparece** (melhor nada do que um número errado). Mais de 500 km entre as duas leituras é tratado como erro de digitação. Só o gestor do Campo vê (mostra custos).
- **Aproveitamento:** só para o gestor do Campo, porque usa as horas do Ponto (dado do RH). Um técnico que bateu ponto no mês e não concluiu nenhuma OS também aparece, com 0%.
- **Lembrete do ponto:** "primeira OS" = nenhuma outra OS do técnico teve chegada hoje; "última" = nenhuma outra OS dele hoje continua esperando. Batidas que ainda estão na fila do celular (sem sinal) também contam. O lembrete nunca bloqueia nada.
- **Vistoria depois do ponto:** segue a frequência da empresa (diária, semanal ou mensal); com "livre", nunca aparece. Motorista "reserva" (qualquer veículo) não recebe a sugestão, porque não tem um veículo dele. Aparece na tela do ponto, não no comprovante (o comprovante é uma página pública, sem login).

## A janela "Novidade: seus sistemas agora trabalham juntos"
- Aparece em qualquer tela da empresa, quando existe alguma conexão **ativa** (a empresa tem os sistemas) que **importa para a pessoa** (pelo papel dela) e que ela **ainda não dispensou**.
- Cada pessoa vê só o que muda algo para ela: o técnico vê o lembrete do ponto e o km da Frota; o gestor vê o aproveitamento e o custo; quem não tem papel nenhum não vê nada.
- Cada conexão traz o título, a explicação e **"Ver onde"** (a tela onde acontece).
- **"Não mostrar novamente"** marcado + "Entendi": grava por pessoa, por empresa e por conexão (tabela `connection_dismissals`). As outras pessoas continuam vendo até cada uma marcar. O mesmo login em outra empresa vê de novo lá.
- **Sem a caixa**, "Entendi" só fecha: a janela volta **no dia seguinte** (ou num novo login). Decisão: lembrar sem incomodar no mesmo dia.
- **Conexão nova** numa versão futura aparece mesmo para quem já dispensou as antigas (a dispensa é por conexão).
- Empresas que já tinham dois sistemas antes desta versão também veem a janela (é novidade para elas).
- O celular também lembra a escolha, para a janela não voltar numa tela guardada para usar sem sinal.
- Empresa com um sistema só: nenhuma janela.

## A página Empresa → Sistemas juntos
- **Funcionando agora:** as conexões ativas (todas para dono/administrador; as que importam para a pessoa para os outros).
- **Com o Ponto (ou a Frota, o Campo), você também teria:** as conexões que um sistema não contratado ligaria sozinho, com o botão para conhecer os planos na Área do cliente do Core (`/conta/sistemas`). **Só para dono e administradores** (quem compra). Ajuda a vender o segundo sistema sem ser chato.
- Fica no menu "Empresa" para quem cuida das pessoas. Técnicos e motoristas chegam por "Ver todas", na janela.

## Vídeos
O comando `kiban:video-demo` marca todas as conexões como vistas para as contas da empresa dos vídeos, para a janela não aparecer nas gravações.

## Regra para o programador
**Toda função nova que liga dois sistemas entra no catálogo** (`App\Connections\ConnectionCatalog::all()`): chave, sistemas, quem vê (papéis), "Ver onde" e os textos em `lang/*/connections.php` (3 idiomas). A conta da função fica numa "ponte" em `app/Connections/Bridges/`.

## Arquivos
- `app/Connections/Connection.php` (uma conexão), `ConnectionCatalog.php` (a lista e as regras de quem vê), `ConnectionDismissal.php` ("não mostrar novamente").
- Pontes: `app/Connections/Bridges/FieldTimeClock.php` (lembrete do ponto e aproveitamento), `FieldFleet.php` (custo do deslocamento), `FleetTimeClock.php` (vistoria depois do ponto), `FieldEpi.php` (EPI necessário no Campo).
- Janela: `resources/views/filament/connections-modal.blade.php` (no fim de toda tela, `AppPanelProvider`) e `app/Http/Controllers/ConnectionsController.php` (rota `connections.dismiss`, `/<empresa>/sistemas-juntos/ok`).
- Página: `app/Filament/Pages/ConnectionsPage.php` + `resources/views/filament/pages/connections.blade.php` (`/<empresa>/empresa/sistemas-juntos`).
- Onde aparecem as funções: `resources/views/field/execute.blade.php` + `ExecuteOrderPage` (lembrete), `resources/views/timeclock/punch-page.blade.php` + `PunchPage` (vistoria), `FieldTechniciansWidget` + `resources/views/field/dashboard/technicians.blade.php` (aproveitamento e deslocamento; no celular a tabela vira cartões), `OrderResource` (custo na OS).
- Migração: `database/migrations/2026_11_02_100000_create_connection_dismissals_table.php`.
- Textos: `lang/*/connections.php`.
- Testes: `tests/Feature/ConnectionsTest.php`.
