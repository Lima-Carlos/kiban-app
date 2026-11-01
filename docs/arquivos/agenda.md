# Kiban Core Agenda (em inglês, Kiban Core Appointments)

Agenda online para empresas que atendem com hora marcada. **Primeiro ramo: pet shop** (banho e tosa, com leva e traz). O código do produto no Core é `agenda`, e o limite do plano é o número de **profissionais ativos** (3 no plano; preço provisório R$ 119/mês + R$ 20 por profissional extra, a confirmar pelo dono). Primeira versão: 01/11/2026.

Posicionamento (pesquisa `kiban-core/docs/planejamento/pesquisa-agenda.md`): "a agenda da empresa que tem equipe de verdade" — o cliente marca sozinho pelo link, o lembrete vai pelo WhatsApp sem taxa e a mesma empresa usa o Ponto (equipe CLT) e o Campo (leva e traz).

## Quem faz o quê (função na Agenda)
Escolhida na tela **Pessoas**:
- **Gestor da agenda:** tudo (serviços, profissionais, configurações, comissões). Dono e administradores são sempre gestores.
- **Recepção:** marca, remarca, cancela, confirma, lembra os clientes, cadastra clientes e vende pacotes.
- **Profissional:** quem tem login e está marcado como "Atende na Agenda" vê **só a própria agenda** e anda com os atendimentos (Chegou → Começar → Pet pronto → Entregue). Não precisa escolher nada: vem sozinho.
- **Só consulta:** vê.

Novo perfil sugerido: **"Recepção (Agenda)"** (recepção na Agenda e funcionário no Ponto). O perfil "Consulta" também vê a Agenda.

## Primeiros passos
1. **Ramo** (Agenda → Configurações): "Pet shop" traz prontos Banho, Banho e tosa completa, Tosa higiênica, Hidratação, Corte de unhas, Escovação de dentes e Leva e traz, com **tempo e preço por porte** (P, M, G, GG; preços sugeridos só em reais) e o "Pacote 4 banhos". Escolher de novo só acrescenta o que falta.
2. **Serviços:** conferir tempos e preços. "Adicional" só vai junto com um serviço principal; "Leva e traz" não ocupa tempo do profissional.
3. **Profissionais:** na tela Pessoas, marcar **"Atende na Agenda"** (com ou sem login). Depois, em Agenda → Profissionais → "Horários, serviços e comissão".

## Clientes e pets
- O cliente (tutor) é **o mesmo cadastro de clientes do Campo** (tabela `fd_customers`): uma empresa, uma lista de clientes, nunca duas cópias para manter iguais. Quem tem os dois sistemas vê o mesmo cliente nos dois. As regras do celular e do documento são as mesmas (`CustomerRules`). A classe `Client` é só uma "janela" da Agenda para esse cadastro.
- **Pets** (`ag_items`): nome, espécie, raça, porte, pelagem, nascimento, observações/alergias (aparecem em vermelho no cartão) e foto (arquivo particular da empresa). A tabela é genérica de propósito: na estética automotiva, o mesmo registro vira o carro.
- Pet com atendimentos não é apagado (desative). Cliente com histórico (agendamentos, pacotes, OS do Campo) também não.
- **Faltas:** contadas por cliente. A partir de N faltas (configurável, padrão 2) o cliente fica marcado (⚠ no cartão) e aparece no "Atenção" quando vem nos próximos dias.

## Profissionais e horários
- Horário: com o **Ponto**, quem é funcionário e tem horário de trabalho no Ponto atende nesse horário (feriados incluídos) — uma verdade só. Sem Ponto (ou com "Seguir o horário do Ponto" desligado), vale a semana própria da Agenda, com intervalo (mesmo formato dos horários do Ponto).
- **Folgas** (dia, férias, consulta médica): "Mais → Nova folga".
- **Serviços que faz** (vazio = todos) e **comissão**: padrão do profissional e, se quiser, uma por serviço.

## Agenda da recepção (Agenda → Agenda)
- **Dia** (uma coluna por profissional; no celular, uma embaixo da outra) ou **Semana**. Cartão com hora, pet, porte, tutor, serviços, alergias, ⚠ (falta muito), 🚐 (leva e traz) e "Pelo link".
- Botão do próximo passo no cartão: **Chegou → Começar → Pet pronto → Entregue**. Em "Pet pronto" aparece **"Avisar no WhatsApp"**, que abre o WhatsApp com a mensagem pronta para o tutor (sem custo; fica marcado como enviado).
- Com o Ponto, cada coluna mostra se o profissional **já bateu o ponto** hoje. Nunca bate o ponto por ninguém.
- **Novo agendamento:** cliente (busca por nome, celular ou nome do pet; dá para cadastrar ali mesmo), pet, serviços (preço do porte), profissional, dia e um **horário livre**. "Encaixar em outro horário" permite um horário fora dos livres: se bater com outro, o sistema avisa (não impede). Pacote é usado sozinho quando serve.
- Página do agendamento: os passos, **Marcar falta** (só depois do horário), **Remarcar**, **Cancelar** (com motivo), **Enviar confirmação no WhatsApp** e o histórico de tudo (`ag_appointment_events`). Entregue, falta e cancelado não mudam mais. Agendamentos nunca são apagados.

## Horários livres (como o sistema calcula)
`SlotFinder` + `WorkingHours`: horário do dia − intervalo − folgas − outros agendamentos (menos cancelados e faltas), de "X em X minutos" a partir do começo de cada trecho, com o tempo dos serviços pelo porte. O link só oferece esses horários, a partir de "agora + antecedência mínima" e até "N dias à frente".

## Link de agendamento (público)
- Endereço: `app.../<empresa>/agendar` (no PC: `http://localhost:8090/<empresa>/agendar`). Com o logo e a cor da empresa (`CompanyProfile`), nos 3 idiomas, feito para o celular.
- O cliente escolhe serviço, porte, adicionais, profissional (ou "qualquer um") e o dia; depois o horário, nome, celular e o pet. Leva e traz (se ligado nas configurações) pede o endereço, que vira um endereço do cliente.
- **Identificação pelo celular**, sem código no v1: o mesmo celular encontra o mesmo cliente (nada dele é mostrado de volta) e o pet pelo nome. Proteção leve, igual ao "Testar grátis" do Core: campo escondido para robôs, tempo mínimo na página, poucas tentativas por minuto e no máximo 3 agendamentos futuros pelo link por celular.
- Depois de agendar, o cliente recebe o **link dele** (`/agendar/<código>`): confirmar presença, **remarcar** (horários livres) ou **cancelar** — só até N horas antes (padrão 4). Depois disso, "fale com a gente" (WhatsApp da empresa, se o telefone estiver nos dados da empresa).
- A recepção recebe no **sino**: novo agendamento pelo link, confirmação, remarcação e cancelamento pelo cliente. Nada por e-mail.

## Lembretes (grátis)
Agenda → **Lembrar amanhã**: os agendamentos do dia seguinte, cada um com o botão do WhatsApp com a mensagem pronta e o link do cliente. O lembrete automático pela API oficial do WhatsApp fica para a v2 (custa por mensagem e precisa de conta na Meta, depois da ME).

## Pacotes
- Agenda → **Pacotes** (o que se vende: serviço, sessões, validade, preço) e **Pacotes vendidos** (vender para um cliente, de um pet ou de qualquer pet dele).
- O agendamento do serviço usa o pacote sozinho (o que vence primeiro). Sessão conta enquanto o agendamento segura o horário; cancelado ou falta devolve a sessão; pronto/entregue = usada. Regras em `PackageService`.
- Pacote com sessões perto de vencer (7 dias) aparece no "Atenção".

## Comissões
Agenda → **Comissões** (gestor): por mês, por profissional, os atendimentos prontos ou entregues, o valor dos serviços e a comissão (a % de cada serviço guardada no agendamento, então mudar a % depois não muda o passado). **Baixar planilha** (Excel, com resumo e uma linha por serviço). A planilha usa o `App\Support\Spreadsheet`, o mesmo escritor de planilhas da Frota.

## Início
- Recepção/gestor/consulta: quadro **"Agenda de hoje"** (a chegar, chegaram, prontos, entregues, faltas).
- Profissional: "Meus atendimentos de hoje".
- "Atenção": pacotes vencendo e clientes que faltam muito chegando.

## Ligações com os outros sistemas (veja `conexoes.md`)
- **Agenda + Campo** (`AgendaField`, conexão `agenda_pickup`): no agendamento com leva e traz, "Mais → Criar ordem de leva e traz no Campo" abre uma OS no Campo, no endereço do cliente (mesmo cliente nos dois sistemas), com o pet e a janela de horário. A OS fica em "A agendar" na agenda do Campo. O tipo de serviço da OS é escolhido nas configurações da Agenda.
- **Agenda + Ponto** (`AgendaTimeClock` + `WorkingHours`, conexão `agenda_clock`): horários do Ponto e quem já bateu o ponto hoje.
- Com a **Frota**: nada a mais por enquanto.

## Dados de exemplo
`docker exec kibanapp-php php artisan kiban:agenda-demo <empresa>` enche uma empresa de teste (que tenha a Agenda) com tutores e pets inventados, dois profissionais, agendamentos de hoje e dos próximos dias em todas as situações, um pacote e um cliente que falta muito. Só acrescenta; usar só em empresa de teste.

## Arquivos
- Módulo: `app/Modules/Agenda/` (`AgendaModule`, `Enums/`, `Models/`, `Policies/`, `Services/`, `Filament/`).
- Regras: `AppointmentBook` (toda mudança de agendamento), `SlotFinder` e `WorkingHours` (horários livres), `OnlineBooking` (link público), `PackageService`, `AgendaReports` (hoje, "Atenção", comissões), `AgendaNotices` (sino), `AgendaMessages` (WhatsApp), `AgendaTemplates` (pet shop), `AgendaPeople` (seção na tela Pessoas).
- Página pública: `app/Http/Controllers/Agenda/BookingController.php`, telas `resources/views/agenda/booking.blade.php` e `manage.blade.php`.
- Pontes: `app/Connections/Bridges/AgendaField.php` e `AgendaTimeClock.php`.
- Banco: `database/migrations/2026_11_05_100000_create_agenda_tables.php` (tabelas `ag_*`).
- Dados de exemplo: `app/Console/Commands/AgendaDemoData.php`.
- Textos: `lang/*/agenda.php`. Testes: `tests/Feature/AgendaTest.php`.

## Fica para a v2
Lembrete automático pelo WhatsApp oficial (cota + pacote), sinal por Pix (Asaas), Google Agenda, lista de espera, avaliação do cliente, várias unidades, clube de assinatura, código por SMS/WhatsApp no link, ficha de saúde/vacinas do pet, e o segundo ramo (estética automotiva).
